<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiPrompt extends TenantModel
{
    protected $table = 'ai_prompts';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public static function active(string $agent): ?self
    {
        return self::where('agent', $agent)->where('is_active', true)->first();
    }
}
