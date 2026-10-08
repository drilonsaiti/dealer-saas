<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Settings → Company: the dealer's own data, printed on every document, plus security options.
 */
class EditCompanyProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('Company');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Company'))
                ->description(__('Printed on contracts, invoices and all other documents.'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')->label(__('Display name'))->required()->maxLength(255),
                        TextInput::make('legal_name')->label(__('Legal name'))->maxLength(255),
                        TextInput::make('uid')
                            ->label(__('UID'))
                            ->placeholder('CHE-123.456.789')
                            ->regex('/^CHE-\d{3}\.\d{3}\.\d{3}$/')
                            ->maxLength(20),
                        TextInput::make('vat_number')
                            ->label(__('VAT number'))
                            ->placeholder('CHE-123.456.789 MWST')
                            ->maxLength(30),
                    ]),
                ]),
            Section::make(__('Address and contact'))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('street')->label(__('Street'))->maxLength(255)->columnSpan(3),
                        TextInput::make('zip')->label(__('Postcode'))->maxLength(10),
                        TextInput::make('city')->label(__('City'))->maxLength(255)->columnSpan(2),
                        TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(40),
                        TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
                        TextInput::make('website')->label(__('Website'))->url()->maxLength(255),
                    ]),
                ]),
            Section::make(__('Language and appearance'))
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('default_locale')
                            ->label(__('Default language'))
                            ->helperText(__('Language of the screens for users without a language of their own; also the default for new customers and your own reports.'))
                            ->options(config('dealer.locale_names'))
                            ->required(),
                        ColorPicker::make('brand_color')->label(__('Brand colour')),
                        FileUpload::make('logo_path')
                            ->label(__('Logo'))
                            ->helperText(__('Printed at the top of contracts and invoices. PNG, JPG or SVG, up to 2 MB.'))
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
                            ->maxSize(2048)
                            ->disk(fn (): string => (string) config('dealer.documents.disk'))
                            ->directory(fn (): string => 'tenants/'.$this->tenant->getKey().'/branding')
                            ->visibility('private')
                            ->columnSpanFull(),
                    ]),
                ]),
            Section::make(__('Security'))
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('settings.security.require_mfa_for_all')
                            ->label(__('Require two-factor authentication for all users'))
                            ->helperText(__('Administrators and accounting always need it.')),
                        TextInput::make('settings.security.idle_timeout_minutes')
                            ->label(__('Automatic sign-out after inactivity (minutes)'))
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(480)
                            ->placeholder((string) Tenant::DEFAULT_IDLE_TIMEOUT_MINUTES)
                            ->default(Tenant::DEFAULT_IDLE_TIMEOUT_MINUTES),
                    ]),
                ]),
        ]);
    }

    /**
     * Keep settings that are not on this form (other modules store theirs in the same column).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenant;

        $data['settings'] = array_replace_recursive($tenant->settings ?? [], $data['settings'] ?? []);

        return $data;
    }

    /**
     * Reload so a changed default language applies to this very screen.
     */
    protected function getRedirectUrl(): ?string
    {
        return static::getUrl();
    }
}
