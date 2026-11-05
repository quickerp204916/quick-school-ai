<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Class (school), semester (college) or level (coaching). Ordered by sequence for promotion. */
class GradeLevel extends TenantModel
{
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_active' => 'boolean'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'level_subjects')->withPivot('is_optional');
    }

    /** The level students are promoted into, or null at the final level. */
    public function next(): ?self
    {
        return self::where('program_id', $this->program_id)->where('sequence', '>', $this->sequence)
            ->where('is_active', true)->orderBy('sequence')->first();
    }
}
