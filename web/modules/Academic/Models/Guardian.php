<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Identity\Models\User;

class Guardian extends TenantModel
{
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')->withPivot(['relation', 'is_primary', 'is_emergency', 'can_pickup']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
