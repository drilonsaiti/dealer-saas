<?php

namespace App\Filament\App\Resources\Financings\RelationManagers;

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Actions\TickChecklistItem;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What the bank needs before it pays out (from the partner's checklist template). Items with
 * a rule tick themselves from the vehicle file; the others are ticked here.
 */
class PartnerChecklistRelationManager extends RelationManager
{
    protected static string $relationship = 'checklistItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documents for the bank');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Financing && ! in_array($ownerRecord->status, [FinancingStatus::Applied, FinancingStatus::Approved, FinancingStatus::Rejected], true);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Financing $financing */
        $financing = $this->getOwnerRecord();
        app(SyncChecklist::class)->partner($financing);

        return $table
            ->columns([
                IconColumn::make('done_at')->label('')->state(fn (ChecklistItem $record): bool => $record->isDone())->boolean(),
                TextColumn::make('label')->label(__('Item'))->wrap()
                    ->description(fn (ChecklistItem $record): ?string => match (true) {
                        $record->auto && $record->isDone() => __('ticked automatically'),
                        $record->auto_rule !== null && ! $record->isDone() && str_starts_with($record->auto_rule, 'document:') => __('ticks itself when the document is in the file'),
                        $record->auto_rule !== null && ! $record->isDone() => __('ticks itself from the records'),
                        default => null,
                    }),
                TextColumn::make('required')->label(__('Required'))->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('optional')),
            ])
            ->defaultSort('sort')
            ->recordActions([
                Action::make('tick')
                    ->label(fn (ChecklistItem $record): string => $record->isDone() ? __('Untick') : __('Tick'))
                    ->icon(fn (ChecklistItem $record): Heroicon => $record->isDone() ? Heroicon::OutlinedXMark : Heroicon::OutlinedCheck)
                    ->visible(fn (ChecklistItem $record): bool => ! $record->auto && ($record->auto_rule === null || str_starts_with($record->auto_rule, 'document:'))
                        && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false))
                    ->action(function (ChecklistItem $record, Action $action): void {
                        try {
                            app(TickChecklistItem::class)($record, ! $record->isDone());
                        } catch (BusinessRuleException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->paginated(false);
    }
}
