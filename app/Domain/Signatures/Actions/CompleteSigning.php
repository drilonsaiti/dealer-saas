<?php

namespace App\Domain\Signatures\Actions;

use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Signatures\Enums\SignatureRequestStatus;
use App\Domain\Signatures\Mail\SignedDocumentMail;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Domain\Signatures\Models\Signer;
use App\Domain\Signatures\Support\DocumentSealer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * All have signed: the contract is rendered again from its frozen data with the signatures in
 * their fields and an evidence page in the document's language, sealed, and filed as a new
 * locked version. The document is "signed" from now on and can no longer change.
 */
class CompleteSigning
{
    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly DocumentSealer $sealer,
        private readonly StoreDocument $store,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SignatureRequest $request): Document
    {
        $request->loadMissing(['document', 'version', 'signers']);
        $document = $request->document;
        $original = $request->version;
        $snapshot = (array) $original->data_snapshot;
        $type = TemplateType::from((string) $snapshot['type']);

        $snapshot['signatures'] = $request->signers
            ->mapWithKeys(fn (Signer $signer): array => [$type->signatureSide($signer->role) => [
                'image' => $this->signatureImage($signer),
                'place_date' => $signer->place.', '.$signer->signed_at?->timezone('Europe/Zurich')->format('d.m.Y'),
            ]])
            ->all();
        $snapshot['evidence'] = $this->evidence($request, $original);

        $pdf = $this->renderer->pdf($snapshot);
        $file = tempnam(sys_get_temp_dir(), 'signed');
        file_put_contents($file, $pdf);

        try {
            $seal = $this->sealer->seal($file);
            $sha = (string) hash_file('sha256', $file);
            $name = preg_replace('/\.pdf$/i', '', $original->original_name).'_'.str_replace(' ', '-', (string) __('Signed', locale: (string) $snapshot['locale'])).'.pdf';

            $signed = DB::transaction(function () use ($request, $document, $original, $snapshot, $file, $sha, $name, $seal): DocumentVersion {
                /** @var Document $locked */
                $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                $next = (int) $locked->versions()->max('version_no') + 1;
                $disk = (string) config('dealer.documents.disk');

                $path = $this->store->storeFile($disk, $locked, $next, $file, $sha, (string) $name);
                $version = $this->store->createVersion($locked, $next, $disk, $path, $file, $sha, (string) $name, $locked->category, $locked->document_on, $locked->source);
                $version->forceFill([
                    'data_snapshot' => $snapshot,
                    'template_version_id' => $original->template_version_id,
                    'locked_at' => now(),
                ])->save();

                $locked->forceFill(['current_version_id' => $version->getKey(), 'status' => DocumentStatus::Signed])->save();

                $request->forceFill([
                    'status' => SignatureRequestStatus::Completed,
                    'completed_at' => now(),
                    'signed_version_id' => $version->getKey(),
                    'seal' => $seal,
                ])->save();

                return $version;
            });
        } finally {
            @unlink($file);
        }

        $this->sendCopies($request, $signed, $pdf = $signed->contents());

        return $document->refresh();
    }

    private function signatureImage(Signer $signer): ?string
    {
        if ($signer->signature_path === null) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk((string) config('dealer.documents.disk'))->get($signer->signature_path));
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(SignatureRequest $request, DocumentVersion $original): array
    {
        return [
            'request_id' => $request->getKey(),
            'document_sha256' => $original->sha256,
            'data_sha256' => hash('sha256', (string) json_encode($original->data_snapshot)),
            'started_at' => $request->created_at?->timezone('Europe/Zurich')->format('Y-m-d H:i:s'),
            'signers' => $request->signers->map(fn (Signer $signer): array => [
                'position' => $signer->position,
                'role' => $signer->role->value,
                'name' => $signer->name,
                'method' => $signer->method->value,
                'identification' => $this->identificationForEvidence((array) $signer->identification),
                'signed_at' => $signer->signed_at?->timezone('Europe/Zurich')->format('Y-m-d H:i:s'),
                'place' => $signer->place,
                'ip' => $signer->ip,
                'device' => $signer->user_agent,
                'signature_sha256' => $signer->signature_sha256,
            ])->values()->all(),
        ];
    }

    /**
     * The ID number is shortened on the page; the full number stays encrypted in the database.
     *
     * @param  array<string, mixed>  $identification
     * @return array<string, mixed>
     */
    private function identificationForEvidence(array $identification): array
    {
        if (isset($identification['doc_number'])) {
            $number = (string) $identification['doc_number'];
            $identification['doc_number'] = str_repeat('*', max(0, mb_strlen($number) - 3)).mb_substr($number, -3);
        }

        return $identification;
    }

    private function sendCopies(SignatureRequest $request, DocumentVersion $signed, string $pdf): void
    {
        $tenant = $this->context->tenant();

        foreach ($request->signers as $signer) {
            if ($signer->role->value !== 'customer' || blank($signer->email)) {
                continue;
            }

            try {
                Mail::to((string) $signer->email)->locale($signer->locale)->send(new SignedDocumentMail(
                    $signer->name,
                    $request->document->title,
                    (string) ($tenant?->legal_name ?: $tenant?->name),
                    $pdf,
                    $signed->original_name,
                ));
            } catch (Throwable $e) {
                // The signature is valid and filed; a failed email must not undo it.
                report($e);
            }
        }
    }
}
