<?php

namespace App\Filament\App\Resources\AuditLogs\Pages;

use App\Filament\App\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
