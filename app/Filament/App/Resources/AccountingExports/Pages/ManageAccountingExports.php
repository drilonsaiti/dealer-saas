<?php

namespace App\Filament\App\Resources\AccountingExports\Pages;

use App\Domain\Accounting\Actions\CreateAccountingExport;
use App\Domain\Accounting\Actions\SaveAccountMappings;
use App\Domain\Accounting\Models\AccountingExport;
use App\Domain\Accounting\Support\AccountChart;
use App\Filament\App\Resources\AccountingExports\AccountingExportResource;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ManageAccountingExports extends ManageRecords
{
    protected static string $resource = AccountingExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('New export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => auth()->user()?->can('create', AccountingExport::class) ?? false)
                ->schema([
                    DatePicker::make('until')->label(__('Up to and including'))->required()->live()
                        ->default(now()->subMonthNoOverflow()->endOfMonth())->maxDate(now()),
                    Text::make(fn (Get $get): string => self::pendingText((string) ($get('until') ?: now()->toDateString()))),
                ])
                ->modalSubmitActionLabel(__('Export'))
                ->action(function (array $data, Action $action): void {
                    try {
                        $export = app(CreateAccountingExport::class)((string) $data['until']);
                        Notification::make()->title(__('Export :number created: :count bookings.', ['number' => $export->number, 'count' => $export->entries_count]))->success()->send();
                    } catch (BusinessRuleException $e) {
                        Notification::make()->title($e->getMessage())->warning()->send();
                        $action->halt();
                    }
                }),
            Action::make('accounts')
                ->label(__('Accounts'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('create', AccountingExport::class) ?? false)
                ->modalWidth('3xl')
                ->modalDescription(__('Account numbers of your chart of accounts. Empty fields use the Swiss SME default shown in grey.'))
                ->fillForm(fn (): array => ['accounts' => collect((new AccountChart)->rows())->mapWithKeys(fn (array $row): array => [self::field($row['key']) => $row['account'] === $row['default'] ? null : $row['account']])->all()])
                ->schema(fn (): array => [
                    Grid::make(2)->schema(array_map(
                        fn (array $row): TextInput => TextInput::make('accounts.'.self::field($row['key']))->label($row['label'])->placeholder($row['default'])->maxLength(20),
                        (new AccountChart)->rows(),
                    )),
                ])
                ->action(function (array $data, Action $action): void {
                    $accounts = [];

                    foreach ((new AccountChart)->rows() as $row) {
                        $accounts[$row['key']] = $data['accounts'][self::field($row['key'])] ?? null;
                    }

                    try {
                        app(SaveAccountMappings::class)($accounts);
                        Notification::make()->title(__('Saved.'))->success()->send();
                    } catch (BusinessRuleException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }
                }),
        ];
    }

    /**
     * Form field names cannot contain ":" or "."; keys like "cost:repair" are encoded.
     */
    private static function field(string $key): string
    {
        return str_replace([':', '-'], ['__', '_'], $key);
    }

    private static function pendingText(string $until): string
    {
        $pending = app(CreateAccountingExport::class)->pending(Carbon::parse($until));

        return __('Not exported yet: :invoices invoices / credit notes, :payments payments, :purchases purchases, :costs costs.', [
            'invoices' => $pending['invoice'], 'payments' => $pending['payment'], 'purchases' => $pending['purchase'], 'costs' => $pending['cost'],
        ]);
    }
}
