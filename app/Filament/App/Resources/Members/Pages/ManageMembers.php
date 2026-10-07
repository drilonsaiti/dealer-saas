<?php

namespace App\Filament\App\Resources\Members\Pages;

use App\Domain\Tenancy\Actions\InviteMember;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\App\Resources\Members\MemberResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Invite user'))
                ->modalHeading(__('Invite user'))
                ->using(function (array $data): Model {
                    /** @var Tenant $tenant */
                    $tenant = Filament::getTenant();

                    return app(InviteMember::class)(
                        $tenant,
                        (string) $data['email'],
                        $data['name'] ?? null,
                        $data['role'] instanceof Role ? $data['role'] : Role::from((string) $data['role']),
                    );
                }),
        ];
    }
}
