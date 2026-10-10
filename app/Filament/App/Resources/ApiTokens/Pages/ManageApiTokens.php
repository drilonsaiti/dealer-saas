<?php

namespace App\Filament\App\Resources\ApiTokens\Pages;

use App\Filament\App\Resources\ApiTokens\ApiTokenResource;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ManageApiTokens extends ManageRecords
{
    protected static string $resource = ApiTokenResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(e(__('Public API for your website: :url', ['url' => url('/api/v1/vehicles')])).' · '.e(__('Header: Authorization: Bearer <token>')));
    }

    protected function getHeaderActions(): array
    {
        return [ApiTokenResource::create()];
    }
}
