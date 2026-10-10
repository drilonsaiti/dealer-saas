<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Listings\Actions\ReceiveEnquiry;
use App\Domain\Listings\Models\Listing;
use App\Http\Controllers\Controller;
use App\Support\BusinessRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/enquiries: the website's enquiry form. A honeypot field ("website") must stay
 * empty; the plugin passes the visitor's IP for rate limiting.
 */
class EnquiryController extends Controller
{
    public function store(Request $request, ReceiveEnquiry $receive): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:190', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'message' => ['required', 'string', 'max:5000'],
            'locale' => ['nullable', 'in:de,fr,it,en'],
            'visitor_ip' => ['nullable', 'ip'],
            'website' => ['nullable', 'max:0'],
        ]);

        $listing = filled($data['vehicle_id'] ?? null) ? Listing::query()->publiclyVisible()->find($data['vehicle_id']) : null;

        if (filled($data['vehicle_id'] ?? null) && $listing === null) {
            return response()->json(['errors' => [['status' => '422', 'title' => 'Unknown vehicle', 'detail' => 'The vehicle is not published.']]], 422);
        }

        try {
            $enquiry = $receive([...$data, 'ip' => $data['visitor_ip'] ?? $request->ip(), 'source' => 'website'], $listing);
        } catch (BusinessRuleException $e) {
            return response()->json(['errors' => [['status' => '422', 'title' => 'Invalid enquiry', 'detail' => $e->getMessage()]]], 422);
        }

        return response()->json(['data' => ['type' => 'enquiries', 'id' => $enquiry->getKey(), 'attributes' => ['status' => 'received']]], 201, ['Content-Type' => 'application/vnd.api+json']);
    }
}
