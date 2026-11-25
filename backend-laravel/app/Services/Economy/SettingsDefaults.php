<?php

namespace App\Services\Economy;

/**
 * Valores iniciais da economia (docs/economia-e-overlay.md). Ficam na tabela `settings` e podem ser
 * ajustados direto no banco; o código nunca usa números fixos.
 */
class SettingsDefaults
{
    /**
     * @return array<string, array{0: string, 1: string}> chave => [valor, descrição]
     */
    public static function all(): array
    {
        return [
            'currency_name' => ['moedas', 'Nome da moeda'],

            // Presença (só XP)
            'presence_xp_per_min' => ['2', 'XP por minuto no chat durante a live'],
            'active_window_min' => ['10', 'Minutos desde a última mensagem para contar como ativo'],
            'active_multiplier' => ['1.5', 'Multiplicador do XP de presença para quem está ativo no chat'],

            // Mensagens, comandos e resgates (só XP, com limite por minuto)
            'message_xp' => ['5', 'XP por mensagem'],
            'message_xp_per_minute' => ['3', 'Máximo de mensagens por minuto que dão XP'],
            'command_xp' => ['3', 'XP por comando do jogo'],
            'redemption_xp' => ['10', 'XP por resgate de pontos do canal'],
            'redemption_xp_per_minute' => ['3', 'Máximo de resgates por minuto que dão XP'],

            // Subs, presentes e bits
            'sub_coins' => ['300', 'Moedas por sub nova, resub ou sub recebida de presente'],
            'sub_xp' => ['500', 'XP por sub nova, resub ou sub recebida de presente'],
            'gift_coins_per_sub' => ['200', 'Moedas por sub dada de presente'],
            'gift_xp_per_sub' => ['300', 'XP por sub dada de presente'],
            'bits_coins_per_100' => ['15', 'Moedas a cada 100 bits'],
            'bits_xp_per_100' => ['30', 'XP a cada 100 bits'],

            // Multiplicadores de sub (valem para tudo que a pessoa ganha)
            'sub_mult_t1' => ['1.25', 'Multiplicador Tier 1 e Prime'],
            'sub_mult_t2' => ['1.5', 'Multiplicador Tier 2'],
            'sub_mult_t3' => ['2', 'Multiplicador Tier 3'],

            // Sequência de lives (só no XP de presença)
            'streak_min_minutes' => ['15', 'Minutos numa live para ela contar na sequência'],
            'streak_bonus_per_live' => ['0.10', 'Bônus de XP de presença por live seguida (a partir da 2ª)'],
            'streak_bonus_max' => ['0.50', 'Bônus máximo da sequência'],

            // Níveis
            'level_base' => ['100', 'XP base para subir de nível'],
            'level_exponent' => ['1.5', 'Expoente da curva de nível'],
            'level_titles' => [
                '[[1,"Andarilho"],[5,"Sobrevivente"],[10,"Batedor"],[20,"Veterano das Ruínas"],[30,"Lenda de Vera Cruz"]]',
                'Títulos por nível mínimo (JSON)',
            ],
        ];
    }
}
