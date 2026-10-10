<?php

namespace App\Filament\App\Resources\EmailMessages;

use App\Domain\Documents\Models\Document;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Inbox\Models\Mailbox;
use App\Filament\App\Resources\EmailMessages\Pages\ListEmailMessages;
use App\Filament\App\Resources\EmailMessages\Pages\ViewEmailMessage;
use App\Filament\App\Resources\Parties\PartyResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inbox: e-mails of the dealer's mailboxes, assigned to contacts and vehicle files, with
 * replies as drafts that leave only on "Send".
 *
 * @extends resource<EmailMessage>
 */
class EmailMessageResource extends Resource
{
    protected static ?string $model = EmailMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'inbox';

    public static function getModelLabel(): string
    {
        return __('E-mail');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Inbox');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = EmailMessage::query()->incoming()->whereNull('read_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('from')->label(__('From'))->state(fn (EmailMessage $record): string => $record->sender()),
                TextEntry::make('to')->label(__('To'))->state(fn (EmailMessage $record): string => implode(', ', array_column($record->to ?? [], 'email')) ?: '–'),
                TextEntry::make('sent_at')->label(__('Date'))->dateTime()->placeholder('–'),
                TextEntry::make('party')->label(__('Contact'))
                    ->state(fn (EmailMessage $record): ?string => $record->party?->displayName())
                    ->url(fn (EmailMessage $record): ?string => $record->party === null ? null : PartyResource::getUrl('edit', ['record' => $record->party]))
                    ->placeholder(__('not assigned')),
                TextEntry::make('stockCycle')->label(__('Vehicle file'))
                    ->state(fn (EmailMessage $record): ?string => $record->stockCycle?->title())
                    ->url(fn (EmailMessage $record): ?string => $record->stockCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->stockCycle]))
                    ->placeholder(__('not assigned'))
                    ->helperText(fn (EmailMessage $record): ?string => self::matchedBy($record->matched_by)),
                TextEntry::make('status')->label(__('Status'))->badge()
                    ->helperText(fn (EmailMessage $record): ?string => $record->handled_at !== null ? __('handled :date', ['date' => $record->handled_at->format('d.m.Y H:i')]) : $record->error),
                TextEntry::make('subject')->label(__('Subject'))->columnSpanFull()->weight('bold')->placeholder('–'),
            ]),
            Section::make(__('Attachments'))
                ->visible(fn (EmailMessage $record): bool => $record->attachments()->isNotEmpty() || ($record->quarantined ?? []) !== [])
                ->schema([
                    TextEntry::make('attachments')->hiddenLabel()
                        ->state(fn (EmailMessage $record): array => $record->attachments()->map(fn (Document $d): string => $d->currentVersion->original_name ?? $d->title)->all())
                        ->listWithLineBreaks()->bulleted()->placeholder('–'),
                    TextEntry::make('quarantined')->label(__('Not accepted (quarantine)'))->color('danger')
                        ->visible(fn (EmailMessage $record): bool => ($record->quarantined ?? []) !== [])
                        ->state(fn (EmailMessage $record): array => array_map(fn (array $q): string => $q['name'].' – '.$q['reason'], $record->quarantined ?? []))
                        ->listWithLineBreaks(),
                ]),
            TextEntry::make('body_text')->hiddenLabel()->columnSpanFull()
                ->extraAttributes(['style' => 'white-space:pre-line; line-height:1.5'])
                ->placeholder(__('(no text)')),
        ]);
    }

    public static function matchedBy(?string $by): ?string
    {
        return match ($by) {
            'vin' => __('found by the VIN'),
            'stammnummer' => __('found by the Stammnummer'),
            'file_number' => __('found by the file number'),
            'plate' => __('found by the plate'),
            'contact' => __('found by the sender'),
            'thread' => __('reply to an earlier e-mail'),
            'manual' => __('assigned by hand'),
            default => null,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['party', 'stockCycle.vehicle']))
            ->columns([
                IconColumn::make('read_at')->label('')->state(fn (EmailMessage $record): bool => $record->direction === EmailMessage::OUT || $record->read_at !== null)
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedEnvelopeOpen : Heroicon::Envelope)
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                TextColumn::make('sent_at')->label(__('Date'))->since()->sortable()
                    ->description(fn (EmailMessage $record): string => $record->sent_at?->format('d.m.Y H:i') ?? $record->created_at->format('d.m.Y H:i')),
                TextColumn::make('from_address')->label(__('From / to'))
                    ->state(fn (EmailMessage $record): string => $record->direction === EmailMessage::IN ? $record->sender() : '→ '.implode(', ', array_column($record->to ?? [], 'email')))
                    ->searchable(['from_address', 'from_name'])->limit(40),
                TextColumn::make('subject')->label(__('Subject'))->searchable()->limit(70)->wrap()
                    ->weight(fn (EmailMessage $record): ?string => $record->direction === EmailMessage::IN && $record->read_at === null ? 'bold' : null)
                    ->description(fn (EmailMessage $record): ?string => ($record->quarantined ?? []) !== [] ? __(':count attachment(s) in quarantine', ['count' => count($record->quarantined ?? [])]) : null),
                TextColumn::make('stockCycle')->label(__('Vehicle file'))->state(fn (EmailMessage $record): ?string => $record->stockCycle?->title())
                    ->description(fn (EmailMessage $record): ?string => $record->party?->displayName())->placeholder(__('not assigned'))->limit(40),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (EmailMessage $record): string => $record->handled_at !== null ? __('Handled') : $record->status->getLabel())
                    ->color(fn (EmailMessage $record): string => $record->handled_at !== null ? 'gray' : $record->status->getColor()),
            ])
            ->defaultSort('sent_at', 'desc')
            ->filters([
                SelectFilter::make('view')->label(__('Show'))
                    ->options(['open' => __('Open'), 'unassigned' => __('Not assigned'), 'drafts' => __('Drafts'), 'sent' => __('Sent'), 'all' => __('All')])
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'open') {
                        'unassigned' => $query->where('direction', EmailMessage::IN)->whereNull('stock_cycle_id'),
                        'drafts' => $query->where('direction', EmailMessage::OUT)->whereIn('status', [EmailStatus::Draft->value, EmailStatus::Failed->value]),
                        'sent' => $query->where('direction', EmailMessage::OUT)->where('status', EmailStatus::Sent->value),
                        'all' => $query,
                        default => $query->where('direction', EmailMessage::IN)->whereNull('handled_at'),
                    }),
                SelectFilter::make('mailbox_id')->label(__('Mailbox'))->options(fn (): array => Mailbox::query()->pluck('email', 'id')->all()),
                Filter::make('with_attachments')->label(__('With quarantined attachments'))->query(fn (Builder $query) => $query->whereNotNull('quarantined')),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()->label(__('Discard draft')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailMessages::route('/'),
            'view' => ViewEmailMessage::route('/{record}'),
        ];
    }
}
