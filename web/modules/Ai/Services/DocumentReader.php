<?php

namespace Modules\Ai\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Ai\AiException;
use Modules\Ai\Models\AiDocument;
use Modules\Ai\Models\AiSetting;
use Modules\Platform\Services\AiCredits;

/**
 * Reads an uploaded marksheet, certificate or vendor bill with a vision model. The result is a proposal for a person
 * to check; a vendor bill can then be turned into a draft purchase invoice, which still has to be posted by hand.
 */
final class DocumentReader
{
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly CurrentMember $member, private readonly AiServiceClient $client, private readonly ChatService $chat)
    {
    }

    public function upload(string $type, UploadedFile $file): AiDocument
    {
        $this->member->authorize('ai.use');
        if (! array_key_exists($type, config('ai.document_types'))) {
            throw new AiException('Choose what kind of document this is.', 'doc_type');
        }
        if (! in_array($file->getMimeType(), self::MIMES, true) || $file->getSize() > 10 * 1024 * 1024) {
            throw new AiException('Upload a PDF, JPG, PNG or WEBP file of up to 10 MB.', 'file');
        }
        $path = $file->store('tenants/'.TenantContext::id().'/ai-documents', 'local');
        $doc = AiDocument::create(['doc_type' => $type, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType(), 'status' => 'pending', 'uploaded_by' => $this->member->userId()]);
        Audit::record('ai.document_uploaded', $doc, ['type' => $type]);

        return $this->extract($doc);
    }

    public function extract(AiDocument $doc): AiDocument
    {
        $tenant = $this->chat->tenant();
        $cost = (int) config('ai.extraction_credits', 5);
        if (app(AiCredits::class)->balance($tenant) < $cost) {
            $doc->update(['status' => 'failed', 'error' => 'Not enough AI credits to read this document.']);

            return $doc;
        }
        [$provider, $model] = AiSetting::current()->modelFor('document');
        try {
            $out = $this->client->post('/v1/extract', ['claims' => ['tenant_id' => $tenant->id, 'user_id' => (string) $this->member->userId(), 'role' => (string) $this->member->role()],
                'provider' => $provider, 'model' => $model, 'doc_type' => $doc->doc_type, 'mime' => $doc->mime,
                'data_base64' => base64_encode(Storage::disk('local')->get($doc->path))], 180);
            $doc->update(['status' => 'extracted', 'extracted' => $out, 'error' => null]);
            $this->chat->charge($tenant, $cost, 'ai-doc:'.$doc->id, 'Document reading');
        } catch (AiException $e) {
            $doc->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return $doc;
    }

    /** The reviewer corrects the fields and marks the document checked. */
    public function review(AiDocument $doc, array $fields): AiDocument
    {
        $this->member->authorize('ai.use');
        if ($doc->uploaded_by !== $this->member->userId() && ! $this->member->can('ai.approve')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only the person who uploaded it or an approver can review this document.');
        }
        $ex = $doc->extracted ?? [];
        $ex['fields'] = array_replace($ex['fields'] ?? [], array_map(fn ($v) => is_string($v) ? trim($v) : $v, $fields));
        $doc->update(['extracted' => $ex, 'status' => 'reviewed']);
        Audit::record('ai.document_reviewed', $doc);

        return $doc;
    }
}
