<?php

namespace App\Filament\App\Resources\Mailboxes\Pages;

use App\Domain\Inbox\Actions\SaveMailbox;
use App\Domain\Inbox\Models\Mailbox;
use App\Filament\App\Resources\Mailboxes\MailboxResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageMailboxes extends ManageRecords
{
    protected static string $resource = MailboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('New mailbox'))
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => auth()->user()?->can('create', Mailbox::class) ?? false)
                ->schema(MailboxResource::fields(null))
                ->modalWidth('3xl')
                ->action(function (array $data): void {
                    app(SaveMailbox::class)(null, $data);
                    Notification::make()->title(__('Saved. Test the connection next.'))->success()->send();
                }),
        ];
    }
}
