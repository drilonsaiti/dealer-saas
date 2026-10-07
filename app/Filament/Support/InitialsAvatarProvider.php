<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Avatars drawn locally as initials. Filament's default calls ui-avatars.com with the user's
 * name, which would send personal data to a third party (nDSG).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = Filament::getNameForDefaultAvatar($record);

        $initials = Str::of($name)
            ->trim()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        $hue = hexdec(substr(md5($name), 0, 2)) * 360 / 255;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="hsl(%d, 35%%, 45%%)"/>'
            .'<text x="50%%" y="50%%" dy=".35em" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="26" fill="#fff">%s</text>'
            .'</svg>',
            (int) $hue,
            e($initials !== '' ? $initials : '?'),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
