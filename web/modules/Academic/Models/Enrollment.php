<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Institution\Models\AcademicSession;

/** A student's place in a section for a session. History rows are kept when students move. */
class Enrollment extends TenantModel
{
    protected $guarded = ['id', 'tenant_id', 'active_session_id', 'active_roll_no'];

    protected function casts(): array
    {
        return ['joined_on' => 'date:Y-m-d', 'left_on' => 'date:Y-m-d', 'roll_no' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }
}
