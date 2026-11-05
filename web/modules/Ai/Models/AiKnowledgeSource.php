<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiKnowledgeSource extends TenantModel
{
    protected $table = 'ai_knowledge_sources';

    protected function casts(): array
    {
        return ['roles' => 'array', 'indexed_at' => 'datetime', 'chunks' => 'integer'];
    }
}
