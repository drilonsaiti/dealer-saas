<?php

namespace App\Domain\Signatures\Actions;

use App\Domain\Documents\Actions\AddDocumentVersion;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Signatures\Enums\SignatureProvider;
use App\Domain\Signatures\Enums\SignatureRequestStatus;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Support\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Signed on paper: the scan of the printed and signed contract becomes the signed version.
 * An open electronic request is withdrawn.
 */
class RecordPaperSignature
{
    public function __construct(
        private readonly AddDocumentVersion $addVersion,
        private readonly CancelSigning $cancel,
    ) {}

    public function __invoke(Document $document, UploadedFile|string $scan, ?string $originalName = null): Document
    {
        if (! in_array($document->status, [DocumentStatus::Final, DocumentStatus::OutForSignature], true) || $document->type_key === null) {
            throw new BusinessRuleException(__('Only a finalised contract can be signed.'));
        }

        return DB::transaction(function () use ($document, $scan, $originalName): Document {
            SignatureRequest::query()->pending()->where('document_id', $document->getKey())->get()
                ->each(fn (SignatureRequest $open) => ($this->cancel)($open, (string) __('Signed on paper')));

            $signedFrom = $document->currentVersion;
            $version = ($this->addVersion)($document->refresh(), $scan, $originalName);
            $version->forceFill(['locked_at' => now()])->save();

            $request = SignatureRequest::create([
                'document_id' => $document->getKey(),
                'document_version_id' => $signedFrom?->getKey() ?? $version->getKey(),
                'provider' => SignatureProvider::Paper,
            ]);
            $request->forceFill([
                'status' => SignatureRequestStatus::Completed,
                'completed_at' => now(),
                'signed_version_id' => $version->getKey(),
            ])->save();

            $document->forceFill(['status' => DocumentStatus::Signed])->save();

            return $document->refresh();
        });
    }
}
