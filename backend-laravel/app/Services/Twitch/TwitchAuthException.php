<?php

namespace App\Services\Twitch;

use RuntimeException;

/**
 * Falha no OAuth da Twitch: código inválido, token revogado ou refresh recusado.
 */
class TwitchAuthException extends RuntimeException {}
