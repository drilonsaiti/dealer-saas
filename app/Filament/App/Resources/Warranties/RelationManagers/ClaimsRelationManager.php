<?php

namespace App\Filament\App\Resources\Warranties\RelationManagers;

use App\Domain\Documents\Models\Document;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Warranty\Actions\AttachClaimDocuments;
use App\Domain\Warranty\Actions\HandleWarrantyClaim;
use App\Domain\Warranty\Actions\SendToWarrantyProvider;
use App\Domain\Warranty\Enums\ClaimStatus;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Filament\App\Resources\Warranties\WarrantyResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Warranty cases: report → decide (who pays what) → settle (dealer share becomes a cost of
 * the vehicle file).
 */
class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Warranty claims');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['workshop', 'warranty']))
            ->columns([
                TextColumn::make('occurred_on')->label(__('Date'))->date()->sortable(),
                TextColumn::make('mileage')->label(__('Mileage'))->numeric(thousandsSeparator: '’')->suffix(' km'),
                TextColumn::make('description')->label(__('What happened'))->wrap()->limit(80)
                    ->description(fn (WarrantyClaim $record): ?string => ($outside = app(HandleWarrantyClaim::class)->outsideCover($record)) === [] ? $record->workshop?->displayName() : '⚠ '.implode(' ', $outside)),
                TextColumn::make('amount_rp')->label(__('Amount'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('dealer_share_rp')->label(__('Dealer share'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->headerActions([
                Action::make('report')
                    ->label(__('Report claim'))
                    ->icon(Heroicon::OutlinedExclamationCircle)
                    ->visible(fn (): bool => $this->warranty()->status !== WarrantyStatus::Draft && (auth()->user()?->can('update', $this->warranty()) ?? false))
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('occurred_on')->label(__('Date'))->default(now())->required(),
                            TextInput::make('mileage')->label(__('Mileage'))->integer()->minValue(0)->suffix('km')->required(),
                        ]),
                        Textarea::make('description')->label(__('What happened'))->rows(3)->required(),
                        PartySelect::make('workshop_party_id', [PartyRole::Workshop])->label(__('Workshop')),
                    ])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn () => app(HandleWarrantyClaim::class)->report($this->warranty(), $data), __('Claim recorded.'))),
            ])
            ->recordActions([
                Action::make('documents')
                    ->label(fn (WarrantyClaim $record): string => __('Documents (:count)', ['count' => Document::query()->linkedTo($record)->count()]))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->color('gray')
                    ->visible(fn (WarrantyClaim $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->modalDescription(__('Photos, diagnosis and workshop invoices. They are filed in the vehicle file and go to the provider with the claim report.'))
                    ->schema([
                        FileUpload::make('files')->label(__('Files'))->multiple()->required()->storeFiles(false)
                            ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg', 'image/heic'])
                            ->maxSize((int) config('dealer.documents.max_upload_kb')),
                    ])
                    ->action(fn (WarrantyClaim $record, array $data, Action $action) => $this->run($action, function () use ($record, $data): void {
                        app(AttachClaimDocuments::class)($record, array_values(array_map(
                            fn (TemporaryUploadedFile $file): array => [(string) $file->getRealPath(), $file->getClientOriginalName()],
                            array_filter((array) $data['files'], fn ($f): bool => $f instanceof TemporaryUploadedFile),
                        )));
                    }, __('Documents saved.'))),
                Action::make('sendToProvider')
                    ->label(fn (WarrantyClaim $record): string => $record->reported_at === null ? __('Send to provider') : __('Send to provider again'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->visible(fn (WarrantyClaim $record): bool => SendToWarrantyProvider::supports($record->warranty->product) && (auth()->user()?->can('update', $record) ?? false))
                    ->tooltip(fn (WarrantyClaim $record): ?string => $record->reported_at === null ? null : WarrantyResource::emailState($record->reported_at, $record->report_email_id))
                    ->requiresConfirmation()
                    ->modalDescription(__('A claim report (PDF) with the claim documents is prepared as an e-mail in the inbox. Nothing is sent before you click "Send" there.'))
                    ->action(fn (WarrantyClaim $record, Action $action) => $this->run($action, function () use ($record): void {
                        app(SendToWarrantyProvider::class)->claim($record);
                    }, __('The claim report is ready as a draft in the inbox. Check it and click "Send".'))),
                Action::make('decide')
                    ->label(__('Decide'))
                    ->icon(Heroicon::OutlinedScale)
                    ->visible(fn (WarrantyClaim $record): bool => in_array($record->status, [ClaimStatus::Open, ClaimStatus::Approved], true) && (auth()->user()?->can('update', $record) ?? false))
                    ->fillForm(fn (WarrantyClaim $record): array => [
                        'diagnosis' => $record->diagnosis,
                        'amount_rp' => $record->amount_rp ?: null,
                        'deductible_rp' => $record->deductible_rp ?: $record->warranty->deductible_rp,
                        'provider_share_rp' => $record->provider_share_rp,
                        'dealer_share_rp' => $record->dealer_share_rp,
                    ])
                    ->modalDescription(fn (WarrantyClaim $record): ?string => ($outside = app(HandleWarrantyClaim::class)->outsideCover($record)) === [] ? null : __('Outside the cover:').' '.implode(' ', $outside))
                    ->schema([
                        Textarea::make('diagnosis')->label(__('Diagnosis'))->rows(2),
                        Grid::make(2)->schema([
                            MoneyInput::make('amount_rp')->label(__('Repair amount'))->required(),
                            MoneyInput::make('deductible_rp')->label(__('Deductible (customer)'))->required(),
                            MoneyInput::make('provider_share_rp')->label(__('Provider pays'))->required(),
                            MoneyInput::make('dealer_share_rp')->label(__('Dealer pays'))->required(),
                        ]),
                    ])
                    ->extraModalFooterActions(fn (WarrantyClaim $record): array => [
                        Action::make('reject')->label(__('Reject claim'))->color('danger')->requiresConfirmation()
                            ->action(fn () => app(HandleWarrantyClaim::class)->decide($record, null))
                            ->cancelParentActions(),
                    ])
                    ->action(fn (WarrantyClaim $record, array $data, Action $action) => $this->run($action, fn () => app(HandleWarrantyClaim::class)->decide($record, [
                        'amount_rp' => (int) $data['amount_rp'],
                        'deductible_rp' => (int) $data['deductible_rp'],
                        'provider_share_rp' => (int) $data['provider_share_rp'],
                        'dealer_share_rp' => (int) $data['dealer_share_rp'],
                    ], $data['diagnosis'] ?? null), __('Decision saved.'))),
                Action::make('settle')
                    ->label(__('Settle'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (WarrantyClaim $record): bool => $record->status === ClaimStatus::Approved && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (WarrantyClaim $record): string => $record->dealer_share_rp > 0
                        ? __('The dealer share of :amount is booked as a cost on the vehicle file.', ['amount' => Money::format($record->dealer_share_rp)])
                        : __('The provider pays everything; no cost for the dealer.'))
                    ->action(fn (WarrantyClaim $record, Action $action) => $this->run($action, fn () => app(HandleWarrantyClaim::class)->settle($record), __('Claim settled.'))),
            ])
            ->paginated(false);
    }

    private function warranty(): Warranty
    {
        /** @var Warranty $warranty */
        $warranty = $this->getOwnerRecord();

        return $warranty;
    }

    private function run(Action $action, Closure $callback, string $success): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($success)->success()->send();
    }
}
