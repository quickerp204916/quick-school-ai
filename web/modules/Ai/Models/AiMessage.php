<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiMessage extends TenantModel
{
    protected $table = 'ai_messages';

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'credits' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class);
    }
}
