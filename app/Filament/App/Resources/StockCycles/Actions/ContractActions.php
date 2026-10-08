<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Documents\Actions\GenerateContract;
use App\Domain\Documents\Models\Document;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Schemas\StockCycleInfolist;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * The contract wizard (replaces the "Kaufverträge" desktop tool): 1. prepare (language,
 * remarks, check the data), 2. check the preview, then finalise: numbered PDF, locked, filed
 * in the vehicle file. Signing follows as its own step on the finalised contract.
 */
final class ContractActions
{
    public static function salesContract(): Action
    {
        return self::wizard('salesContract', fn (StockCycle $record): ?Sale => $record->activeSale)
            ->label(__('Sales contract'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null
                && in_array($record->activeSale->status->value, ['reserved', 'contracted', 'invoiced'], true)
                && (auth()->user()?->can('update', $record->activeSale) ?? false));
    }

    public static function purchaseContract(): Action
    {
        return self::wizard('purchaseContract', fn (StockCycle $record): ?Purchase => $record->purchase)
            ->label(__('Purchase contract'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->visible(fn (StockCycle $record): bool => $record->purchase !== null
                && (auth()->user()?->can('update', $record->purchase) ?? false));
    }

    /**
     * @param  callable(StockCycle): (Sale|Purchase|null)  $subject
     */
    private static function wizard(string $name, callable $subject): Action
    {
        $generator = fn (): GenerateContract => app(GenerateContract::class);

        return Action::make($name)
            ->color('gray')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel(__('Finalise contract'))
            ->fillForm(fn (StockCycle $record): array => [
                'locale' => self::defaultLocale($subject($record)),
                'remarks' => $subject($record) instanceof Sale ? $subject($record)->remarks : null,
            ])
            ->steps(fn (StockCycle $record): array => [
                Step::make(__('Prepare'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema(array_values(array_filter([
                        self::existingNotice($generator(), $subject($record)),
                        self::warnings($generator(), $subject($record)),
                        Select::make('locale')
                            ->label(__('Language of the contract'))
                            ->options((array) config('dealer.locale_names'))
                            ->helperText(__('Proposed from the customer’s language. The language is printed in the file name.'))
                            ->native(false)
                            ->required(),
                        Textarea::make('remarks')
                            ->label(__('Remarks'))
                            ->helperText(__('Open promises to the customer (Zusagen) are printed automatically; add only what is not recorded elsewhere.'))
                            ->rows(4)
                            ->maxLength(2000),
                    ]))),
                Step::make(__('Check'))
                    ->icon(Heroicon::OutlinedEye)
                    ->schema([
                        Html::make(function (Get $get) use ($generator, $subject, $record): HtmlString {
                            try {
                                $html = $generator()->preview($subject($record), (string) $get('locale'), $get('remarks'));
                            } catch (BusinessRuleException $e) {
                                return new HtmlString('<p>'.e($e->getMessage()).'</p>');
                            }

                            return new HtmlString('<iframe title="'.e(__('Preview')).'" srcdoc="'.e($html).'" style="width:100%;height:68vh;border:1px solid rgb(0 0 0 / 0.1);border-radius:0.5rem;background:#fff"></iframe>');
                        }),
                    ]),
            ])
            ->action(function (StockCycle $record, array $data, Action $action) use ($generator, $subject): void {
                try {
                    $document = $generator()($subject($record), (string) $data['locale'], $data['remarks'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $record->refresh();
                StockCycleInfolist::forget($record);
                self::notifyFinalised($document);
            });
    }

    private static function existingNotice(GenerateContract $generator, Sale|Purchase|null $subject): ?Callout
    {
        if ($subject === null) {
            return null;
        }

        $existing = $generator->existing(GenerateContract::typeFor($subject), $subject);

        if ($existing === null) {
            return null;
        }

        return Callout::make(__('Contract :number already exists', ['number' => $existing->number]))
            ->description($existing->status->isLocked()
                ? __('It is signed and can no longer be changed.')
                : __('Finalising again adds a new version with the same number; the previous version stays in the file.'))
            ->info();
    }

    private static function warnings(GenerateContract $generator, Sale|Purchase|null $subject): ?Callout
    {
        $warnings = $subject === null ? [] : $generator->warnings($subject);

        if ($warnings === []) {
            return null;
        }

        return Callout::make(__('Please check'))
            ->description(new HtmlString('<ul style="list-style:disc;padding-left:1.25rem">'.collect($warnings)->map(fn (string $w): string => '<li>'.e($w).'</li>')->implode('').'</ul>'))
            ->warning();
    }

    private static function defaultLocale(Sale|Purchase|null $subject): string
    {
        $party = match (true) {
            $subject instanceof Sale => $subject->buyer,
            $subject instanceof Purchase => $subject->seller,
            default => null,
        };
        $locale = $subject instanceof Sale ? $subject->locale : $party?->locale;

        return in_array($locale, (array) config('dealer.locales'), true)
            ? (string) $locale
            : (string) (Filament::getTenant()?->getAttribute('default_locale') ?? config('app.locale'));
    }

    private static function notifyFinalised(Document $document): void
    {
        $version = $document->currentVersion;

        Notification::make()
            ->title(__('Contract :number finalised', ['number' => $document->number]))
            ->body(__('Filed in the vehicle file (version :version).', ['version' => $version?->version_no]))
            ->success()
            ->actions($version === null ? [] : [
                Action::make('open')
                    ->label(__('Open document'))
                    ->url(Storage::disk($version->disk)->temporaryUrl($version->path, now()->addMinutes(30)), shouldOpenInNewTab: true),
            ])
            ->send();
    }
}
