<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Channels\AutoScout24\AutoScout24Channel;
use App\Domain\Integrations\Contracts\Integration;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\VehicleData\Providers\AutoIDat\AutoIDatProvider;
use InvalidArgumentException;

/**
 * The external services a dealer can connect, by kind: portals the listings go to, and
 * providers of vehicle data. A new service = one adapter class and one entry here.
 */
final class Providers
{
    public const LISTING = 'listing';

    public const VEHICLE_DATA = 'vehicle_data';

    /**
     * @return array<string, array{label: string, kind: string, class: class-string<Integration>, fields: array<string, array{label: string, secret?: bool, help?: string}>}>
     */
    public static function all(): array
    {
        return [
            IntegrationAccount::AUTOSCOUT24 => [
                'label' => 'AutoScout24',
                'kind' => self::LISTING,
                'class' => AutoScout24Channel::class,
                'fields' => [
                    'client_id' => ['label' => __('Client ID')],
                    'client_secret' => ['label' => __('Client secret'), 'secret' => true],
                    'seller_id' => ['label' => __('Customer number'), 'help' => __('Your AutoScout24 customer / seller number.')],
                ],
            ],
            IntegrationAccount::AUTOIDAT => [
                'label' => 'Auto-i-DAT',
                'kind' => self::VEHICLE_DATA,
                'class' => AutoIDatProvider::class,
                'fields' => [
                    'customer_number' => ['label' => __('Customer number'), 'help' => __('Your Auto-i-DAT customer number.')],
                    'api_key' => ['label' => __('API key'), 'secret' => true],
                ],
            ],
        ];
    }

    /**
     * @return array{label: string, kind: string, class: class-string<Integration>, fields: array<string, array{label: string, secret?: bool, help?: string}>}
     */
    public static function definition(string $provider): array
    {
        return self::all()[$provider] ?? throw new InvalidArgumentException("Unknown provider {$provider}");
    }

    public static function make(string $provider): Integration
    {
        return app(self::definition($provider)['class']);
    }

    public static function label(string $provider): string
    {
        return self::all()[$provider]['label'] ?? $provider;
    }

    public static function kind(string $provider): ?string
    {
        return self::all()[$provider]['kind'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $d): string => $d['label'], self::all());
    }

    /**
     * @return list<string>
     */
    public static function ofKind(string $kind): array
    {
        return array_keys(array_filter(self::all(), fn (array $d): bool => $d['kind'] === $kind));
    }
}
