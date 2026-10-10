<?php

namespace App\Domain\VehicleData\Providers\AutoIDat;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\VehicleData\Contracts\VehicleDataProvider;
use App\Domain\VehicleData\Support\VehicleData;
use App\Domain\VehicleData\Support\VehicleValuation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Auto-i-DAT: licensed Swiss vehicle data. The dealer's own customer number and API key.
 *
 * The request and field names are an assumption (documentation comes with the licence) and
 * must be checked before going live; everything provider-specific is in this class and
 * AutoIDatMapper.
 */
class AutoIDatProvider implements VehicleDataProvider
{
    public function __construct(private readonly AutoIDatMapper $mapper) {}

    public function key(): string
    {
        return IntegrationAccount::AUTOIDAT;
    }

    public function test(IntegrationAccount $account): void
    {
        $this->get($account, 'vehicles', [], ['typeApproval' => '1AA000', 'limit' => 1], allowNotFound: true);
    }

    public function lookup(IntegrationAccount $account, ?string $typeApproval, ?string $vin): array
    {
        $query = array_filter(['typeApproval' => self::clean($typeApproval), 'vin' => self::clean($vin)]);

        if ($query === []) {
            throw new IntegrationException(__('Enter the type approval or the VIN first.'), retryable: false);
        }

        $response = $this->get($account, 'vehicles', [], $query, allowNotFound: true);
        $items = $response->status() === 404 ? [] : ($response->json('items') ?? $response->json('data') ?? (array_is_list((array) $response->json()) ? $response->json() : []));

        return array_values(array_filter(array_map(
            fn ($item): ?VehicleData => is_array($item) ? $this->mapper->vehicle($item) : null,
            (array) $items,
        )));
    }

    public function valuation(IntegrationAccount $account, string $externalId, Carbon $firstRegistration, int $mileage): VehicleValuation
    {
        $response = $this->get($account, 'valuation', ['id' => $externalId], [
            'firstRegistration' => $firstRegistration->format('Y-m'),
            'mileage' => $mileage,
        ]);

        return $this->mapper->valuation((array) $response->json());
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, mixed>  $query
     */
    private function get(IntegrationAccount $account, string $path, array $parameters, array $query, bool $allowNotFound = false): Response
    {
        $apiKey = $account->credential('api_key');
        $customer = $account->credential('customer_number');

        if ($apiKey === null || $customer === null) {
            throw new IntegrationException(__('Enter the customer number and the API key from Auto-i-DAT.'), retryable: false);
        }

        $url = rtrim((string) config('integrations.autoidat.base_url'), '/').strtr((string) config("integrations.autoidat.paths.{$path}"), array_combine(
            array_map(fn (string $k): string => '{'.$k.'}', array_keys($parameters)),
            array_map('rawurlencode', array_values($parameters)),
        ) ?: []);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('integrations.autoidat.timeout', 15))
                ->withToken($apiKey)
                ->withHeaders(['X-Customer-Number' => $customer, 'Accept-Language' => app()->getLocale()])
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new IntegrationException(__('Auto-i-DAT cannot be reached: :reason', ['reason' => $e->getMessage()]));
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        $detail = $response->json('message') ?? $response->json('error') ?? mb_substr($response->body(), 0, 200);

        throw new IntegrationException(match (true) {
            in_array($response->status(), [401, 403], true) => __('Auto-i-DAT refused access (:code). Check the customer number and the API key.', ['code' => $response->status()]),
            $response->status() === 404 => __('Auto-i-DAT does not know this vehicle.'),
            default => __('Auto-i-DAT answered with an error (:code): :detail', ['code' => $response->status(), 'detail' => is_string($detail) ? $detail : json_encode($detail)]),
        }, $response->status(), retryable: $response->serverError());
    }

    private static function clean(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s.\-]/', '', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }
}
