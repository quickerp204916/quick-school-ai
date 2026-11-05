<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiConversation extends TenantModel
{
    protected $table = 'ai_conversations';

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Modules\Identity\Models\User::class);
    }
}
