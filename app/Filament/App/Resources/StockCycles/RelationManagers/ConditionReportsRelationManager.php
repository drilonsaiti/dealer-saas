<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Preparation\Actions\RecordConditionReport;
use App\Domain\Preparation\Enums\ConditionRating;
use App\Domain\Preparation\Enums\DamageArea;
use App\Domain\Preparation\Enums\DamageKind;
use App\Domain\Preparation\Enums\DamageSeverity;
use App\Domain\Preparation\Models\ConditionReport;
use App\Domain\Preparation\Models\Damage;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Condition reports of the file (preparation): rating per area, damages with photos.
 */
class ConditionReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'conditionReports';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Condition');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['damages.repairOrder', 'user']))
            ->columns([
                TextColumn::make('reported_on')->label(__('Date'))->date()->sortable()
                    ->description(fn (ConditionReport $record): ?string => $record->user?->name),
                TextColumn::make('mileage')->label(__('Mileage'))->numeric(thousandsSeparator: '’')->suffix(' km')->placeholder('–'),
                TextColumn::make('ratings')->label(__('Condition'))->wrap()
                    ->state(fn (ConditionReport $record): string => collect($record->items ?? [])
                        ->filter(fn (array $item): bool => $item['rating'] !== 'ok')
                        ->map(fn (array $item, string $area): string => (ConditionReport::areaLabels()[$area] ?? $area).': '.(ConditionRating::tryFrom($item['rating'])?->getLabel() ?? '').(filled($item['note'] ?? null) ? ' ('.$item['note'].')' : ''))
                        ->implode(' · ') ?: __('all areas OK')),
                TextColumn::make('damages')->label(__('Damages'))->wrap()
                    ->state(fn (ConditionReport $record): string => $record->damages->map(fn (Damage $d): string => $d->label().($d->repairOrder ? ' → '.$d->repairOrder->status->getLabel() : ''))->implode(' · ') ?: '–'),
                TextColumn::make('summary')->label(__('Summary'))->wrap()->limit(120)->placeholder('–'),
            ])
            ->defaultSort('reported_on', 'desc')
            ->headerActions([
                Action::make('report')
                    ->label(__('New condition report'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->visible(fn (): bool => auth()->user()?->can('create', ConditionReport::class) ?? false)
                    ->modalWidth('4xl')
                    ->fillForm(fn (): array => [
                        'reported_on' => now()->toDateString(),
                        'mileage' => $this->cycle()->mileage_in,
                        'items' => collect(ConditionReport::AREAS)->mapWithKeys(fn (string $a): array => [$a => ['rating' => 'ok', 'note' => null]])->all(),
                    ])
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('reported_on')->label(__('Date'))->required(),
                            TextInput::make('mileage')->label(__('Mileage'))->integer()->suffix('km'),
                        ]),
                        Section::make(__('Areas'))->compact()->schema(collect(ConditionReport::areaLabels())->map(fn (string $label, string $area): Grid => Grid::make(2)->schema([
                            Select::make("items.{$area}.rating")->label($label)->options(ConditionRating::class)->required()->native(false),
                            TextInput::make("items.{$area}.note")->label(__('Note'))->maxLength(200),
                        ]))->values()->all()),
                        Repeater::make('damages')->label(__('Damages'))
                            ->schema([
                                Grid::make(3)->schema([
                                    Select::make('area')->label(__('Where'))->options(DamageArea::class)->required(),
                                    Select::make('kind')->label(__('What'))->options(DamageKind::class)->required(),
                                    Select::make('severity')->label(__('Severity'))->options(DamageSeverity::class)->default('minor')->required(),
                                ]),
                                TextInput::make('notes')->label(__('Note'))->maxLength(200),
                                FileUpload::make('photos')->label(__('Photos'))->image()->multiple()->storeFiles(false)->maxFiles(6)
                                    ->maxSize((int) config('dealer.documents.max_upload_kb')),
                            ])
                            ->defaultItems(0)->addActionLabel(__('Add damage'))->collapsible(),
                        Textarea::make('summary')->label(__('Summary'))->rows(2),
                    ])
                    ->action(function (array $data, Action $action): void {
                        // Uploaded photos are UploadedFile instances (name and path kept for the document).
                        $damages = array_map(fn (array $d): array => [
                            ...$d,
                            'photos' => array_values(array_filter((array) ($d['photos'] ?? []), fn ($f): bool => $f instanceof TemporaryUploadedFile)),
                        ], array_values($data['damages'] ?? []));

                        try {
                            app(RecordConditionReport::class)($this->cycle(), $data, $damages);
                        } catch (BusinessRuleException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title(__('Condition report saved.'))->success()->send();
                    }),
            ])
            ->paginated(false);
    }

    private function cycle(): StockCycle
    {
        /** @var StockCycle $cycle */
        $cycle = $this->getOwnerRecord();

        return $cycle;
    }
}
