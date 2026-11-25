<?php

namespace App\Services\Chat;

use App\Models\TwitchAccount;

/**
 * Contas que ficam fora da presença e da contagem de mensagens: bots (TWITCH_IGNORED_LOGINS) e,
 * a menos que TWITCH_COUNT_BROADCASTER=true, a conta do próprio canal.
 */
class IgnoredChatters
{
    /** Lista usada quando TWITCH_IGNORED_LOGINS não está definido. */
    public const DEFAULT_BOTS = 'nightbot,streamelements,streamlabs,moobot,fossabot,wizebot,soundalerts,sery_bot,kofistreambot,commanderroot';

    /** @var array<int, string>|null */
    private ?array $logins = null;

    /**
     * @return array<int, string> logins em minúsculas
     */
    public function logins(): array
    {
        if ($this->logins !== null) {
            return $this->logins;
        }

        $logins = array_filter(array_map(
            fn ($l) => mb_strtolower(trim($l)),
            explode(',', (string) config('services.twitch.ignored_logins', self::DEFAULT_BOTS)),
        ));

        if (! config('services.twitch.count_broadcaster')) {
            $broadcaster = TwitchAccount::latest('updated_at')->value('login');

            if ($broadcaster) {
                $logins[] = mb_strtolower($broadcaster);
            }
        }

        return $this->logins = array_values(array_unique($logins));
    }

    public function isIgnored(string $login): bool
    {
        return in_array(mb_strtolower($login), $this->logins(), true);
    }
}
