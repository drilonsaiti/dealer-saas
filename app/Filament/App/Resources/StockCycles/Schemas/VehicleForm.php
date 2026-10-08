<?php

namespace App\Filament\App\Resources\StockCycles\Schemas;

use App\Domain\Vehicles\Actions\FindVehicleDuplicates;
use App\Domain\Vehicles\Enums\BodyType;
use App\Domain\Vehicles\Enums\DriveType;
use App\Domain\Vehicles\Enums\FuelType;
use App\Domain\Vehicles\Enums\Transmission;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use App\Domain\Vehicles\Support\Vin;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The vehicle's own data, shared by "New vehicle" and "Edit vehicle file".
 *
 * $ignoreVehicleId returns the vehicle being edited (null when creating), so its own
 * Stammnummer does not count as a duplicate.
 */
final class VehicleForm
{
    /**
     * @param  Closure(): ?string  $ignoreVehicleId
     * @param  bool  $allowKnownVehicle  creating: a known car without open file is fine (it comes back)
     * @return list<Section>
     */
    public static function sections(Closure $ignoreVehicleId, bool $allowKnownVehicle): array
    {
        return [
            Section::make(__('Identification'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('stammnummer')
                            ->label(__('Stammnummer'))
                            ->placeholder('683.737.537')
                            ->maxLength(15)
                            ->live(onBlur: true)
                            ->formatStateUsing(fn (?string $state): ?string => Stammnummer::format($state))
                            ->dehydrateStateUsing(fn (?string $state): ?string => Stammnummer::normalize($state))
                            ->helperText(fn (?string $state): ?string => self::stammnummerHint($state, $ignoreVehicleId(), $allowKnownVehicle))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($ignoreVehicleId, $allowKnownVehicle): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (! Stammnummer::isValid((string) $value)) {
                                    $fail(__('The Stammnummer has 9 digits, for example 683.737.537.'));

                                    return;
                                }

                                $existing = app(FindVehicleDuplicates::class)->byStammnummer((string) $value, $ignoreVehicleId());

                                if ($existing === null) {
                                    return;
                                }

                                if (! $allowKnownVehicle) {
                                    $fail(__('Another vehicle already has this Stammnummer.'));

                                    return;
                                }

                                $open = $existing->openStockCycle()->first();

                                if ($open !== null) {
                                    $fail(__('This vehicle is already in stock (file :number).', ['number' => $open->number ?? $open->status->getLabel()]));
                                }
                            }),
                        TextInput::make('vin')
                            ->label(__('VIN'))
                            ->maxLength(20)
                            ->live(onBlur: true)
                            ->dehydrateStateUsing(fn (?string $state): ?string => Vin::normalize($state))
                            ->helperText(fn (?string $state): ?string => self::vinHint($state, $ignoreVehicleId()))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ! Vin::isValid((string) $value)) {
                                    $fail(__('A VIN has 17 letters and digits (no I, O or Q).'));
                                }
                            }),
                        TextInput::make('plate')->label(__('Plate'))->placeholder('BE 123456')->maxLength(20),
                        TextInput::make('internal_label')
                            ->label(__('Internal label'))
                            ->helperText(__('Your own short name, e.g. "BMW X3 blue Shema".'))
                            ->maxLength(255),
                    ]),
                ]),
            Section::make(__('Vehicle'))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('make')->label(__('Make'))->maxLength(60)
                            ->required(fn (Get $get): bool => blank($get('internal_label'))),
                        TextInput::make('model')->label(__('Model'))->maxLength(80),
                        TextInput::make('variant')->label(__('Version'))->maxLength(120),
                        Select::make('vehicle_type')->label(__('Vehicle type'))->options(VehicleType::class)->default(VehicleType::PassengerCar)->required(),
                        Select::make('body_type')->label(__('Body type'))->options(BodyType::class),
                        Select::make('fuel')->label(__('Fuel'))->options(FuelType::class),
                        Select::make('transmission')->label(__('Transmission'))->options(Transmission::class),
                        Select::make('drive')->label(__('Drive'))->options(DriveType::class),
                        DatePicker::make('first_registration_on')->label(__('First registration')),
                        TextInput::make('power_kw')->label(__('Power (kW)'))->integer()->minValue(1)->maxValue(2000),
                        TextInput::make('displacement_cc')->label(__('Displacement (cm³)'))->integer()->minValue(1)->maxValue(20000),
                        TextInput::make('type_approval')->label(__('Type approval'))->maxLength(20),
                        TextInput::make('color_exterior')->label(__('Exterior colour'))->maxLength(60),
                        TextInput::make('color_interior')->label(__('Interior colour'))->maxLength(60),
                        TextInput::make('doors')->label(__('Doors'))->integer()->minValue(0)->maxValue(9),
                        TextInput::make('seats')->label(__('Seats'))->integer()->minValue(0)->maxValue(99),
                    ]),
                ]),
            Section::make(__('Registration, inspection and service'))
                ->collapsible()
                ->collapsed(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Grid::make(3)->schema([
                        DatePicker::make('last_registration_on')->label(__('Last registration')),
                        DatePicker::make('mfk_last_on')->label(__('Last MFK')),
                        DatePicker::make('mfk_due_on')->label(__('Next MFK due')),
                        DatePicker::make('service_last_on')->label(__('Last service')),
                        TextInput::make('service_last_km')->label(__('Last service at (km)'))->integer()->minValue(0),
                        DatePicker::make('service_next_on')->label(__('Next service due')),
                        TextInput::make('keys_count')->label(__('Number of keys'))->integer()->minValue(0)->maxValue(20),
                        TextInput::make('curb_weight_kg')->label(__('Curb weight (kg)'))->integer()->minValue(0),
                        TextInput::make('total_weight_kg')->label(__('Total weight (kg)'))->integer()->minValue(0),
                    ]),
                ]),
            Section::make(__('Equipment and notes'))
                ->collapsible()
                ->collapsed(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    TagsInput::make('equipment')->label(__('Equipment'))->placeholder(__('e.g. Navigation, heated seats')),
                    Textarea::make('internal_notes')->label(__('Internal notes'))->rows(3),
                ]),
        ];
    }

    private static function stammnummerHint(?string $state, ?string $ignoreId, bool $allowKnownVehicle): ?string
    {
        if (! $allowKnownVehicle || ! Stammnummer::isValid($state)) {
            return null;
        }

        $existing = app(FindVehicleDuplicates::class)->byStammnummer($state, $ignoreId);

        if ($existing === null || $existing->openStockCycle()->exists()) {
            return null;
        }

        return __('Known vehicle (:name): a new file will be opened for it. Fields you leave empty keep their current values.', ['name' => $existing->displayName()]);
    }

    private static function vinHint(?string $state, ?string $ignoreId): ?string
    {
        if (! Vin::isValid($state)) {
            return null;
        }

        $duplicate = app(FindVehicleDuplicates::class)->byVin($state, $ignoreId)->first();

        if (! $duplicate instanceof Vehicle) {
            return null;
        }

        return __('Warning: :name has the same VIN (Stammnummer :stammnummer). Check before saving.', [
            'name' => $duplicate->displayName(),
            'stammnummer' => $duplicate->formattedStammnummer() ?? '–',
        ]);
    }
}
