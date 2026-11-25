<?php

namespace App\Http\Controllers;

use App\Models\ChannelEvent;
use App\Models\Chatter;
use App\Models\ChatterStreamStat;
use App\Models\PresenceSegment;
use App\Models\Transaction;
use App\Services\Chat\IgnoredChatters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tudo separado por pessoa do chat: ranking geral e a ficha individual.
 */
class CommunityController extends Controller
{
    private const SORTS = [
        'xp' => 'xp',
        'coins' => 'coins',
        'watch' => 'watch_seconds',
        'messages' => 'message_count',
        'bits' => 'bits_total',
        'gifts' => 'gifts_given',
        'subs' => 'subs_count',
        'redemptions' => 'redemptions_count',
        'recent' => 'last_seen_at',
    ];

    public function index(Request $request, IgnoredChatters $ignored): JsonResponse
    {
        $data = $request->validate([
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'q' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $sort = self::SORTS[$data['sort'] ?? 'xp'];
        $search = trim((string) ($data['q'] ?? ''));

        $page = Chatter::query()
            ->withTotals()
            // Bots e a conta do canal não entram no ranking.
            ->whereNotIn('login', $ignored->logins())
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('login', 'like', '%'.mb_strtolower($search).'%')
                ->orWhere('display_name', 'like', "%{$search}%")))
            ->orderByDesc($sort)
            ->orderBy('display_name')
            ->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Chatter $c) => [
                'id' => $c->twitch_user_id,
                'login' => $c->login,
                'display_name' => $c->display_name,
                'avatar' => $c->profile_image_url,
                ...$c->totalsArray(),
                ...$c->economyArray(),
            ]),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $twitchUserId): JsonResponse
    {
        $chatter = Chatter::withTotals()->where('twitch_user_id', $twitchUserId)->firstOrFail();

        $lastSub = $chatter->events()
            ->whereIn('type', [ChannelEvent::TYPE_SUB, ChannelEvent::TYPE_RESUB, ChannelEvent::TYPE_UPGRADE])
            ->latest('occurred_at')
            ->first();

        // Interações agrupadas por live para juntar com a presença.
        $eventsByLive = $chatter->events()
            ->selectRaw('stream_session_id, type, count(*) as total, sum(coalesce(amount, 0)) as amount_sum')
            ->whereNotNull('stream_session_id')
            ->groupBy('stream_session_id', 'type')
            ->get()
            ->groupBy('stream_session_id');

        $lives = ChatterStreamStat::with(['session', 'segments'])
            ->where('chatter_id', $chatter->id)
            ->get()
            ->sortByDesc(fn ($s) => $s->session->started_at)
            ->values()
            ->map(function (ChatterStreamStat $s) use ($eventsByLive) {
                $events = $eventsByLive->get($s->stream_session_id, collect())->keyBy('type');

                // Entradas e saídas. Período aberto = ainda no chat; o fim provisório é a última vez vista.
                $periods = $s->segments->map(function (PresenceSegment $p) use ($s) {
                    $end = $p->ended_at ?? $s->last_seen_at ?? $p->started_at;

                    return [
                        'started_at' => $p->started_at->toIso8601String(),
                        'ended_at' => $p->ended_at?->toIso8601String(),
                        'last_seen_at' => $end->toIso8601String(),
                        'seconds' => (int) $p->started_at->diffInSeconds($end, true),
                        'open' => $p->ended_at === null && $s->session->ended_at === null,
                    ];
                })->values();

                return [
                    'periods' => $periods,
                    'session_id' => $s->stream_session_id,
                    'started_at' => $s->session->started_at->toIso8601String(),
                    'title' => $s->session->title,
                    'game_name' => $s->session->game_name,
                    'watch_seconds' => $s->watch_seconds,
                    'message_count' => $s->message_count,
                    'redemptions' => (int) ($events->get(ChannelEvent::TYPE_REDEMPTION)?->total ?? 0),
                    'bits' => (int) ($events->get(ChannelEvent::TYPE_BITS)?->amount_sum ?? 0),
                    'gifts' => (int) ($events->get(ChannelEvent::TYPE_GIFT)?->amount_sum ?? 0),
                    'subscribed' => $events->has(ChannelEvent::TYPE_SUB) || $events->has(ChannelEvent::TYPE_RESUB),
                ];
            });

        $timeline = $chatter->events()
            ->with('chatter')
            ->latest('occurred_at')
            ->limit(200)
            ->get()
            ->map->toFeedItem();

        // Livro-caixa resumido: as últimas movimentações de XP e moedas.
        $ledger = $chatter->transactions()
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'coins' => $t->coins_delta,
                'xp' => $t->xp_delta,
                'meta' => $t->meta,
                'at' => $t->created_at->toIso8601String(),
            ]);

        return response()->json([
            'id' => $chatter->twitch_user_id,
            'login' => $chatter->login,
            'display_name' => $chatter->display_name,
            'avatar' => $chatter->profile_image_url,
            'first_seen_at' => $chatter->created_at->toIso8601String(),
            'economy' => $chatter->economyArray(),
            'ledger' => $ledger,
            'totals' => $chatter->totalsArray(),
            'last_sub' => $lastSub?->toFeedItem(),
            'lives' => $lives,
            'timeline' => $timeline,
        ]);
    }
}
