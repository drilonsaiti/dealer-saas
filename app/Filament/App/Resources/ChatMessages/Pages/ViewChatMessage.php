<?php

namespace App\Filament\App\Resources\ChatMessages\Pages;

use App\Domain\Chat\Actions\SendWhatsApp;
use App\Domain\Chat\Models\ChatMessage;
use App\Filament\App\Resources\ChatMessages\ChatMessageResource;
use App\Filament\App\Resources\Costs\CostResource;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property ChatMessage $record
 */
class ViewChatMessage extends ViewRecord
{
    protected static string $resource = ChatMessageResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        ChatMessage::query()->where('phone', $this->record->phone)->where('direction', ChatMessage::IN)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function getTitle(): string
    {
        return $this->record->contactLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label(__('Reply'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => auth()->user()?->can('create', ChatMessage::class) ?? false)
                ->disabled(fn (): bool => ! SendWhatsApp::windowOpen($this->record->phone))
                ->tooltip(fn (): ?string => SendWhatsApp::windowOpen($this->record->phone) ? null : __('More than 24 hours since the customer\'s last message.'))
                ->schema([Textarea::make('body')->label(__('Message'))->rows(5)->required()->maxLength(4000)])
                ->modalSubmitActionLabel(__('Send'))
                ->action(function (array $data, Action $action): void {
                    try {
                        app(SendWhatsApp::class)($this->record->phone, (string) $data['body'], $this->record);
                        Notification::make()->title(__('Message sent.'))->success()->send();
                    } catch (BusinessRuleException $e) {
                        Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                        $action->halt();
                    }
                }),
            Action::make('assign')
                ->label(__('Assign'))
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('update', $this->record) ?? false)
                ->fillForm(fn (): array => ['party_id' => $this->record->party_id, 'stock_cycle_id' => $this->record->stock_cycle_id])
                ->schema([
                    PartySelect::make('party_id')->label(__('Contact')),
                    Select::make('stock_cycle_id')->label(__('Vehicle file'))->options(fn (): array => CostResource::cycleOptions())->searchable(),
                ])
                ->action(function (array $data): void {
                    // The whole conversation belongs to the contact / file from now on.
                    ChatMessage::query()->where('phone', $this->record->phone)->update([
                        'party_id' => $data['party_id'] ?? null,
                        'stock_cycle_id' => $data['stock_cycle_id'] ?? null,
                    ]);
                    $this->record->refresh();
                    Notification::make()->title(__('Saved.'))->success()->send();
                }),
        ];
    }
}
