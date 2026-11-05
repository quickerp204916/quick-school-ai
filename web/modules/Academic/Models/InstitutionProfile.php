<?php

namespace Modules\Academic\Models;

class InstitutionProfile extends TenantModel
{
    protected function casts(): array
    {
        return ['working_days' => 'array', 'required_documents' => 'array', 'attendance_edit_days' => 'integer', 'low_attendance_percent' => 'integer'];
    }

    /** Words for this structure: term('level') → "Class", "Semester" or "Level". */
    public function term(string $key): string
    {
        return config("academic.structures.{$this->structure}.terms.{$key}", ucfirst($key));
    }

    public function isWorkingDay(\DateTimeInterface $date): bool
    {
        return in_array((int) $date->format('N'), array_map('intval', $this->working_days ?? []), true);
    }
}
