<?php

namespace App\Filament\App\Resources\Parties\Schemas;

use App\Domain\Parties\Actions\FindPartyDuplicates;
use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Enums\Salutation;
use App\Domain\Parties\Models\Party;
use App\Domain\Parties\Support\SwissLanguageRegion;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Person or company. Used on the contacts screen and, shortened, wherever a seller,
 * supplier or customer can be created on the spot.
 */
final class PartyForm
{
    /**
     * @return list<Section>
     */
    public static function full(?PartyRole $defaultRole = null): array
    {
        return [
            Section::make(__('Contact'))
                ->schema([
                    ...self::identity($defaultRole),
                    self::duplicateWarning(),
                ]),
            Section::make(__('Address and contact'))
                ->schema(self::addressAndContact()),
            Section::make(__('VAT and identification'))
                ->collapsible()
                ->collapsed(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('uid')->label(__('UID'))->placeholder('CHE-123.456.789')->maxLength(20)
                            ->visible(fn (Get $get): bool => $get('kind') === PartyKind::Company->value || $get('kind') === PartyKind::Company),
                        TextInput::make('vat_number')->label(__('VAT number'))->maxLength(30),
                        Select::make('vat_registered')
                            ->label(__('VAT registered'))
                            ->options(['1' => __('Yes'), '0' => __('No')])
                            ->placeholder(__('Unknown'))
                            ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : ($state ? '1' : '0'))
                            ->dehydrateStateUsing(fn (mixed $state): ?bool => $state === null || $state === '' ? null : (bool) (int) $state),
                        DatePicker::make('birth_date')->label(__('Date of birth'))->maxDate(now())
                            ->visible(fn (Get $get): bool => $get('kind') !== PartyKind::Company->value && $get('kind') !== PartyKind::Company),
                        Select::make('id_doc_type')->label(__('ID document'))->options([
                            'passport' => __('Passport'),
                            'id_card' => __('Identity card'),
                            'residence_permit' => __('Residence permit'),
                            'driving_licence' => __('Driving licence'),
                        ]),
                        TextInput::make('id_doc_number')->label(__('ID document number'))->maxLength(40),
                    ]),
                ]),
            Section::make(__('Privacy and notes'))
                ->collapsible()
                ->collapsed(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Grid::make(2)->schema([
                        DateTimePicker::make('privacy_ack_at')->label(__('Privacy notice acknowledged')),
                        DateTimePicker::make('consent_marketing_at')->label(__('Agreed to marketing')),
                    ]),
                    Textarea::make('notes')->label(__('Notes'))->rows(3),
                ]),
        ];
    }

    /**
     * The short version for "create on the spot" in a select.
     *
     * @return list<mixed>
     */
    public static function quick(PartyRole $defaultRole): array
    {
        return [
            ...self::identity($defaultRole),
            ...self::addressAndContact(),
            self::duplicateWarning(),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function identity(?PartyRole $defaultRole): array
    {
        return [
            Grid::make(2)->schema([
                ToggleButtons::make('kind')
                    ->label(__('Type'))
                    ->options(PartyKind::class)
                    ->icons([PartyKind::Person->value => Heroicon::OutlinedUser, PartyKind::Company->value => Heroicon::OutlinedBuildingOffice])
                    ->default(PartyKind::Person->value)
                    ->inline()
                    ->live()
                    ->required(),
                CheckboxList::make('roles')
                    ->label(__('Roles'))
                    ->options(PartyRole::class)
                    ->default($defaultRole === null ? [PartyRole::Customer->value] : [$defaultRole->value])
                    ->columns(2)
                    ->required(),
            ]),
            Grid::make(3)->schema([
                TextInput::make('company_name')
                    ->label(__('Company name'))
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => self::isCompany($get))
                    ->visible(fn (Get $get): bool => self::isCompany($get))
                    ->columnSpan(3),
                Select::make('salutation')->label(__('Salutation'))->options([
                    Salutation::Mr->value => Salutation::Mr->getLabel(),
                    Salutation::Ms->value => Salutation::Ms->getLabel(),
                ]),
                TextInput::make('first_name')->label(__('First name'))->maxLength(100)->live(onBlur: true),
                TextInput::make('last_name')
                    ->label(__('Last name'))
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => ! self::isCompany($get)),
            ]),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function addressAndContact(): array
    {
        return [
            Grid::make(4)->schema([
                TextInput::make('street')->label(__('Street'))->maxLength(255)->columnSpan(4),
                TextInput::make('zip')
                    ->label(__('Postcode'))
                    ->maxLength(10)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, string $operation): void {
                        if ($operation === 'create') {
                            $set('locale', SwissLanguageRegion::localeForPostcode($state));
                        }
                    }),
                TextInput::make('city')->label(__('City'))->maxLength(100)->columnSpan(2),
                Select::make('country')->label(__('Country'))->options(self::countries())->default('CH')->required(),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255)->live(onBlur: true)->columnSpan(2),
                TextInput::make('mobile')->label(__('Mobile'))->tel()->maxLength(40)->live(onBlur: true),
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(40)->live(onBlur: true),
                Select::make('locale')
                    ->label(__('Correspondence language'))
                    ->helperText(__('Proposed from the postcode; used for contracts, invoices and emails.'))
                    ->options(config('dealer.locale_names'))
                    ->default('de')
                    ->required()
                    ->columnSpan(2),
            ]),
        ];
    }

    private static function duplicateWarning(): Text
    {
        return Text::make(function (Get $get, ?Party $record): ?string {
            $matches = app(FindPartyDuplicates::class)([
                'company_name' => $get('company_name'),
                'first_name' => $get('first_name'),
                'last_name' => $get('last_name'),
                'email' => $get('email'),
                'phone' => $get('phone'),
                'mobile' => $get('mobile'),
                'uid' => $get('uid'),
            ], $record?->getKey());

            if ($matches->isEmpty()) {
                return null;
            }

            return __('Possible duplicate: :names. Check before saving.', [
                'names' => $matches->map(fn (Party $party): string => trim($party->displayName().' '.($party->city ?? '')))->implode('; '),
            ]);
        })
            ->color('warning')
            ->visible(fn (string $operation): bool => $operation === 'create')
            ->columnSpanFull();
    }

    private static function isCompany(Get $get): bool
    {
        $kind = $get('kind');

        return $kind === PartyKind::Company || $kind === PartyKind::Company->value;
    }

    /**
     * @return array<string, string>
     */
    private static function countries(): array
    {
        return [
            'CH' => __('Switzerland'),
            'LI' => __('Liechtenstein'),
            'DE' => __('Germany'),
            'FR' => __('France'),
            'IT' => __('Italy'),
            'AT' => __('Austria'),
        ];
    }
}
