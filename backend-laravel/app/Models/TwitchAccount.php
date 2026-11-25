<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwitchAccount extends Model
{
    protected $fillable = [
        'user_id',
        'twitch_id',
        'login',
        'display_name',
        'email',
        'profile_image_url',
        'broadcaster_type',
        'access_token',
        'refresh_token',
        'scopes',
        'expires_at',
        'last_validated_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tokenExpiresSoon(): bool
    {
        return $this->expires_at->subMinutes(5)->isPast();
    }

    /**
     * @return array<int, string>
     */
    public function missingScopes(): array
    {
        $required = preg_split('/\s+/', trim((string) config('services.twitch.scopes')), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($required, (array) $this->scopes));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    public function needsValidation(): bool
    {
        return $this->last_validated_at === null
            || $this->last_validated_at->addHour()->isPast();
    }
}
