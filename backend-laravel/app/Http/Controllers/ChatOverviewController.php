<?php

namespace App\Http\Controllers;

use App\Console\Commands\SubscribeEventSub;
use App\Console\Commands\TrackChatters;
use App\Models\ChannelEvent;
use App\Models\ChatterStreamStat;
use App\Models\EventLog;
use App\Models\StreamSession;
use App\Services\Chat\ChatTracker;
use App\Services\Chat\IgnoredChatters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Painel do chat: quem está online, há quanto tempo, quantas mensagens e os resgates da loja.
 * Mostra a live atual ou, se estiver offline, a última live registrada.
 */
class ChatOverviewController extends Controller
{
    private const MAX_CHATTERS = 300;

    public function __invoke(Request $request, IgnoredChatters $ignored): JsonResponse
    {
        $session = StreamSession::current() ?? StreamSession::latest('started_at')->first();
        $live = $session !== null && $session->ended_at === null;
        $onlineSince = now()->subSeconds(ChatTracker::tolerance());

        // Bots e a conta do canal ficam fora (inclusive registros antigos, de antes do filtro).
        $stats = $session
            ? ChatterStreamStat::with('chatter')
                ->withCount('segments')
                ->where('stream_session_id', $session->id)
                ->whereHas('chatter', fn ($q) => $q->whereNotIn('login', $ignored->logins()))
                ->get()
            : collect();

        // Interações de cada pessoa nesta live (resgates, bits, gifts, sub).
        $sessionEvents = $session
            ? ChannelEvent::where('stream_session_id', $session->id)->get()
            : collect();
        $eventsByChatter = $sessionEvents->whereNotNull('chatter_id')->groupBy('chatter_id');

        $chatters = $stats
            ->map(function (ChatterStreamStat $s) use ($live, $onlineSince, $eventsByChatter) {
                $events = $eventsByChatter->get($s->chatter_id, collect());
                $sub = $events->whereIn('type', [ChannelEvent::TYPE_SUB, ChannelEvent::TYPE_RESUB])->sortByDesc('occurred_at')->first();

                return [
                    'id' => $s->chatter->twitch_user_id,
                    'login' => $s->chatter->login,
                    'display_name' => $s->chatter->display_name,
                    'avatar' => $s->chatter->profile_image_url,
                    'level' => (int) $s->chatter->level,
                    'xp' => (int) $s->chatter->xp,
                    'online' => $live && $s->last_seen_at !== null && $s->last_seen_at->greaterThanOrEqualTo($onlineSince),
                    'watch_seconds' => $s->watch_seconds,
                    'message_count' => $s->message_count,
                    'first_seen_at' => $s->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                    // Quantos períodos no chat nesta live (2 = saiu e voltou uma vez).
                    'periods' => (int) $s->segments_count,
                    'last_message_at' => $s->last_message_at?->toIso8601String(),
                    'redemptions' => $events->where('type', ChannelEvent::TYPE_REDEMPTION)->count(),
                    'bits' => (int) $events->where('type', ChannelEvent::TYPE_BITS)->sum('amount'),
                    'gifts' => (int) $events->where('type', ChannelEvent::TYPE_GIFT)->sum('amount'),
                    'sub' => $sub ? ['tier' => $sub->tier, 'is_prime' => $sub->is_prime, 'is_gift' => $sub->is_gift] : null,
                ];
            })
            ->sortBy([
                ['online', 'desc'],
                ['watch_seconds', 'desc'],
                ['message_count', 'desc'],
            ])
            ->values();

        $events = ChannelEvent::with('chatter')
            ->latest('occurred_at')
            ->limit(40)
            ->get()
            ->map->toFeedItem();

        return response()->json([
            'live' => $live,
            'session' => $session ? [
                'id' => $session->id,
                'title' => $session->title,
                'game_name' => $session->game_name,
                'started_at' => $session->started_at->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
            ] : null,
            'totals' => [
                'online' => $chatters->where('online', true)->count(),
                'chatters' => $chatters->count(),
                'messages' => (int) $stats->sum('message_count'),
                'redemptions' => $sessionEvents->where('type', ChannelEvent::TYPE_REDEMPTION)->count(),
                'subs' => $sessionEvents->whereIn('type', [ChannelEvent::TYPE_SUB, ChannelEvent::TYPE_RESUB])->count(),
                'gifts' => (int) $sessionEvents->where('type', ChannelEvent::TYPE_GIFT)->sum('amount'),
                'bits' => (int) $sessionEvents->where('type', ChannelEvent::TYPE_BITS)->sum('amount'),
            ],
            'chatters' => $chatters->take(self::MAX_CHATTERS),
            'events' => $events,
            'health' => $this->health(),
            // O front usa isto para o tempo de quem está online subir segundo a segundo entre as checagens.
            'presence_gap_seconds' => ChatTracker::tolerance(),
            'presence_interval_seconds' => ChatTracker::interval(),
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function health(): array
    {
        $status = Cache::get(SubscribeEventSub::STATUS_KEY);

        return [
            // Inscrições do webhook ativas e conferidas há pouco (o agendador confere a cada 5 min).
            'webhook' => is_array($status)
                && ($status['problems'] ?? ['x']) === []
                && $this->isRecent($status['checked_at'] ?? null, 15 * 60),
            // Fila andando: nenhum evento recebido esperando há mais de 2 minutos.
            'queue' => ! EventLog::whereNull('processed_at')
                ->whereNull('error')
                ->where('received_at', '<', now()->subMinutes(2))
                ->exists(),
            'tracker' => $this->isRecent(Cache::get(TrackChatters::HEARTBEAT_KEY), 180),
        ];
    }

    private function isRecent(?string $timestamp, int $seconds): bool
    {
        return $timestamp !== null && Carbon::parse($timestamp)->greaterThan(now()->subSeconds($seconds));
    }
}
