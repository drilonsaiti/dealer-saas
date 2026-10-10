<?php

namespace App\Domain\VehicleData\Actions;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\Integrations\Support\Providers;
use App\Domain\VehicleData\Contracts\VehicleDataProvider;
use App\Domain\VehicleData\Models\VehicleValuation;
use App\Domain\VehicleData\Support\VehicleData;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use BackedEnum;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Vehicle data from the dealer's licensed provider: look up by type approval / VIN, take over
 * the technical data (empty fields only, unless the user chooses to overwrite) and the chosen
 * equipment; valuation for the current mileage, kept as history. Every call is logged.
 */
class FetchVehicleData
{
    public static function account(): ?IntegrationAccount
    {
        return IntegrationAccount::query()->active()->ofKind(Providers::VEHICLE_DATA)->first();
    }

    /**
     * @return list<VehicleData>
     */
    public function lookup(Vehicle $vehicle, ?string $typeApproval = null, ?string $vin = null): array
    {
        [$account, $provider] = $this->provider();
        $typeApproval ??= $vehicle->type_approval;
        $vin ??= $vehicle->vin;
        $key = 'vehicle-data:'.$account->getKey().':'.hash('sha256', $typeApproval.'|'.$vin);

        // The dialog asks several times while the user chooses; the provider is called once.
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var list<VehicleData> $cached */
            return $cached;
        }

        $results = $this->logged($account, 'lookup', fn (): array => $provider->lookup($account, $typeApproval, $vin));
        Cache::put($key, $results, 600);

        return $results;
    }

    /**
     * @param  list<string>  $optionalEquipment  the options this car actually has
     */
    public function apply(Vehicle $vehicle, VehicleData $data, array $optionalEquipment = [], bool $overwrite = false): Vehicle
    {
        $changes = [];

        foreach (array_filter($data->attributes(), fn ($v): bool => $v !== null) as $field => $value) {
            $current = $vehicle->getAttribute($field);
            $current = $current instanceof BackedEnum ? $current->value : $current;

            if ($overwrite || $current === null || $current === '') {
                $changes[$field] = $value;
            }
        }

        $chosen = array_values(array_intersect($optionalEquipment, $data->optionalEquipment));

        if ($chosen !== []) {
            $changes['equipment'] = array_values(array_unique([...($vehicle->equipment ?? []), ...$chosen]));
        }

        $vehicle->fill($changes)->save();

        return $vehicle;
    }

    public function value(StockCycle $cycle, VehicleData|string $variant): VehicleValuation
    {
        [$account, $provider] = $this->provider();
        $vehicle = $cycle->vehicle;
        $externalId = $variant instanceof VehicleData ? $variant->externalId : $variant;
        $mileage = $cycle->mileage_out ?? $cycle->mileage_in;

        if ($vehicle->first_registration_on === null || $mileage === null) {
            throw new BusinessRuleException(__('Enter the first registration and the mileage first.'));
        }

        $result = $this->logged($account, 'valuation', fn () => $provider->valuation($account, $externalId, Carbon::parse($vehicle->first_registration_on), $mileage));

        return VehicleValuation::query()->create([
            'stock_cycle_id' => $cycle->getKey(),
            'provider' => $account->provider,
            'external_id' => $externalId,
            'valued_on' => now()->toDateString(),
            'mileage' => $mileage,
            'retail_rp' => $result->retailRp,
            'trade_in_rp' => $result->tradeInRp,
            'reference' => $result->reference,
        ]);
    }

    /**
     * @return array{0: IntegrationAccount, 1: VehicleDataProvider}
     */
    private function provider(): array
    {
        $account = self::account() ?? throw new BusinessRuleException(__('Connect a vehicle data provider first (Settings → Integrations).'));
        $provider = Providers::make($account->provider);

        if (! $provider instanceof VehicleDataProvider) {
            throw new BusinessRuleException(__('Connect a vehicle data provider first (Settings → Integrations).'));
        }

        return [$account, $provider];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function logged(IntegrationAccount $account, string $action, Closure $call): mixed
    {
        $started = hrtime(true);

        try {
            $result = $call();
            $this->log($account, $action, 'ok', null, null, $started);

            return $result;
        } catch (IntegrationException $e) {
            $this->log($account, $action, 'error', $e->getMessage(), $e->status, $started);

            throw new BusinessRuleException($e->getMessage());
        }
    }

    private function log(IntegrationAccount $account, string $action, string $status, ?string $message, ?int $code, int $started): void
    {
        IntegrationLog::query()->create([
            'integration_account_id' => $account->getKey(),
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'response_code' => $code,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
