<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Documents\DocumentActions;
use App\Filament\App\Resources\Documents\DocumentTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The vehicle file's documents, in the six folders.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documents');
    }

    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof StockCycle && $owner->isLocked();
    }

    public function table(Table $table): Table
    {
        return DocumentTable::configure($table, groupByFolder: true)
            ->headerActions([
                DocumentActions::upload(fn (): array => [$this->getOwnerRecord()]),
            ]);
    }
}
