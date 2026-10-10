<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Listings\Models\Listing;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VehicleResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/vehicles: the dealer's published cars (available, reserved, recently sold),
 * filterable and paginated. GET /api/v1/vehicles/{id}: one car.
 */
class VehicleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'filter.make' => ['nullable', 'string', 'max:60'],
            'filter.fuel' => ['nullable', 'string', 'max:20'],
            'filter.body_type' => ['nullable', 'string', 'max:20'],
            'filter.price_max' => ['nullable', 'numeric', 'min:0'],
            'filter.availability' => ['nullable', 'in:available,reserved,sold'],
            'sort' => ['nullable', 'in:price,-price,published_at,-published_at,mileage,-mileage'],
            'page.size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filter = (array) $request->input('filter', []);
        $sort = (string) $request->input('sort', '-published_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = match (ltrim($sort, '-')) {
            'price' => 'listings.price_rp',
            'mileage' => 'stock_cycles.mileage_in',
            default => 'listings.published_at',
        };

        $listings = Listing::query()->publiclyVisible()
            ->with(['stockCycle.vehicle'])
            ->join('stock_cycles', 'stock_cycles.id', '=', 'listings.stock_cycle_id')
            ->select('listings.*')
            ->when(filled($filter['make'] ?? null), fn (Builder $q) => $q->whereHas('stockCycle.vehicle', fn (Builder $v) => $v->whereRaw('lower(make) = ?', [mb_strtolower((string) $filter['make'])])))
            ->when(filled($filter['fuel'] ?? null), fn (Builder $q) => $q->whereHas('stockCycle.vehicle', fn (Builder $v) => $v->where('fuel', $filter['fuel'])))
            ->when(filled($filter['body_type'] ?? null), fn (Builder $q) => $q->whereHas('stockCycle.vehicle', fn (Builder $v) => $v->where('body_type', $filter['body_type'])))
            ->when(filled($filter['price_max'] ?? null), fn (Builder $q) => $q->where('listings.show_price', true)->where('listings.price_rp', '<=', (int) round((float) $filter['price_max'] * 100)))
            ->when(filled($filter['availability'] ?? null), fn (Builder $q) => $q->whereIn('stock_cycles.status', match ($filter['availability']) {
                'available' => [StockCycleStatus::Listed->value, StockCycleStatus::ReadyForSale->value],
                'reserved' => [StockCycleStatus::Reserved->value],
                default => [StockCycleStatus::Sold->value, StockCycleStatus::Delivered->value, StockCycleStatus::Archived->value],
            }))
            ->orderBy($column, $direction)
            ->paginate((int) $request->input('page.size', 24), pageName: 'page.number')
            ->withQueryString();

        return VehicleResource::collection($listings);
    }

    public function show(string $vehicle): VehicleResource
    {
        $listing = Listing::query()->publiclyVisible()->with(['stockCycle.vehicle'])->whereKey($vehicle)->first();

        abort_if($listing === null, 404, 'Vehicle not found.');

        return new VehicleResource($listing);
    }
}
