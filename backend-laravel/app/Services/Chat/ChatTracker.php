<?php

namespace App\Services\Chat;

use App\Models\Chatter;
use App\Models\ChatterStreamStat;
use App\Models\PresenceSegment;
use App\Models\StreamSession;
use App\Services\Economy\RewardService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Regras de presença e contagem do chat, usadas pela checagem periódica e pelos eventos do EventSub.
 *
 * Presença: a Twitch não informa quando alguém entrou ou saiu. A cada checagem (a cada
 * CHAT_PRESENCE_INTERVAL segundos, padrão 15), quem está na lista ganha o tempo exato desde a última vez
 * em que foi visto. Mensagens no chat também contam como "visto agora".
 *
 * Saiu e voltou: se a pessoa ficou mais que tolerance() sem aparecer, o tempo fora não conta. O período
 * anterior é fechado (presence_segments) e um novo começa quando ela volta; o tempo continua do total
 * que ela já tinha.
 */
class ChatTracker
{
    /** Intervalos aceitos pelo agendador do Laravel (segundos). */
    public const ALLOWED_INTERVALS = [10, 15, 20, 30, 60];

    public function __construct(
        private readonly IgnoredChatters $ignored,
        private readonly RewardService $rewards,
    ) {}

    public static function interval(): int
    {
        $value = (int) config('services.twitch.presence_interval', 15);

        return in_array($value, self::ALLOWED_INTERVALS, true) ? $value : 15;
    }

    /**
     * Tempo máximo sem aparecer que ainda conta como presença contínua: duas checagens mais 5s de folga
     * (35s com checagens de 15s). Perder uma checagem por oscilação da lista não para o tempo; sumir
     * por duas ou mais conta como saída.
     */
    public static function tolerance(): int
    {
        return self::interval() * 2 + 5;
    }

    /**
     * Abre (ou reaproveita) a sessão da live atual e fecha qualquer outra que tenha ficado aberta.
     */
    public function openSession(string $streamId, CarbonInterface $startedAt, ?string $title = null, ?string $game = null): StreamSession
    {
        $stale = StreamSession::whereNull('ended_at')->where('twitch_stream_id', '!=', $streamId)->pluck('id');

        if ($stale->isNotEmpty()) {
            StreamSession::whereIn('id', $stale)->update(['ended_at' => now()]);
            $this->closeSegments($stale->all());
        }

        $session = StreamSession::firstOrCreate(
            ['twitch_stream_id' => $streamId],
            ['started_at' => $startedAt],
        );

        $session->fill(array_filter(['title' => $title, 'game_name' => $game], fn ($v) => $v !== null));

        if ($session->ended_at !== null) {
            // A Twitch voltou a dizer que esse stream está no ar (queda rápida de conexão).
            $session->ended_at = null;
        }

        $session->save();

        return $session;
    }

    public function closeOpenSessions(): void
    {
        $open = StreamSession::whereNull('ended_at')->pluck('id');

        if ($open->isEmpty()) {
            return;
        }

        StreamSession::whereIn('id', $open)->update(['ended_at' => now()]);
        $this->closeSegments($open->all());
    }

    /**
     * @param  array<string, mixed>|null  $stream  resposta de /helix/streams, ou null se offline
     */
    public function syncStream(?array $stream): ?StreamSession
    {
        if ($stream === null) {
            $this->closeOpenSessions();

            return null;
        }

        return $this->openSession(
            (string) $stream['id'],
            Carbon::parse($stream['started_at']),
            $stream['title'] ?? null,
            $stream['game_name'] ?? null,
        );
    }

    /**
     * Uma checagem da lista de quem está no chat.
     *
     * @param  array<int, array{user_id: string, user_login: string, user_name: string}>  $chatters
     */
    public function recordPresence(StreamSession $session, array $chatters, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        // Bots e a conta do canal não entram na presença.
        $chatters = array_values(array_filter(
            $chatters,
            fn ($c) => ! $this->ignored->isIgnored((string) $c['user_login']),
        ));

        if ($chatters !== []) {
            $rows = array_map(fn ($c) => [
                'twitch_user_id' => (string) $c['user_id'],
                'login' => $c['user_login'],
                'display_name' => $c['user_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chatters);

            foreach (array_chunk($rows, 500) as $chunk) {
                Chatter::upsert($chunk, ['twitch_user_id'], ['login', 'display_name', 'updated_at']);
            }

            $chatterIds = Chatter::whereIn('twitch_user_id', array_column($rows, 'twitch_user_id'))->pluck('id');
            $stats = ChatterStreamStat::where('stream_session_id', $session->id)
                ->whereIn('chatter_id', $chatterIds)
                ->get()
                ->keyBy('chatter_id');
            $openSegments = PresenceSegment::whereIn('chatter_stream_stat_id', $stats->pluck('id'))
                ->whereNull('ended_at')
                ->get()
                ->keyBy('chatter_stream_stat_id');

            foreach ($chatterIds as $chatterId) {
                $stat = $stats->get($chatterId) ?? new ChatterStreamStat([
                    'stream_session_id' => $session->id,
                    'chatter_id' => $chatterId,
                ]);

                $this->seen($stat, $now, $stat->exists ? $openSegments->get($stat->id) : null);
            }
        }

        // Quem não aparece há mais que a tolerância saiu: fecha o período na última vez em que foi visto.
        $this->closeSegments([$session->id], $now->copy()->subSeconds(self::tolerance()));
    }

    /**
     * Uma mensagem no chat. Só conta durante a live; fora dela é ignorada.
     */
    public function recordMessage(string $userId, string $login, string $name, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $session = StreamSession::current();

        if (! $session || $this->ignored->isIgnored($login)) {
            return;
        }

        $chatter = Chatter::remember($userId, $login, $name);
        $stat = ChatterStreamStat::firstOrNew([
            'stream_session_id' => $session->id,
            'chatter_id' => $chatter->id,
        ]);

        $stat->message_count = ($stat->message_count ?? 0) + 1;
        $stat->last_message_at = $at;

        $open = $stat->exists
            ? PresenceSegment::where('chatter_stream_stat_id', $stat->id)->whereNull('ended_at')->first()
            : null;

        $this->seen($stat, $at, $open);
        $this->rewards->onMessage($chatter, $session->id);
    }

    /**
     * Marca a pessoa como vista em $now: soma o tempo se ela continuou no chat, ou fecha o período
     * anterior e abre um novo se ela tinha saído.
     */
    private function seen(ChatterStreamStat $stat, CarbonInterface $now, ?PresenceSegment $open): void
    {
        $last = $stat->last_seen_at;

        // Evento atrasado (ex.: mensagem com horário anterior à última checagem): só salva o resto.
        if ($last !== null && ! $now->greaterThan($last)) {
            $stat->save();

            return;
        }

        $continued = $last !== null && $last->diffInSeconds($now, true) <= self::tolerance();

        if ($continued) {
            $stat->watch_seconds = ($stat->watch_seconds ?? 0) + (int) $last->diffInSeconds($now, true);
        }

        $stat->first_seen_at ??= $now;
        $stat->last_seen_at = $now;
        $stat->save();

        if ($continued && $open) {
            return;
        }

        if ($open) {
            // Tinha saído: o período anterior termina na última vez em que foi visto.
            $open->forceFill(['ended_at' => $last])->save();
        }

        PresenceSegment::create([
            'chatter_stream_stat_id' => $stat->id,
            // Continuou mas não havia período aberto (dados antigos): começa onde foi visto antes.
            'started_at' => $continued ? $last : $now,
        ]);
    }

    /**
     * Fecha os períodos abertos nas lives informadas, no last_seen_at de cada pessoa.
     * Com $seenBefore, fecha só de quem não é visto desde antes desse horário.
     *
     * @param  array<int, int>  $sessionIds
     */
    private function closeSegments(array $sessionIds, ?CarbonInterface $seenBefore = null): void
    {
        PresenceSegment::query()
            ->whereNull('ended_at')
            ->whereHas('stat', function ($q) use ($sessionIds, $seenBefore) {
                $q->whereIn('stream_session_id', $sessionIds);

                if ($seenBefore) {
                    $q->where('last_seen_at', '<', $seenBefore);
                }
            })
            ->with('stat')
            ->get()
            ->each(fn (PresenceSegment $segment) => $segment->forceFill([
                'ended_at' => $segment->stat->last_seen_at ?? $segment->started_at,
            ])->save());
    }
}
