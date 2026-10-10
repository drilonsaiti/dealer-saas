<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Portal\Actions\IssuePortalLink;
use App\Domain\Portal\Models\PortalLink;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\EmailMessages\EmailMessageResource;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The buyer's customer portal: create (or renew) the personal link, optionally as an e-mail
 * draft to the buyer; switch it off.
 */
final class PortalActions
{
    public static function issue(): Action
    {
        return Action::make('portal')
            ->label(fn (StockCycle $record): string => self::active($record) === null ? __('Customer portal') : __('Customer portal: new link'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null
                && ! in_array($record->activeSale->status, [SaleStatus::Draft, SaleStatus::Cancelled], true)
                && (auth()->user()?->can('update', $record) ?? false))
            ->modalDescription(fn (StockCycle $record): string => (self::active($record) === null ? '' : __('The current link stops working.').' ')
                .__('The buyer sees status, contract, invoices, warranty and can upload documents. The link is valid for a year.'))
            ->schema([
                Toggle::make('email')->label(__('Prepare an e-mail with the link to the buyer'))->default(true),
            ])
            ->action(function (StockCycle $record, array $data, Action $action): void {
                try {
                    $issue = app(IssuePortalLink::class);
                    [, $url] = $issue($record->activeSale);
                    $draft = ($data['email'] ?? false) ? $issue->draftEmail($record->activeSale, $url) : null;
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title($draft !== null ? __('Portal link created; the e-mail is ready as a draft in the inbox.') : __('Portal link created. Copy it now; it is shown only once.'))
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->actions($draft === null ? [] : [Action::make('open')->label(__('Open draft'))->url(EmailMessageResource::getUrl('view', ['record' => $draft]))])
                    ->send();
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revokePortal')
            ->label(__('Switch off customer portal'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (StockCycle $record): bool => self::active($record) !== null && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->action(function (StockCycle $record): void {
                app(IssuePortalLink::class)->revoke($record->activeSale);
                Notification::make()->title(__('Customer portal switched off.'))->success()->send();
            });
    }

    private static function active(StockCycle $record): ?PortalLink
    {
        return $record->activeSale === null ? null : PortalLink::query()->where('sale_id', $record->activeSale->getKey())->whereNull('revoked_at')->where('expires_at', '>', now())->first();
    }
}
