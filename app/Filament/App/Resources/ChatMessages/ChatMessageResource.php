<?php

namespace App\Filament\App\Resources\ChatMessages;

use App\Domain\Chat\Actions\SendWhatsApp;
use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Filament\App\Resources\ChatMessages\Pages\ListChatMessages;
use App\Filament\App\Resources\ChatMessages\Pages\ViewChatMessage;
use App\Filament\App\Resources\Parties\PartyResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * WhatsApp: one row per customer conversation; the conversation page shows all messages,
 * the contact and vehicle file, and the reply box.
 *
 * @extends resource<ChatMessage>
 */
class ChatMessageResource extends Resource
{
    protected static ?string $model = ChatMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeftEllipsis;

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'whatsapp';

    public static function getNavigationLabel(): string
    {
        return 'WhatsApp';
    }

    public static function getModelLabel(): string
    {
        return __('WhatsApp conversation');
    }

    public static function getPluralModelLabel(): string
    {
        return 'WhatsApp';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && IntegrationAccount::query()->where('provider', IntegrationAccount::WHATSAPP)->exists();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ChatMessage::query()->where('direction', ChatMessage::IN)->whereNull('read_at')->distinct()->count('phone');

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('contact')->label(__('Contact'))
                    ->state(fn (ChatMessage $record): string => $record->contactLabel())
                    ->url(fn (ChatMessage $record): ?string => $record->party === null ? null : PartyResource::getUrl('edit', ['record' => $record->party]))
                    ->helperText(fn (ChatMessage $record): string => $record->phone),
                TextEntry::make('stockCycle')->label(__('Vehicle file'))
                    ->state(fn (ChatMessage $record): ?string => $record->stockCycle?->title())
                    ->url(fn (ChatMessage $record): ?string => $record->stockCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->stockCycle]))
                    ->placeholder(__('not assigned')),
                TextEntry::make('window')->label(__('Free reply possible'))
                    ->state(fn (ChatMessage $record): string => SendWhatsApp::windowOpen($record->phone) ? __('yes (customer wrote in the last 24 h)') : __('no – only templates; call or e-mail'))
                    ->color(fn (ChatMessage $record): string => SendWhatsApp::windowOpen($record->phone) ? 'success' : 'warning'),
            ]),
            ViewEntry::make('thread')->hiddenLabel()->columnSpanFull()
                ->view('filament.app.chat-thread')
                ->state(fn (ChatMessage $record) => ChatMessage::query()->with('document')->where('phone', $record->phone)->orderBy('created_at')->limit(300)->get()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw(ChatMessage::LATEST_PER_PHONE)->with(['party', 'stockCycle.vehicle']))
            ->columns([
                IconColumn::make('unread')->label('')
                    ->state(fn (ChatMessage $record): bool => ChatMessage::query()->where('phone', $record->phone)->where('direction', ChatMessage::IN)->whereNull('read_at')->exists())
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::ChatBubbleOvalLeftEllipsis : Heroicon::OutlinedChatBubbleOvalLeft)
                    ->color(fn (bool $state): string => $state ? 'primary' : 'gray'),
                TextColumn::make('contact')->label(__('Contact'))->state(fn (ChatMessage $record): string => $record->contactLabel())
                    ->description(fn (ChatMessage $record): string => $record->phone)
                    ->searchable(['phone', 'contact_name']),
                TextColumn::make('body')->label(__('Last message'))->limit(80)->wrap()
                    ->formatStateUsing(fn (?string $state, ChatMessage $record): string => ($record->direction === ChatMessage::OUT ? '→ ' : '').($state ?: ($record->document_id !== null ? __('(file)') : ''))),
                TextColumn::make('stockCycle')->label(__('Vehicle file'))->state(fn (ChatMessage $record): ?string => $record->stockCycle?->title())->placeholder(__('not assigned'))->limit(40),
                TextColumn::make('created_at')->label(__('Time'))->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (ChatMessage $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatMessages::route('/'),
            'view' => ViewChatMessage::route('/{record}'),
        ];
    }
}
