<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class Invoice extends TenantModel
{
    public const KINDS = ["sales", "purchase", "credit_note", "debit_note", "expense"];

    protected function casts(): array
    {
        return ["date" => "date:Y-m-d", "due_date" => "date:Y-m-d"] + array_fill_keys(["subtotal", "cgst", "sgst", "igst", "total", "tds", "net"], "integer");
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, "party_ledger_id");
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function against(): BelongsTo
    {
        return $this->belongsTo(self::class, "against_invoice_id");
    }

    public function tdsSection(): BelongsTo
    {
        return $this->belongsTo(TdsSection::class);
    }

    /** Receivable side (sales, credit notes) or payable side (purchases, expenses, debit notes). */
    public function isSalesSide(): bool
    {
        return in_array($this->kind, ["sales", "credit_note"], true);
    }

    public function kindLabel(): string
    {
        return ["sales" => "Sales invoice", "purchase" => "Purchase invoice", "credit_note" => "Credit note", "debit_note" => "Debit note", "expense" => "Expense"][$this->kind];
    }
}
