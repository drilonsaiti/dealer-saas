<?php

namespace App\Filament\App\Resources\EmailMessages\Pages;

use App\Domain\Inbox\Models\EmailMessage;
use App\Filament\App\Resources\EmailMessages\EmailActions;
use App\Filament\App\Resources\EmailMessages\EmailMessageResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property EmailMessage $record
 */
class ViewEmailMessage extends ViewRecord
{
    protected static string $resource = EmailMessageResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->direction === EmailMessage::IN && $this->record->read_at === null) {
            $this->record->forceFill(['read_at' => now()])->save();
        }
    }

    public function getTitle(): string
    {
        return (string) ($this->record->subject ?: __('(no subject)'));
    }

    protected function getHeaderActions(): array
    {
        return [
            EmailActions::reply(),
            EmailActions::editDraft(),
            EmailActions::send(),
            EmailActions::assign(),
            EmailActions::handled(),
        ];
    }
}
