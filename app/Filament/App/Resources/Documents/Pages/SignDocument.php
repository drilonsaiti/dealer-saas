<?php

namespace App\Filament\App\Resources\Documents\Pages;

use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Documents\Models\Document;
use App\Domain\Signatures\Actions\RecordSignature;
use App\Domain\Signatures\Enums\SignerRole;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Domain\Signatures\Models\Signer;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Documents\DocumentResource;
use App\Filament\App\Resources\Documents\SigningActions;
use App\Filament\App\Resources\StockCycles\RelationManagers\DocumentsRelationManager;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Models\User;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Signing on this device (iPad in the showroom, or the dealer's PC): the signer reads the
 * whole contract, ticks "read and accepted" and signs in the field. For the customer the
 * salesperson first confirms the ID check. Customer first, then the dealer countersigns.
 */
class SignDocument extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DocumentResource::class;

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()?->can('update', $this->getRecord()) ?? false, 403);

        $signer = $this->signer();

        if ($signer === null || $signer->method !== SigningMethod::OnDevice) {
            Notification::make()->title(__('There is nothing to sign on this device for this document.'))->warning()->send();
            $this->redirect($this->backUrl());

            return;
        }

        $this->getSchema('form')?->fill([
            'place' => Filament::getTenant()?->getAttribute('city'),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        $signer = $this->signer();

        return $signer === null ? __('Sign') : __('Signature: :name', ['name' => $signer->name]);
    }

    public function getSubheading(): ?string
    {
        return $this->document()->title;
    }

    public function form(Schema $schema): Schema
    {
        $signer = $this->signer();
        $isCustomer = $signer?->role === SignerRole::Customer;

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('ID check'))
                    ->description(__('Check the customer’s official ID before they sign. Only the last three digits are printed on the evidence page.'))
                    ->visible($isCustomer)
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('doc_type')->label(__('ID document'))->options([
                                'id_card' => __('ID card'),
                                'passport' => __('Passport'),
                                'driving_licence' => __('Driving licence'),
                                'residence_permit' => __('Residence permit'),
                            ])->required($isCustomer)->native(false),
                            TextInput::make('doc_number')->label(__('Document number'))->required($isCustomer)->maxLength(40),
                        ]),
                        Checkbox::make('id_checked')->label(__('I have checked the ID and it matches the person signing.'))->accepted($isCustomer),
                    ]),
                Section::make(__('Signature'))->schema([
                    Checkbox::make('accepted')
                        ->label($isCustomer
                            ? __(':name has read the whole document and accepts it.', ['name' => $signer->name])
                            : __('I have read the whole document and accept it.'))
                        ->accepted(),
                    TextInput::make('place')->label(__('Place'))->required()->maxLength(100),
                    ViewField::make('signature')->label(__('Signature'))->view('filament.forms.signature-pad')->required(),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $version = $this->document()->currentVersion;
        $html = $version === null ? '' : app(DocumentRenderer::class)->html((array) $version->data_snapshot);

        return $schema->components([
            Section::make(__('Document'))
                ->description(__('Read the whole document; scroll inside the frame.'))
                ->schema([
                    Html::make(new HtmlString('<iframe title="'.e(__('Document')).'" srcdoc="'.e($html).'" style="width:100%;height:60vh;border:1px solid rgb(0 0 0 / 0.1);border-radius:0.5rem;background:#fff"></iframe>')),
                ]),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('sign')
                ->footer([
                    Actions::make([
                        Action::make('sign')->label(__('Sign now'))->submit('sign')->size('lg'),
                        Action::make('back')->label(__('Cancel'))->color('gray')->url($this->backUrl()),
                    ]),
                ]),
        ]);
    }

    public function sign(): void
    {
        $data = $this->getSchema('form')?->getState() ?? [];
        $signer = $this->signer();
        $user = auth()->user();

        if ($signer === null || ! $user instanceof User) {
            return;
        }

        try {
            app(RecordSignature::class)(
                $signer,
                (string) ($data['signature'] ?? ''),
                (string) $data['place'],
                (bool) ($data['accepted'] ?? false),
                ['ip' => request()->ip(), 'user_agent' => request()->userAgent()],
                $signer->role === SignerRole::Customer ? ['doc_type' => $data['doc_type'] ?? null, 'doc_number' => $data['doc_number'] ?? null] : null,
                $user,
            );
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $next = $this->signer(fresh: true);

        if ($next === null) {
            Notification::make()->title(__('Signed by everyone. The signed contract is filed and was sent to the customer.'))->success()->send();
            $this->redirect($this->backUrl());

            return;
        }

        if ($next->method !== SigningMethod::OnDevice) {
            $this->redirect($this->backUrl());

            return;
        }

        Notification::make()->title(__(':name has signed. Now :next signs.', ['name' => $signer->name, 'next' => $next->name]))->success()->send();
        // Same page, next signer: fresh form.
        $this->redirect(static::getUrl(['record' => $this->getRecord()]));
    }

    private ?SignatureRequest $openRequest = null;

    private function signer(bool $fresh = false): ?Signer
    {
        if ($fresh || $this->openRequest === null) {
            $this->openRequest = SigningActions::openRequest($this->document());
        }

        return $this->openRequest?->nextSigner();
    }

    private function document(): Document
    {
        /** @var Document $document */
        $document = $this->getRecord();

        return $document;
    }

    private function backUrl(): string
    {
        $cycle = $this->document()->stockCycles()->first();

        if ($cycle instanceof StockCycle) {
            $tab = array_search(DocumentsRelationManager::class, StockCycleResource::getRelations(), true);

            return StockCycleResource::getUrl('view', ['record' => $cycle, 'relation' => (string) $tab]);
        }

        return DocumentResource::getUrl('index');
    }
}
