<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Academic\Models\TenantModel;

class AiCallLog extends TenantModel
{
    protected $table = 'ai_call_logs';

    public const OUTCOMES = ['connected', 'no_answer', 'busy', 'wrong_number', 'promised_to_pay', 'callback', 'not_interested'];

    protected function casts(): array
    {
        return ['next_action_on' => 'date', 'duration_seconds' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(\Modules\Academic\Models\Student::class);
    }
}
