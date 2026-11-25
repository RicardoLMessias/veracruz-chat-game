<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\TwitchAccount;
use App\Services\Twitch\EventSubHandler;
use App\Services\Twitch\TwitchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cria e sincroniza as inscrições do EventSub por webhook.
 *
 *   php artisan twitch:subscribe --url=https://xxxx.trycloudflare.com   (salva o endereço e sincroniza)
 *   php artisan twitch:subscribe                                       (usa o endereço salvo)
 *   php artisan twitch:subscribe --status                              (só confere, não muda nada)
 */
class SubscribeEventSub extends Command
{
    public const CALLBACK_SETTING = 'twitch.eventsub_base_url';

    public const STATUS_KEY = 'twitch:eventsub:status';

    protected $signature = 'twitch:subscribe {--url= : Endereço público (túnel ou domínio), sem /twitch/eventsub} {--status : Só mostra a situação}';

    protected $description = 'Cria/sincroniza as inscrições do EventSub por webhook';

    public function handle(TwitchClient $twitch): int
    {
        $account = TwitchAccount::latest('updated_at')->first();

        if (! $account) {
            $this->error('Nenhum canal conectado. Faça login no dashboard primeiro.');

            return self::FAILURE;
        }

        if ($url = $this->option('url')) {
            AppSetting::setValue(self::CALLBACK_SETTING, rtrim((string) $url, '/'));
        }

        $base = AppSetting::getValue(self::CALLBACK_SETTING);
        $secret = (string) config('services.twitch.eventsub_secret');

        if (! $base || ! str_starts_with($base, 'https://')) {
            $this->error('Informe o endereço público HTTPS: php artisan twitch:subscribe --url=https://...');

            return self::FAILURE;
        }

        if (strlen($secret) < 10 || strlen($secret) > 100) {
            $this->error('TWITCH_EVENTSUB_SECRET no .env precisa ter de 10 a 100 caracteres.');

            return self::FAILURE;
        }

        $callback = $base.'/twitch/eventsub';

        try {
            $existing = collect($twitch->listSubscriptions())
                ->filter(fn ($s) => ($s['transport']['method'] ?? null) === 'webhook');

            if ($this->option('status')) {
                return $this->report($existing, $callback);
            }

            // Remove o que aponta para outro endereço (túnel antigo) ou falhou.
            foreach ($existing as $sub) {
                $stale = ($sub['transport']['callback'] ?? '') !== $callback;
                $broken = ! in_array($sub['status'] ?? '', ['enabled', 'webhook_callback_verification_pending'], true);

                if ($stale || $broken) {
                    $twitch->deleteSubscription((string) $sub['id']);
                    $this->line("<fg=gray>removida</> {$sub['type']} ({$sub['status']})");
                }
            }

            $active = $existing
                ->filter(fn ($s) => ($s['transport']['callback'] ?? '') === $callback
                    && in_array($s['status'] ?? '', ['enabled', 'webhook_callback_verification_pending'], true))
                ->map(fn ($s) => $s['type'])
                ->all();

            $problems = [];

            foreach (EventSubHandler::subscriptions($account->twitch_id) as $sub) {
                if (in_array($sub['type'], $active, true)) {
                    $this->info("ok        {$sub['type']}");

                    continue;
                }

                $needed = $sub['webhook_scopes'] ?? array_filter([$sub['scope']]);
                $missing = array_values(array_filter($needed, fn ($s) => ! $account->hasScope($s)));

                if ($missing !== []) {
                    $problems[] = $sub['type'];
                    $this->warn("pulada    {$sub['type']}: falta autorizar ".implode(', ', $missing).' no dashboard');

                    continue;
                }

                // Uma falha numa inscrição não impede as outras.
                try {
                    $result = $twitch->subscribeWebhook($sub['type'], $sub['version'], $sub['condition'], $callback, $secret);
                } catch (Throwable $e) {
                    $problems[] = $sub['type'];
                    $this->error("erro      {$sub['type']}: {$e->getMessage()}");

                    continue;
                }

                if (in_array($result['status'], [202, 409], true)) {
                    $this->info("criada    {$sub['type']}");
                } else {
                    $problems[] = $sub['type'];
                    $this->warn("recusada  {$sub['type']} (HTTP {$result['status']}): {$result['message']}");
                }
            }

            $this->cacheStatus($callback, $problems);
            $this->line("Webhook: {$callback}");
        } catch (Throwable $e) {
            report($e);
            $this->error('Não foi possível falar com a Twitch: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $existing
     */
    private function report($existing, string $callback): int
    {
        $this->table(['Tipo', 'Status', 'Endereço'], $existing->map(fn ($s) => [
            $s['type'],
            $s['status'],
            ($s['transport']['callback'] ?? '') === $callback ? 'atual' : 'outro',
        ])->all());

        $problems = $existing
            ->filter(fn ($s) => ($s['status'] ?? '') !== 'enabled' || ($s['transport']['callback'] ?? '') !== $callback)
            ->pluck('type')
            ->all();

        $this->cacheStatus($callback, $problems, $existing->where('status', 'enabled')->count());

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $problems
     */
    private function cacheStatus(string $callback, array $problems, ?int $enabled = null): void
    {
        Cache::forever(self::STATUS_KEY, [
            'callback' => $callback,
            'problems' => array_values(array_unique($problems)),
            'enabled' => $enabled,
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
