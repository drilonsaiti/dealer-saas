<?php

namespace App\Filament\Platform\Resources\Tenants\Pages;

use App\Domain\Tenancy\Actions\CreateTenant as CreateTenantAction;
use App\Filament\Platform\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $attributes = Arr::except($data, ['admin_email', 'admin_name']);

        if (blank($attributes['slug'] ?? null)) {
            unset($attributes['slug']);
        }

        return app(CreateTenantAction::class)(
            $attributes,
            (string) $data['admin_email'],
            $data['admin_name'] ?? null,
        );
    }
}
