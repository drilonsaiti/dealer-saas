<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Channels\AutoScout24\AutoScout24Channel;
use App\Domain\Integrations\Contracts\ListingChannel;
use App\Domain\Integrations\Models\IntegrationAccount;
use InvalidArgumentException;

/**
 * The portals a dealer can connect. A new portal = one more ListingChannel adapter here.
 */
final class Channels
{
    /** @var array<string, class-string<ListingChannel>> */
    private const CHANNELS = [
        IntegrationAccount::AUTOSCOUT24 => AutoScout24Channel::class,
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [IntegrationAccount::AUTOSCOUT24 => 'AutoScout24'];
    }

    public static function label(string $provider): string
    {
        return self::options()[$provider] ?? $provider;
    }

    public static function for(string $provider): ListingChannel
    {
        $class = self::CHANNELS[$provider] ?? throw new InvalidArgumentException("Unknown channel {$provider}");

        return app($class);
    }
}
