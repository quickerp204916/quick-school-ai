<?php

namespace Modules\Ai\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class AiActionRequest extends TenantModel
{
    protected $table = 'ai_action_requests';

    public const TYPES = ['accounting.voucher' => 'Accounting voucher', 'fees.refund' => 'Fee refund', 'fees.waiver' => 'Fee waiver', 'payroll.approve' => 'Payroll approval'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\Modules\Identity\Models\User::class, 'requested_by');
    }

    public function present(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'summary' => $this->summary, 'status' => $this->status,
            'message' => 'Waiting for approval by someone allowed to '.str_replace('.', ' ', $this->permission).'. Nothing has been posted.'];
    }
}
