<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class Voucher extends TenantModel
{
    public const STATUSES = ["draft", "pending", "posted", "rejected", "reversed"];

    protected function casts(): array
    {
        return ["date" => "date:Y-m-d", "lines" => "array", "total" => "integer", "submitted_at" => "datetime", "approved_at" => "datetime", "posted_at" => "datetime"];
    }

    protected static function booted(): void
    {
        // A posted voucher is permanent: only its link to a reversal may be added.
        static::updating(function (self $v) {
            $was = $v->getOriginal("status");
            if (in_array($was, ["posted", "reversed"], true)) {
                $allowed = ["status", "reversed_by_id", "updated_at"];
                if (array_diff(array_keys($v->getDirty()), $allowed) || ($v->isDirty("status") && ! ($was === "posted" && $v->status === "reversed"))) {
                    throw new \LogicException("Posted vouchers cannot be changed. Post a reversal instead.");
                }
            }
        });
        static::deleting(function (self $v) {
            if (! in_array($v->status, ["draft", "rejected"], true)) {
                throw new \LogicException("Only draft or rejected vouchers can be deleted.");
            }
        });
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderBy("line_no");
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, "reversal_of_id");
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, "reversed_by_id");
    }

    public function typeLabel(): string
    {
        return config("accounting.voucher_types.{$this->type}.label", ucfirst($this->type));
    }

    public function isPosted(): bool
    {
        return in_array($this->status, ["posted", "reversed"], true);
    }
}
