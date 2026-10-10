<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Inbox\Mime\Attachment;
use App\Domain\Inbox\Support\AttachmentGuard;
use App\Domain\Portal\Models\PortalLink;
use App\Domain\Portal\Support\PortalContent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\BusinessRuleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The buyer's portal behind a personal secret link: status, documents, invoices, warranty,
 * and an upload for documents the dealer needs.
 */
class PortalController
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(string $token): View
    {
        $link = $this->resolve($token);
        $content = new PortalContent($link->sale);

        return view('portal.show', [
            'token' => $token,
            'tenant' => $this->context->tenant(),
            'sale' => $link->sale,
            'content' => $content,
            'link' => $link,
        ]);
    }

    public function download(string $token, string $document): StreamedResponse
    {
        $link = $this->resolve($token);
        $content = new PortalContent($link->sale);
        $photo = $content->photo();

        $record = $photo?->getKey() === $document ? $photo : $content->documentQuery()->with('currentVersion')->find($document);
        $version = $record?->currentVersion;
        abort_if($version === null, 404);

        return Storage::disk($version->disk)->response($version->path, $version->original_name, [
            'Content-Type' => $version->mime,
            'Cache-Control' => 'private, no-store',
        ], $record === $photo ? 'inline' : 'attachment');
    }

    public function upload(Request $request, string $token, StoreDocument $store, AttachmentGuard $guard): RedirectResponse
    {
        $link = $this->resolve($token);
        $request->validate(['file' => ['required', 'file', 'max:20480'], 'note' => ['nullable', 'string', 'max:200']]);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $name = basename(str_replace('\\', '/', $file->getClientOriginalName())) ?: 'upload';
        $refusal = $guard->refuse(new Attachment($name, (string) $file->getMimeType(), (string) file_get_contents((string) $file->getRealPath())));

        if ($refusal !== null) {
            return back()->withErrors(['file' => $refusal]);
        }

        $category = DocumentCategory::query()->where('key', 'customer_upload')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'customer_upload')->firstOrFail();
        }

        try {
            $store($file, $category, [
                'title' => trim(($request->input('note') ? $request->input('note').' – ' : '').pathinfo($name, PATHINFO_FILENAME)),
                'original_name' => $name,
                'document_on' => now()->toDateString(),
                'source' => DocumentSource::Upload,
            ], [$link->sale->stockCycle, $link->sale]);
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', __('Thank you, the file has been sent to :dealer.', ['dealer' => $this->context->tenant()?->name]));
    }

    private function resolve(string $token): PortalLink
    {
        $link = str_starts_with($token, PortalLink::PREFIX)
            ? $this->context->bypass(fn (): ?PortalLink => PortalLink::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first())
            : null;
        $tenant = $link === null ? null : $this->context->bypass(fn (): ?Tenant => Tenant::query()->find($link->tenant_id));

        abort_if($link === null || $tenant === null || ! $tenant->isActive() || ! $link->isUsable(), 404);

        $this->context->set($tenant);
        app()->terminating(fn () => $this->context->clear());

        $link->setRelation('sale', $link->sale()->with(['buyer', 'stockCycle.vehicle'])->firstOrFail());
        app()->setLocale($link->sale->buyer->locale ?: $tenant->default_locale);

        if ($link->last_used_at === null || $link->last_used_at->lt(now()->subMinutes(10))) {
            $link->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $link;
    }
}
