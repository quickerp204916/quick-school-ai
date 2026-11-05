<?php

namespace Modules\Platform\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\AiCreditEntry;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\Tenant;

/** Per-tenant AI credit ledger. The balance is always the sum of entries. */
final class AiCredits
{
    public function balance(Tenant $tenant): int
    {
        return (int) AiCreditEntry::where('tenant_id', $tenant->id)->sum('amount');
    }

    public function grant(Tenant $tenant, int $amount, string $kind, ?string $note = null, ?PlatformAdmin $by = null): AiCreditEntry
    {
        if (! in_array($kind, ['top_up', 'adjustment'], true)) {
            throw new PlatformRuleException('Staff can only add top-ups or adjustments.');
        }
        if ($amount === 0 || ($kind === 'top_up' && $amount < 0)) {
            throw new PlatformRuleException('A top-up must add credits.');
        }
        if ($kind === 'adjustment' && $amount < 0 && $this->balance($tenant) + $amount < 0) {
            throw new PlatformRuleException('This adjustment would take the balance below zero.');
        }
        if (trim((string) $note) === '') {
            throw new PlatformRuleException('Add a note explaining the change.');
        }

        $entry = AiCreditEntry::create([
            'tenant_id' => $tenant->id, 'amount' => $amount, 'kind' => $kind,
            'reference' => $kind.'-'.now()->format('YmdHisv').'-'.random_int(1000, 9999), 'note' => $note, 'created_by' => $by?->id,
        ]);
        PlatformAudit::record('ai_credits.'.$kind, $entry, $tenant->id, ['amount' => $amount, 'note' => $note], $by);

        return $entry;
    }

    /** Grant exactly once per (kind, reference). Returns false if it was already granted. */
    public function grantOnce(Tenant $tenant, int $amount, string $kind, string $reference, ?string $note = null): bool
    {
        if (AiCreditEntry::where(['tenant_id' => $tenant->id, 'kind' => $kind, 'reference' => $reference])->exists()) {
            return false;
        }

        try {
            AiCreditEntry::create(['tenant_id' => $tenant->id, 'amount' => $amount, 'kind' => $kind, 'reference' => $reference, 'note' => $note]);
        } catch (QueryException $e) {
            // Another worker granted it at the same moment: the unique key stopped the duplicate.
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** Spend credits for an AI call. Throws when the balance is too low. */
    public function consume(Tenant $tenant, int $amount, string $reference, ?string $note = null): AiCreditEntry
    {
        if ($amount <= 0) {
            throw new PlatformRuleException('Usage must be more than zero credits.');
        }

        return DB::transaction(function () use ($tenant, $amount, $reference, $note) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->first();   // serialise spending per tenant

            $balance = $this->balance($tenant);
            if ($balance < $amount) {
                throw new PlatformRuleException("Not enough AI credits: {$balance} left, {$amount} needed.");
            }

            return AiCreditEntry::create(['tenant_id' => $tenant->id, 'amount' => -$amount, 'kind' => 'usage', 'reference' => $reference, 'note' => $note]);
        });
    }

    public function usedBetween(\DateTimeInterface $from, \DateTimeInterface $to, ?Tenant $tenant = null): int
    {
        return (int) -AiCreditEntry::where('kind', 'usage')
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->whereBetween('created_at', [$from, $to])->sum('amount');
    }
}
