<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha do livro-caixa. Nunca é alterada depois de criada.
 */
class Transaction extends Model
{
    public const UPDATED_AT = null;

    public const PRESENCE = 'presenca';

    public const MESSAGE = 'mensagem';

    public const COMMAND = 'comando';

    public const REDEMPTION = 'resgate';

    public const SUB = 'sub';

    public const RESUB = 'resub';

    public const GIFT_RECEIVED = 'sub_presente_recebido';

    public const GIFT_GIVEN = 'sub_presente_dado';

    public const BITS = 'bits';

    public const MANUAL = 'ajuste_manual';

    /** Zerado pela aba de gerenciamento (lança o valor negativo; o histórico continua). */
    public const RESET = 'zerado';

    protected $fillable = ['chatter_id', 'stream_session_id', 'type', 'coins_delta', 'xp_delta', 'meta'];

    protected function casts(): array
    {
        return [
            'coins_delta' => 'integer',
            'xp_delta' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function chatter(): BelongsTo
    {
        return $this->belongsTo(Chatter::class);
    }
}
