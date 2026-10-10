<?php

namespace App\Filament\App\Resources\Warranties;

use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Warranty\Actions\AddWarranty;
use App\Domain\Warranty\Actions\RegisterWarranty;
use App\Domain\Warranty\Actions\SendToWarrantyProvider;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Filament\App\Resources\EmailMessages\EmailMessageResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Filament\App\Resources\Warranties\Pages\ListWarranties;
use App\Filament\App\Resources\Warranties\Pages\ViewWarranty;
use App\Filament\App\Resources\Warranties\RelationManagers\ClaimsRelationManager;
use App\Filament\Support\MoneyInput;
use App\Support\BusinessRuleException;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Warranties sold with cars: draft on the sale, active from handover, expiring, with claims.
 *
 * @extends resource<Warranty>
 */
class WarrantyResource extends Resource
{
    protected static ?string $model = Warranty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('Warranty');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Warranties');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Warranty::query()->expiringWithin(30)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Ending within 30 days');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Warranty $record): string => $record->product->name)
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('policy_number')->label(__('Policy number'))->placeholder(__('not registered yet')),
                        TextEntry::make('product.provider_party_id')->label(__('Provider'))
                            ->state(fn (Warranty $record): string => $record->product->provider?->displayName() ?? __('own warranty')),
                        TextEntry::make('stockCycle.number')->label(__('Vehicle file'))
                            ->state(fn (Warranty $record): string => $record->stockCycle->title())
                            ->url(fn (Warranty $record): string => StockCycleResource::getUrl('view', ['record' => $record->stockCycle])),
                        TextEntry::make('starts_on')->label(__('From'))->date()->placeholder(__('at handover')),
                        TextEntry::make('ends_on')->label(__('Until'))->date()->placeholder('–'),
                        TextEntry::make('km')->label(__('Until km'))
                            ->state(fn (Warranty $record): string => $record->kmUntil() !== null ? number_format($record->kmUntil(), 0, '.', '’').' km' : ($record->km_limit !== null ? '+'.number_format($record->km_limit, 0, '.', '’').' km' : '–')),
                        TextEntry::make('coverage_limit_rp')->label(__('Coverage limit'))->formatStateUsing(fn (?int $state): string => $state === null ? '–' : Money::format($state))->placeholder('–'),
                        TextEntry::make('price_rp')->label(__('Price to the customer'))->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('cost_rp')->label(__('Premium (your cost)'))->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('deductible_rp')->label(__('Deductible'))->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('certificate.title')->label(__('Certificate'))->placeholder('–'),
                        TextEntry::make('submitted_at')->label(__('Sent to provider'))
                            ->visible(fn (Warranty $record): bool => SendToWarrantyProvider::supports($record->product))
                            ->state(fn (Warranty $record): string => self::emailState($record->submitted_at, $record->submission_email_id))
                            ->url(fn (Warranty $record): ?string => $record->submission_email_id === null ? null : EmailMessageResource::getUrl('view', ['record' => $record->submission_email_id])),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.provider', 'stockCycle.vehicle', 'sale.buyer']))
            ->columns([
                TextColumn::make('product.name')->label(__('Product'))
                    ->description(fn (Warranty $record): string => $record->product->provider?->displayName() ?? __('own warranty')),
                TextColumn::make('stockCycle.number')->label(__('Vehicle'))->state(fn (Warranty $record): string => $record->stockCycle->title())
                    ->description(fn (Warranty $record): ?string => $record->sale?->buyer->displayName()),
                TextColumn::make('policy_number')->label(__('Policy number'))->placeholder('–')->searchable(),
                TextColumn::make('ends_on')->label(__('Until'))->date()->sortable()->placeholder('–'),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Warranty $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function register(): Action
    {
        return Action::make('register')
            ->label(fn (Warranty $record): string => $record->policy_number === null ? __('Register policy') : __('Update policy'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->visible(fn (Warranty $record): bool => in_array($record->status, [WarrantyStatus::Draft, WarrantyStatus::Active], true) && (auth()->user()?->can('update', $record) ?? false))
            ->fillForm(fn (Warranty $record): array => ['policy_number' => $record->policy_number, 'coverage_limit_rp' => $record->coverage_limit_rp])
            ->schema([
                TextInput::make('policy_number')->label(__('Policy number'))->required()->maxLength(60),
                MoneyInput::make('coverage_limit_rp')->label(__('Coverage limit'))->nullable(),
                FileUpload::make('certificate')->label(__('Certificate (PDF)'))
                    ->helperText(__('A changed policy becomes a new version of the same certificate.'))
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])->storeFiles(false)
                    ->maxSize((int) config('dealer.documents.max_upload_kb')),
            ])
            ->action(function (Warranty $record, array $data, Action $action): void {
                $file = $data['certificate'] ?? null;

                try {
                    app(RegisterWarranty::class)(
                        $record,
                        (string) $data['policy_number'],
                        $file instanceof TemporaryUploadedFile ? $file->getRealPath() : null,
                        $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : null,
                        ['coverage_limit_rp' => $data['coverage_limit_rp'] ?? $record->coverage_limit_rp],
                    );
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title(__('Policy registered.'))->success()->send();
            });
    }

    /**
     * "Draft prepared 10.10.2026" / "sent 11.10.2026" / "not yet", for a registration or claim e-mail.
     */
    public static function emailState(?Carbon $prepared, ?string $emailId): string
    {
        if ($prepared === null) {
            return __('not yet');
        }

        $email = $emailId === null ? null : EmailMessage::query()->find($emailId);

        return $email?->status === EmailStatus::Sent
            ? __('sent :date', ['date' => $email->sent_at?->format('d.m.Y H:i') ?? ''])
            : __('draft prepared :date – send it in the inbox', ['date' => $prepared->format('d.m.Y')]);
    }

    public static function sendToProvider(): Action
    {
        return Action::make('sendToProvider')
            ->label(__('Send to provider'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn (Warranty $record): bool => SendToWarrantyProvider::supports($record->product)
                && in_array($record->status, [WarrantyStatus::Draft, WarrantyStatus::Active], true)
                && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription(fn (Warranty $record): string => __('A registration form (PDF) is filed in the vehicle file and an e-mail to :address is prepared in the inbox. Nothing is sent before you click "Send" there.', ['address' => $record->product->submissionAddress() ?? '–']))
            ->action(function (Warranty $record, Action $action): void {
                try {
                    $message = app(SendToWarrantyProvider::class)->warranty($record);
                    Notification::make()->title($message)->success()
                        ->actions([Action::make('open')->label(__('Open draft'))->url(EmailMessageResource::getUrl('view', ['record' => $record->refresh()->submission_email_id]))])
                        ->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    $action->halt();
                }
            });
    }

    public static function remove(): Action
    {
        return Action::make('remove')
            ->label(__('Remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Warranty $record): bool => $record->status === WarrantyStatus::Draft && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('The price is removed from the sale and the premium from the costs.'))
            ->action(function (Warranty $record, Action $action): void {
                try {
                    app(AddWarranty::class)->remove($record);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    public static function getRelations(): array
    {
        return [ClaimsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarranties::route('/'),
            'view' => ViewWarranty::route('/{record}'),
        ];
    }
}
