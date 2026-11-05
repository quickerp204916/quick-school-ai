<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiDocument extends TenantModel
{
    protected $table = 'ai_documents';

    protected function casts(): array
    {
        return ['extracted' => 'array'];
    }
}
