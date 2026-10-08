<?php

namespace App\Filament\App\Resources\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Signatures\Actions\CancelSigning;
use App\Domain\Signatures\Actions\RecordPaperSignature;
use App\Domain\Signatures\Actions\StartSigning;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Filament\App\Resources\Documents\Pages\SignDocument;
use App\Models\User;
use App\Support\BusinessRuleException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Signing of finalised contracts: start (here on the device or by link), continue on the
 * device, send the link again, withdraw, or file a contract signed on paper.
 */
final class SigningActions
{
    public static function start(): Action
    {
        return Action::make('startSigning')
            ->label(__('Sign'))
            ->icon(Heroicon::OutlinedPencil)
            ->color('primary')
            ->visible(fn (Document $record): bool => $record->type_key !== null && $record->status === DocumentStatus::Final && self::canSign($record))
            ->modalHeading(fn (Document $record): string => __('Sign :document', ['document' => $record->title]))
            ->modalSubmitActionLabel(__('Continue'))
            ->fillForm(function (Document $record): array {
                $party = $record->parties()->first();

                return [
                    'method' => SigningMethod::OnDevice->value,
                    'name' => $party?->displayName(),
                    'email' => $party?->email,
                    'phone' => $party?->mobile ?: $party?->phone,
                    'locale' => $record->locale,
                ];
            })
            ->schema([
                Radio::make('method')
                    ->label(__('How does the customer sign?'))
                    ->options([
                        SigningMethod::OnDevice->value => __('Here on this device (iPad or PC), with an ID check'),
                        SigningMethod::Link->value => __('By link: the customer gets an email and confirms with a one-time code'),
                    ])
                    ->live()
                    ->required(),
                Grid::make(2)->schema([
                    TextInput::make('name')->label(__('Customer who signs'))->required()->maxLength(255),
                    Select::make('locale')->label(__('Language'))->options((array) config('dealer.locale_names'))->required(),
                    TextInput::make('email')->label(__('Email'))->email()->maxLength(255)
                        ->required(fn (Get $get): bool => $get('method') === SigningMethod::Link->value),
                    TextInput::make('phone')->label(__('Mobile'))->tel()->maxLength(40)
                        ->required(fn (Get $get): bool => $get('method') === SigningMethod::Link->value && config('dealer.signatures.code_channel') === 'sms'),
                ]),
            ])
            ->action(function (Document $record, array $data, Action $action): void {
                $method = SigningMethod::from((string) $data['method']);

                self::run($action, fn () => app(StartSigning::class)($record, $method, $data, self::user()));

                if ($method === SigningMethod::OnDevice) {
                    $action->redirect(SignDocument::getUrl(['record' => $record]));

                    return;
                }

                Notification::make()->title(__('The signing link was sent to :email.', ['email' => $data['email']]))->success()->send();
            });
    }

    /**
     * Next signer on this device: the customer in the showroom, or the dealer's countersignature.
     */
    public static function continueHere(): Action
    {
        return Action::make('continueSigning')
            ->label(fn (Document $record): string => self::openRequest($record)?->nextSigner()?->role->value === 'dealer' ? __('Countersign') : __('Sign here'))
            ->icon(Heroicon::OutlinedPencil)
            ->color('primary')
            ->visible(function (Document $record): bool {
                $next = self::openRequest($record)?->nextSigner();

                return $next !== null && $next->method === SigningMethod::OnDevice && self::canSign($record);
            })
            ->url(fn (Document $record): string => SignDocument::getUrl(['record' => $record]));
    }

    public static function resendLink(): Action
    {
        return Action::make('resendSigningLink')
            ->label(__('Send link again'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(function (Document $record): bool {
                $next = self::openRequest($record)?->nextSigner();

                return $next !== null && $next->method === SigningMethod::Link && self::canSign($record);
            })
            ->requiresConfirmation()
            ->modalDescription(__('A new link is sent; the previous link stops working.'))
            ->action(function (Document $record, Action $action): void {
                $signer = self::openRequest($record)?->nextSigner();

                if ($signer !== null) {
                    self::run($action, fn () => app(StartSigning::class)->resendLink($signer));
                    Notification::make()->title(__('The signing link was sent to :email.', ['email' => $signer->email]))->success()->send();
                }
            });
    }

    public static function withdraw(): Action
    {
        return Action::make('withdrawSigning')
            ->label(__('Withdraw signing'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Document $record): bool => $record->status === DocumentStatus::OutForSignature && self::canSign($record))
            ->schema([TextInput::make('reason')->label(__('Reason'))->required()->maxLength(255)])
            ->action(function (Document $record, array $data, Action $action): void {
                $request = self::openRequest($record, includeExpired: true);

                if ($request !== null) {
                    self::run($action, fn () => app(CancelSigning::class)($request, (string) $data['reason']));
                }

                Notification::make()->title(__('Signing withdrawn. The contract can be changed or sent again.'))->success()->send();
            });
    }

    public static function paper(): Action
    {
        return Action::make('signedOnPaper')
            ->label(__('Signed on paper'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (Document $record): bool => $record->type_key !== null
                && in_array($record->status, [DocumentStatus::Final, DocumentStatus::OutForSignature], true)
                && self::canSign($record))
            ->modalDescription(__('Print the contract, have it signed, and upload the scan. It becomes the signed version.'))
            ->schema([
                FileUpload::make('file')->label(__('Scan of the signed contract'))->storeFiles(false)->maxSize((int) config('dealer.documents.max_upload_kb'))->required(),
            ])
            ->action(function (Document $record, array $data, Action $action): void {
                $file = $data['file'];

                if ($file instanceof TemporaryUploadedFile) {
                    self::run($action, fn () => app(RecordPaperSignature::class)($record, $file->getRealPath(), $file->getClientOriginalName()));
                    Notification::make()->title(__('The signed contract is filed.'))->success()->send();
                }
            });
    }

    public static function openRequest(Document $document, bool $includeExpired = false): ?SignatureRequest
    {
        $request = SignatureRequest::query()->pending()->with('signers')->where('document_id', $document->getKey())->latest()->first();

        return $request !== null && ($includeExpired || $request->isOpen()) ? $request : null;
    }

    private static function canSign(Document $document): bool
    {
        return auth()->user()?->can('update', $document) ?? false;
    }

    private static function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }
}
