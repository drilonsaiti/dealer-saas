<?php

namespace App\Filament\App\Resources\ChecklistTemplates;

use App\Domain\Checklists\Actions\SaveChecklistTemplate;
use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Checklists\Models\ChecklistTemplate;
use App\Domain\Checklists\Models\ChecklistTemplateItem;
use App\Domain\Checklists\Support\ChecklistRules;
use App\Domain\Parties\Enums\PartyRole;
use App\Filament\App\Resources\ChecklistTemplates\Pages\ManageChecklistTemplates;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settings → Checklists: the handover checklist and what each leasing bank needs. Items can
 * tick themselves from an automatic rule. Saving makes a new version.
 *
 * @extends resource<ChecklistTemplate>
 */
class ChecklistTemplateResource extends Resource
{
    protected static ?string $model = ChecklistTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 47;

    protected static ?string $slug = 'checklists';

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Checklist');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Checklists');
    }

    /**
     * @return list<Component|Field>
     */
    public static function fields(bool $new): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('kind')->label(__('Type'))->options(ChecklistKind::class)->required()->live()->visible($new),
                PartySelect::make('partner_party_id', [PartyRole::FinancingPartner])->label(__('Only for this bank'))
                    ->visible(fn (Get $get): bool => $new && $get('kind') === ChecklistKind::FinancingPartner->value),
                TextInput::make('name.de')->label(__('Name (German)'))->required()->maxLength(120),
                TextInput::make('name.fr')->label(__('Name (French)'))->maxLength(120),
            ]),
            Repeater::make('items')->label(__('Items'))
                ->schema([
                    Hidden::make('key'),
                    Grid::make(2)->schema([
                        TextInput::make('label.de')->label(__('Text (German)'))->required()->maxLength(200),
                        TextInput::make('label.fr')->label(__('Text (French)'))->maxLength(200),
                        TextInput::make('label.it')->label(__('Text (Italian)'))->maxLength(200),
                        TextInput::make('label.en')->label(__('Text (English)'))->maxLength(200),
                        Select::make('auto_rule')->label(__('Ticks itself when'))->options(ChecklistRules::options())->placeholder(__('ticked by hand')),
                        Toggle::make('required')->label(__('Required'))->default(true)->inline(false),
                    ]),
                ])
                ->reorderable()->collapsible()->defaultItems(1)
                ->itemLabel(fn (array $state): ?string => $state['label']['de'] ?? null)
                ->addActionLabel(__('Add item')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)->with('partner')->withCount('items'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->description(fn (ChecklistTemplate $record): ?string => $record->partner?->displayName()),
                TextColumn::make('kind')->label(__('Type')),
                TextColumn::make('items_count')->label(__('Items')),
                TextColumn::make('version')->label(__('Version')),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (ChecklistTemplate $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->modalDescription(__('Saving makes a new version; checklists already started keep their items.'))
                    ->fillForm(fn (ChecklistTemplate $record): array => [
                        'name' => $record->getTranslations('name'),
                        'items' => $record->items->map(fn (ChecklistTemplateItem $item): array => [
                            'key' => $item->key,
                            'label' => $item->getTranslations('label'),
                            'required' => $item->required,
                            'auto_rule' => $item->auto_rule,
                        ])->all(),
                    ])
                    ->schema(self::fields(false))
                    ->action(fn (ChecklistTemplate $record, array $data, Action $action) => self::save($record, $data, $action)),
            ])
            ->paginated(false);
    }

    public static function create(): CreateAction
    {
        return CreateAction::make()
            ->label(__('Checklist for a bank'))
            ->schema(self::fields(true))
            ->fillForm(['kind' => ChecklistKind::FinancingPartner->value])
            ->using(fn (array $data, Action $action): ChecklistTemplate => self::save(null, $data, $action));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function save(?ChecklistTemplate $previous, array $data, Action $action): ChecklistTemplate
    {
        try {
            $template = app(SaveChecklistTemplate::class)($previous, $data, array_values($data['items'] ?? []));
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return $previous ?? new ChecklistTemplate;
        }

        Notification::make()->title(__('Saved as version :version.', ['version' => $template->version]))->success()->send();

        return $template;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChecklistTemplates::route('/'),
        ];
    }
}
