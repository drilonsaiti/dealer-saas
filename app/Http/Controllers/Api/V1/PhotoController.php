<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Listings\Models\Listing;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A listing photo by signed URL (no token: it is used in <img> tags). Only photos chosen for a
 * publicly visible listing are served; cached by browsers for a day.
 */
class PhotoController extends Controller
{
    public function __invoke(TenantContext $context, string $tenant, string $listing, string $document): StreamedResponse
    {
        $owner = $context->bypass(fn () => Tenant::query()->find($tenant));
        abort_if($owner === null, 404);

        return $context->run($owner, function () use ($listing, $document): StreamedResponse {
            $model = Listing::query()->publiclyVisible()->find($listing);
            abort_if($model === null || ! in_array($document, $model->photo_document_ids ?? [], true), 404);

            $version = $model->photos()->firstWhere('id', $document)?->currentVersion;
            abort_if($version === null, 404);

            return Storage::disk($version->disk)->response($version->path, $version->original_name, [
                'Content-Type' => $version->mime,
                'Cache-Control' => 'public, max-age=86400',
            ]);
        });
    }
}
