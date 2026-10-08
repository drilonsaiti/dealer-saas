<?php

namespace App\Filament\App\Resources\StockCycles\Pages;

use App\Domain\Vehicles\Actions\RecordVehicle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Filament\App\Resources\StockCycles\Schemas\PurchaseForm;
use App\Filament\App\Resources\StockCycles\Schemas\VehicleForm;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Filament\Support\MoneyInput;
use App\Support\BusinessRuleException;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * "New vehicle": the car's data plus the start of its file. A car the dealer already had
 * is recognised by its Stammnummer and gets a new file on its existing record.
 */
class CreateStockCycle extends CreateRecord
{
    protected static string $resource = StockCycleResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Group::make(VehicleForm::sections(fn (): ?string => null, allowKnownVehicle: true))
                ->statePath('vehicle')
                ->columnSpanFull(),
            Section::make(__('Vehicle file'))
                ->schema([
                    Radio::make('status')
                        ->label(__('Status'))
                        ->options([
                            StockCycleStatus::InReview->value => __('In review (not bought yet)'),
                            StockCycleStatus::Purchased->value => __('Purchased'),
                        ])
                        ->default(StockCycleStatus::Purchased->value)
                        ->inline()
                        ->live()
                        ->required(),
                    Grid::make(3)->schema([
                        TextInput::make('mileage_in')->label(__('Mileage at purchase'))->integer()->minValue(0)->suffix('km'),
                        MoneyInput::make('planned_price_rp')->label(__('Planned price')),
                        MoneyInput::make('list_price_rp')->label(__('List price')),
                    ]),
                    Textarea::make('notes')->label(__('Notes'))->rows(2),
                ]),
            Section::make(__('Purchase'))
                ->schema(PurchaseForm::components(withMileage: false))
                ->statePath('purchase')
                ->visible(fn (Get $get): bool => $get('status') === StockCycleStatus::Purchased->value),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(RecordVehicle::class)(
                $data['vehicle'] ?? [],
                [
                    'mileage_in' => $data['mileage_in'] ?? null,
                    'planned_price_rp' => $data['planned_price_rp'] ?? null,
                    'list_price_rp' => $data['list_price_rp'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ],
                $data['status'] === StockCycleStatus::Purchased->value
                    ? [...($data['purchase'] ?? []), 'mileage' => $data['mileage_in'] ?? null]
                    : null,
            );
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    public function getTitle(): string
    {
        return __('New vehicle');
    }

    protected function getRedirectUrl(): string
    {
        return StockCycleResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
