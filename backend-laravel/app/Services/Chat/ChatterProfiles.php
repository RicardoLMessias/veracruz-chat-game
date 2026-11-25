<?php

namespace App\Services\Chat;

use App\Models\Chatter;
use App\Services\Twitch\TwitchClient;

/**
 * Busca a foto de perfil (pública) de quem ainda não tem, ou cuja foto tem mais de 7 dias.
 * Até 100 pessoas por chamada; roda junto com a checagem de presença.
 */
class ChatterProfiles
{
    public function __construct(private readonly TwitchClient $twitch) {}

    public function refresh(string $accessToken, int $limit = 100): int
    {
        $pending = Chatter::query()
            ->where(fn ($q) => $q->whereNull('profile_fetched_at')
                ->orWhere('profile_fetched_at', '<', now()->subDays(7)))
            ->orderByRaw('profile_fetched_at is not null')
            ->limit($limit)
            ->pluck('twitch_user_id')
            ->all();

        if ($pending === []) {
            return 0;
        }

        $profiles = collect($this->twitch->users($accessToken, $pending))->keyBy('id');

        foreach ($pending as $id) {
            $profile = $profiles->get($id);

            Chatter::where('twitch_user_id', $id)->update(array_filter([
                'profile_image_url' => $profile['profile_image_url'] ?? null,
                'display_name' => $profile['display_name'] ?? null,
                'login' => $profile['login'] ?? null,
            ]) + ['profile_fetched_at' => now()]);
        }

        return count($pending);
    }
}
