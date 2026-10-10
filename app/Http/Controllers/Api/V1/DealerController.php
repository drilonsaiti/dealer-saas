<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/dealer: the dealer's public contact data for the website.
 */
class DealerController extends Controller
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        abort_if($tenant === null, 404);

        return response()->json(['data' => [
            'type' => 'dealers',
            'id' => $tenant->getKey(),
            'attributes' => [
                'name' => $tenant->legal_name ?: $tenant->name,
                'street' => $tenant->street,
                'zip' => $tenant->zip,
                'city' => $tenant->city,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'website' => $tenant->website,
                'languages' => config('dealer.locales'),
            ],
        ]], 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
