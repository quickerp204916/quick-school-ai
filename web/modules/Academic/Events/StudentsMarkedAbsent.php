<?php

namespace Modules\Academic\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Academic\Models\AttendanceRegister;

/**
 * Fired after attendance is saved with absences. The communication module (WhatsApp/SMS phase)
 * listens to alert parents; nothing is sent from here.
 */
class StudentsMarkedAbsent
{
    use Dispatchable;

    /** @param list<string> $studentIds */
    public function __construct(public readonly string $tenantId, public readonly AttendanceRegister $register, public readonly array $studentIds)
    {
    }
}
