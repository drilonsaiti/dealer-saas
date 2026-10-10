<?php

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Contracts\ListingChannel;
use InvalidArgumentException;

/**
 * The portals listings are published on (the listing kind of Providers).
 */
final class Channels
{
    public static function label(string $provider): string
    {
        return Providers::label($provider);
    }

    public static function for(string $provider): ListingChannel
    {
        $channel = Providers::make($provider);

        return $channel instanceof ListingChannel ? $channel : throw new InvalidArgumentException("{$provider} is not a listing channel");
    }
}
