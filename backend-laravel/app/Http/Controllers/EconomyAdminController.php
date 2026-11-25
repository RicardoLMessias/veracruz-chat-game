<?php

namespace App\Http\Controllers;

use App\Models\Chatter;
use App\Models\Transaction;
use App\Services\Chat\IgnoredChatters;
use App\Services\Economy\EconomyService;
use App\Services\Economy\InsufficientFundsException;
use App\Services\Economy\SettingsDefaults;
use App\Services\Economy\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Gerenciar economia": resumo, configurações, ajuste manual e zerar.
 * Tudo passa pelo livro-caixa: zerar lança o valor negativo (tipo "zerado") em vez de apagar histórico.
 */
class EconomyAdminController extends Controller
{
    public function __construct(
        private readonly EconomyService $economy,
        private readonly SettingsService $settings,
    ) {}

    public function summary(IgnoredChatters $ignored): JsonResponse
    {
        $people = Chatter::whereNotIn('login', $ignored->logins());

        return response()->json([
            'xp_total' => (int) (clone $people)->sum('xp'),
            'coins_total' => (int) (clone $people)->sum('coins'),
            'with_xp' => (clone $people)->where('xp', '>', 0)->count(),
            'with_coins' => (clone $people)->where('coins', '>', 0)->count(),
            'with_streak' => (clone $people)->where('current_streak', '>', 0)->count(),
            'subs' => (clone $people)->where('sub_tier', '>', 0)->count(),
            'transactions' => Transaction::count(),
            'currency_name' => $this->settings->get('currency_name'),
        ]);
    }

    public function settings(): JsonResponse
    {
        $current = $this->settings->all();

        return response()->json(collect(SettingsDefaults::all())->map(fn ($default, $key) => [
            'key' => $key,
            'value' => $current[$key] ?? $default[0],
            'default' => $default[0],
            'description' => $default[1],
        ])->values());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $known = SettingsDefaults::all();
        $errors = [];

        foreach ($data['values'] as $key => $value) {
            $value = trim((string) $value);

            if (! array_key_exists($key, $known)) {
                $errors["values.$key"] = 'Configuração desconhecida.';
            } elseif ($key === 'level_titles') {
                $titles = json_decode($value, true);
                $valid = is_array($titles) && $titles !== [] && collect($titles)->every(
                    fn ($t) => is_array($t) && count($t) === 2 && is_numeric($t[0]) && is_string($t[1]),
                );

                if (! $valid) {
                    $errors["values.$key"] = 'Use o formato [[1,"Andarilho"],[5,"Sobrevivente"]].';
                }
            } elseif ($key !== 'currency_name' && (! is_numeric($value) || (float) $value < 0)) {
                $errors["values.$key"] = 'Precisa ser um número maior ou igual a zero.';
            } elseif ($key === 'currency_name' && $value === '') {
                $errors["values.$key"] = 'Informe um nome.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($data['values'] as $key => $value) {
            $this->settings->set($key, trim((string) $value));
        }

        return $this->settings();
    }

    /**
     * Dá ou tira XP e moedas de uma pessoa (com motivo).
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string'],
            'xp' => ['nullable', 'integer', 'between:-100000000,100000000'],
            'coins' => ['nullable', 'integer', 'between:-100000000,100000000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $chatter = Chatter::where('twitch_user_id', $data['user_id'])->firstOrFail();
        $xp = (int) ($data['xp'] ?? 0);
        $coins = (int) ($data['coins'] ?? 0);

        if ($xp === 0 && $coins === 0) {
            throw ValidationException::withMessages(['xp' => 'Informe XP ou moedas diferentes de zero.']);
        }

        try {
            $result = $this->economy->grant($chatter->id, Transaction::MANUAL, $coins, $xp, array_filter([
                'reason' => $data['reason'] ?? null,
                'by' => $request->user()->name,
            ]));
        } catch (InsufficientFundsException) {
            throw ValidationException::withMessages([
                'coins' => "{$chatter->display_name} tem só {$chatter->coins} moedas; não dá para tirar ".abs($coins).'.',
            ]);
        }

        return response()->json(['ok' => true, ...$result]);
    }

    /**
     * Zera XP/nível, moedas e/ou sequências de uma pessoa ou de todo mundo.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:all,user'],
            'user_id' => ['required_if:scope,user', 'nullable', 'string'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['in:xp,coins,streak'],
            'confirm' => ['required', 'in:ZERAR'],
        ], [
            'confirm.in' => 'Digite ZERAR para confirmar.',
            'confirm.required' => 'Digite ZERAR para confirmar.',
            'targets.required' => 'Escolha o que zerar.',
        ]);

        $targets = array_unique($data['targets']);
        $query = Chatter::query();

        if ($data['scope'] === 'user') {
            $query->where('twitch_user_id', $data['user_id']);

            if (! (clone $query)->exists()) {
                abort(404);
            }
        }

        $affected = 0;
        $by = $request->user()->name;

        $query->where(fn ($q) => $q
            ->when(in_array('xp', $targets, true), fn ($w) => $w->orWhere('xp', '>', 0))
            ->when(in_array('coins', $targets, true), fn ($w) => $w->orWhere('coins', '!=', 0))
            ->when(in_array('streak', $targets, true), fn ($w) => $w
                ->orWhere('current_streak', '>', 0)
                ->orWhere('best_streak', '>', 0)))
            ->orderBy('id')
            ->chunkById(200, function ($chatters) use ($targets, $by, &$affected) {
                foreach ($chatters as $chatter) {
                    DB::transaction(function () use ($chatter, $targets, $by) {
                        $xp = in_array('xp', $targets, true) ? -$chatter->xp : 0;
                        $coins = in_array('coins', $targets, true) ? -$chatter->coins : 0;

                        if ($xp !== 0 || $coins !== 0) {
                            $this->economy->grant($chatter->id, Transaction::RESET, $coins, $xp, [
                                'targets' => array_values($targets),
                                'by' => $by,
                            ], null, true);
                        }

                        if (in_array('streak', $targets, true)) {
                            Chatter::whereKey($chatter->id)->update([
                                'current_streak' => 0,
                                'best_streak' => 0,
                                'last_counted_session_id' => null,
                            ]);
                        }
                    });

                    $affected++;
                }
            });

        return response()->json(['ok' => true, 'affected' => $affected]);
    }
}
