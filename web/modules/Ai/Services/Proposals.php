<?php

namespace Modules\Ai\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\VoucherService;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiActionRequest;
use Modules\Fees\Models\FeeInvoice;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Services\CollectionService;
use Modules\Fees\Services\FeePlanService;
use Modules\Hr\Models\PayrollRun;
use Modules\Hr\Services\PayrollService;
use Modules\Identity\Models\User;

/**
 * Sensitive actions an AI agent suggested. The agent only creates a pending request; nothing is posted, refunded,
 * waived or approved until a person with the target permission (and who is not the person who asked) approves it.
 * On approval the action runs through the module's own service, so every accounting, fee and payroll rule still applies:
 * the person who asked is the maker, the approver is the checker.
 */
final class Proposals
{
    public function __construct(private readonly CurrentMember $member, private readonly ToolContext $context)
    {
    }

    /** Called from a propose_ tool, as the chatting user. Returns what the agent should tell the user. */
    public function propose(string $type, string $permission, string $summary, array $payload): array
    {
        $req = AiActionRequest::create(['type' => $type, 'summary' => mb_substr($summary, 0, 300), 'payload' => $payload, 'permission' => $permission,
            'status' => 'pending', 'requested_by' => $this->member->userId(), 'conversation_id' => $this->context->conversationId]);
        Audit::record('ai.proposal_created', $req, ['type' => $type, 'summary' => $req->summary]);

        return ['proposed' => true, 'approval_request' => $req->present()];
    }

    public function canDecide(AiActionRequest $r): bool
    {
        return $this->member->can('ai.approve') && $this->member->can($r->permission) && $r->requested_by !== $this->member->userId();
    }

    public function approve(AiActionRequest $r): AiActionRequest
    {
        $this->guard($r);
        $approver = $this->member->user();
        $requester = User::findOrFail($r->requested_by);

        try {
            $result = DB::transaction(fn () => $this->execute($r, $requester, $approver));
        } catch (\Throwable $e) {
            $r->update(['status' => 'failed', 'decided_by' => $approver->id, 'decided_at' => now(), 'result' => mb_substr($e->getMessage(), 0, 300)]);
            Audit::record('ai.proposal_failed', $r, ['error' => $e->getMessage()]);
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException || $e instanceof \Modules\Academic\Exceptions\AcademicRuleException) {
                throw new AiException('Approved, but the action was refused: '.$e->getMessage());
            }
            throw $e;
        }
        $r->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now(), 'result' => mb_substr($result, 0, 300)]);
        Audit::record('ai.proposal_approved', $r, ['result' => $result]);

        return $r;
    }

    public function reject(AiActionRequest $r, string $reason): AiActionRequest
    {
        $this->guard($r);
        if (mb_strlen(trim($reason)) < 3) {
            throw new AiException('Give a reason for rejecting it.', 'reason');
        }
        $r->update(['status' => 'rejected', 'decided_by' => $this->member->userId(), 'decided_at' => now(), 'result' => mb_substr(trim($reason), 0, 300)]);
        Audit::record('ai.proposal_rejected', $r, ['reason' => $reason]);

        return $r;
    }

    private function guard(AiActionRequest $r): void
    {
        $this->member->authorize('ai.approve');
        $this->member->authorize($r->permission);
        if ($r->status !== 'pending') {
            throw new AiException('This request has already been decided.');
        }
        if ($r->requested_by === $this->member->userId()) {
            throw new AiException('You asked for this, so someone else has to approve it.');
        }
    }

    /** Runs $fn signed in as $user (the maker step), then switches back. */
    private function as(User $user, callable $fn): mixed
    {
        $guard = auth()->guard();
        $before = $guard->user();
        $guard->setUser($user);
        try {
            return $fn();
        } finally {
            $before ? $guard->setUser($before) : $guard->forgetUser();
        }
    }

    private function execute(AiActionRequest $r, User $requester, User $approver): string
    {
        $p = $r->payload;

        return match ($r->type) {
            'accounting.voucher' => (function () use ($p, $requester) {
                $svc = app(VoucherService::class);
                // The person who asked is the maker; the voucher goes through the normal draft → submit path.
                $v = $this->as($requester, fn () => $svc->submit($svc->saveDraft(['type' => $p['type'], 'date' => $p['date'], 'narration' => $p['narration'],
                    'reference' => $p['reference'] ?? null, 'lines' => array_map(fn ($l) => ['ledger_id' => $l['ledger_id'], 'debit' => (int) $l['debit'], 'credit' => (int) $l['credit']], $p['lines'])])));
                if ($v->status === 'pending') {
                    $v = $svc->approve(Voucher::findOrFail($v->id));
                }

                return "Posted as {$v->number}.";
            })(),
            'fees.refund' => (function () use ($p, $requester) {
                $svc = app(CollectionService::class);
                $refund = $this->as($requester, fn () => $svc->requestRefund(FeePayment::findOrFail($p['payment_id']), ['amount' => number_format($p['amount'] / 100, 2, '.', ''), 'reason' => $p['reason']]));
                $refund = $svc->approveRefund($refund);

                return 'Refund approved ('.\Modules\Accounting\Support\Amount::format($refund->amount).'); pay it out from Fee approvals.';
            })(),
            'fees.waiver' => (function () use ($p, $r) {
                $inv = app(FeePlanService::class)->waive(FeeInvoice::findOrFail($p['invoice_id']), number_format($p['amount'] / 100, 2, '.', ''), $p['reason'], 'ai:'.$r->id);

                return "Waived on {$inv->number}; balance now ".\Modules\Accounting\Support\Amount::format($inv->balance()).'.';
            })(),
            'payroll.approve' => (function () use ($p) {
                $run = app(PayrollService::class)->approve(PayrollRun::findOrFail($p['run_id']));

                return "Payroll {$run->period} approved and posted to the books.";
            })(),
            default => throw new AiException("Unknown request type {$r->type}."),
        };
    }
}
