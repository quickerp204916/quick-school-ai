<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiSetting extends TenantModel
{
    protected $table = 'ai_settings';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'models' => 'array', 'agents_enabled' => 'array'];
    }

    public static function current(): self
    {
        return self::firstOrCreate([], ['enabled' => true, 'models' => [], 'language' => 'en']);
    }

    /** [provider, model] for an agent. */
    public function modelFor(string $agent): array
    {
        $m = $this->models[$agent] ?? config('ai.default');

        return [$m['provider'], $m['model']];
    }

    public function agentEnabled(string $agent): bool
    {
        return $this->enabled && array_key_exists($agent, config('ai.agents')) && ($this->agents_enabled === null || in_array($agent, $this->agents_enabled, true));
    }
}
