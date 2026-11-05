<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends TenantModel
{
    protected $guarded = ['id', 'tenant_id', 'active_tc_student_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'issued_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class);
    }
}
