<?php

namespace Modules\Institution\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\AcademicTerm;

class AcademicSession extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['planning', 'open', 'closed'];

    protected $fillable = ['tenant_id', 'name', 'starts_on', 'ends_on', 'is_current', 'status'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'is_current' => 'boolean'];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class, 'session_id')->orderBy('sequence');
    }

    public static function current(): ?self
    {
        return self::where('is_current', true)->first();
    }

    public function contains(\DateTimeInterface|string $date): bool
    {
        $d = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $d >= $this->starts_on->format('Y-m-d') && $d <= $this->ends_on->format('Y-m-d');
    }
}
