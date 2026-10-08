<?php

namespace App\Filament\App\Resources\DocumentTemplates;

use App\Domain\Documents\Actions\ActivateTemplate;
use App\Domain\Documents\Actions\InstallDefaultTemplates;
use App\Domain\Documents\Actions\SaveTemplateDraft;
use App\Domain\Documents\Enums\TemplateStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Filament\App\Resources\DocumentTemplates\Pages\ManageDocumentTemplates;
use App\Support\BusinessRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * Settings → Templates: the clauses and footer of generated documents in all four languages.
 * Changes are drafts (new version numbers); activating one retires the previous version,
 * documents keep the version they were made with.
 *
 * @extends resource<DocumentTemplate>
 */
class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'templates';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Templates');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label(__('Template version'))->prefix('v'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('valid_from')->label(__('Active since'))->date()->placeholder('–'),
                TextColumn::make('notes')->label(__('Note'))->state(fn (DocumentTemplate $record): ?string => $record->notes ?? ($record->version === 1 ? __('System default') : null))->placeholder('–')->wrap(),
                TextColumn::make('updated_at')->label(__('Changed'))->dateTime(),
            ])
            ->groups([Group::make('type_key')->label(__('Document'))->getTitleFromRecordUsing(fn (DocumentTemplate $record): string => $record->type_key->getLabel())])
            ->defaultGroup('type_key')
            ->defaultSort('version', 'desc')
            ->paginated(false)
            ->recordActions([
                self::editDraft(),
                self::activate(),
                self::show(),
            ]);
    }

    /**
     * New draft, prefilled with the active version of the chosen document.
     */
    public static function newVersion(): Action
    {
        return Action::make('newVersion')
            ->label(__('New version'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => auth()->user()?->can('create', DocumentTemplate::class) ?? false)
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn (): array => self::formData(app(InstallDefaultTemplates::class)->active(TemplateType::SalesContract)))
            ->schema([
                Select::make('type_key')
                    ->label(__('Document'))
                    ->options(TemplateType::class)
                    ->default(TemplateType::SalesContract->value)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $type = $state instanceof TemplateType ? $state : TemplateType::tryFrom((string) $state);

                        if ($type !== null) {
                            foreach (self::formData(app(InstallDefaultTemplates::class)->active($type)) as $key => $value) {
                                $set($key, $value);
                            }
                        }
                    })
                    ->required(),
                ...self::contentFields(),
            ])
            ->action(function (array $data): void {
                $type = $data['type_key'] instanceof TemplateType ? $data['type_key'] : TemplateType::from((string) $data['type_key']);
                $draft = app(SaveTemplateDraft::class)($type, (array) $data['clauses'], (array) ($data['footer'] ?? []), $data['notes'] ?? null);

                Notification::make()->title(__('Draft v:version saved. Activate it to use it for new documents.', ['version' => $draft->version]))->success()->send();
            });
    }

    private static function editDraft(): Action
    {
        return Action::make('edit')
            ->label(__('Edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (DocumentTemplate $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn (DocumentTemplate $record): array => self::formData($record))
            ->schema(self::contentFields())
            ->action(function (DocumentTemplate $record, array $data): void {
                app(SaveTemplateDraft::class)($record->type_key, (array) $data['clauses'], (array) ($data['footer'] ?? []), $data['notes'] ?? null, $record);
                Notification::make()->title(__('Saved.'))->success()->send();
            });
    }

    private static function activate(): Action
    {
        return Action::make('activate')
            ->label(__('Activate'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (DocumentTemplate $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(__('New documents of this type will use this version. Documents made earlier keep their version.'))
            ->action(function (DocumentTemplate $record, Action $action): void {
                try {
                    app(ActivateTemplate::class)($record);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title(__('Version :version is now active.', ['version' => $record->version]))->success()->send();
            });
    }

    private static function show(): Action
    {
        return Action::make('show')
            ->label(__('Show'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (DocumentTemplate $record): bool => $record->status !== TemplateStatus::Draft)
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn (DocumentTemplate $record): array => self::formData($record))
            ->schema(self::contentFields())
            ->disabledForm()
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /**
     * @return array<int, mixed>
     */
    private static function contentFields(): array
    {
        $names = (array) config('dealer.locale_names');

        return [
            Tabs::make('languages')->tabs(collect((array) config('dealer.locales'))
                ->map(fn (string $locale): Tab => Tab::make((string) ($names[$locale] ?? $locale))
                    ->schema([
                        Repeater::make("clauses.{$locale}")
                            ->label(__('Clauses'))
                            ->simple(Textarea::make('text')->rows(2)->required())
                            ->reorderable()
                            ->addActionLabel(__('Add clause'))
                            ->minItems(1),
                        Textarea::make("footer.{$locale}")
                            ->label(__('Footer'))
                            ->helperText(__('Optional text above the bank details, e.g. opening hours.'))
                            ->rows(2)
                            ->maxLength(500),
                    ]))
                ->all()),
            TextInput::make('notes')->label(__('Note'))->placeholder(__('What changed?'))->maxLength(255),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(DocumentTemplate $template): array
    {
        return [
            'type_key' => $template->type_key->value,
            'clauses' => collect((array) config('dealer.locales'))->mapWithKeys(fn (string $locale): array => [$locale => $template->clausesIn($locale)])->all(),
            'footer' => $template->footer ?? [],
            'notes' => null,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentTemplates::route('/'),
        ];
    }
}
