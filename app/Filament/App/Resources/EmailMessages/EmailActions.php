<?php

namespace App\Filament\App\Resources\EmailMessages;

use App\Domain\Documents\Models\Document;
use App\Domain\Inbox\Actions\AssignEmail;
use App\Domain\Inbox\Actions\SaveEmailDraft;
use App\Domain\Inbox\Actions\SendEmail;
use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Filament\App\Resources\Costs\CostResource;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Actions on an e-mail: reply (draft), edit and send a draft, assign contact / vehicle file,
 * mark handled.
 */
final class EmailActions
{
    public static function reply(): Action
    {
        return Action::make('reply')
            ->label(__('Reply'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (EmailMessage $record): bool => $record->direction === EmailMessage::IN && $record->mailbox->canSend() && self::can('create'))
            ->modalWidth('3xl')
            ->fillForm(fn (EmailMessage $record): array => [
                'to' => $record->from_address,
                'subject' => preg_match('/^\s*(re|aw|ri|tr)\s*:/i', (string) $record->subject) === 1 ? $record->subject : 'Re: '.$record->subject,
                'body' => "\n\n".self::quote($record),
            ])
            ->schema(fn (EmailMessage $record): array => self::fields($record))
            ->modalSubmitActionLabel(__('Save draft'))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAndSend', ['send' => true])->label(__('Send'))->color('primary'),
            ])
            ->action(fn (EmailMessage $record, array $data, array $arguments, Action $action) => self::run($action, function () use ($record, $data, $arguments): void {
                $draft = app(SaveEmailDraft::class)->reply($record, self::payload($data));
                self::finish($draft, (bool) ($arguments['send'] ?? false));
            }));
    }

    public static function editDraft(): Action
    {
        return Action::make('editDraft')
            ->label(__('Edit draft'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (EmailMessage $record): bool => in_array($record->status, [EmailStatus::Draft, EmailStatus::Failed], true) && self::can('update', $record))
            ->modalWidth('3xl')
            ->fillForm(fn (EmailMessage $record): array => [
                'to' => implode(', ', array_column($record->to ?? [], 'email')),
                'cc' => implode(', ', array_column($record->cc ?? [], 'email')),
                'subject' => $record->subject,
                'body' => $record->body_text,
                'document_ids' => $record->attachments()->pluck('id')->all(),
            ])
            ->schema(fn (EmailMessage $record): array => self::fields($record))
            ->modalSubmitActionLabel(__('Save draft'))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAndSend', ['send' => true])->label(__('Send'))->color('primary'),
            ])
            ->action(fn (EmailMessage $record, array $data, array $arguments, Action $action) => self::run($action, function () use ($record, $data, $arguments): void {
                $draft = app(SaveEmailDraft::class)->save($record, self::payload($data));
                self::finish($draft, (bool) ($arguments['send'] ?? false));
            }));
    }

    public static function send(): Action
    {
        return Action::make('send')
            ->label(__('Send'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (EmailMessage $record): bool => in_array($record->status, [EmailStatus::Draft, EmailStatus::Failed], true) && self::can('update', $record))
            ->requiresConfirmation()
            ->modalDescription(fn (EmailMessage $record): string => __('Send to :to now?', ['to' => implode(', ', array_column($record->to ?? [], 'email'))]))
            ->action(fn (EmailMessage $record, Action $action) => self::run($action, fn () => self::finish($record, true)));
    }

    public static function assign(): Action
    {
        return Action::make('assign')
            ->label(__('Assign'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (EmailMessage $record): bool => self::can('update', $record))
            ->fillForm(fn (EmailMessage $record): array => ['party_id' => $record->party_id, 'stock_cycle_id' => $record->stock_cycle_id])
            ->schema([
                PartySelect::make('party_id')->label(__('Contact')),
                Select::make('stock_cycle_id')->label(__('Vehicle file'))->options(fn (): array => CostResource::cycleOptions())->searchable(),
            ])
            ->action(function (EmailMessage $record, array $data): void {
                app(AssignEmail::class)($record, $data['party_id'] ?? null, $data['stock_cycle_id'] ?? null);
                Notification::make()->title(__('Saved.'))->success()->send();
            });
    }

    public static function handled(): Action
    {
        return Action::make('handled')
            ->label(fn (EmailMessage $record): string => $record->handled_at === null ? __('Mark handled') : __('Reopen'))
            ->icon(fn (EmailMessage $record): Heroicon => $record->handled_at === null ? Heroicon::OutlinedCheck : Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (EmailMessage $record): bool => $record->direction === EmailMessage::IN && self::can('update', $record))
            ->action(fn (EmailMessage $record) => app(AssignEmail::class)->handled($record, $record->handled_at === null));
    }

    /**
     * @return array<int, mixed>
     */
    private static function fields(EmailMessage $record): array
    {
        $documents = $record->stock_cycle_id === null ? [] : Document::query()
            ->whereHas('links', fn ($q) => $q->where('linkable_type', 'stock_cycle')->where('linkable_id', $record->stock_cycle_id))
            ->orderByDesc('documents.created_at')->limit(100)->get()
            ->mapWithKeys(fn (Document $d): array => [$d->getKey() => $d->title])->all();

        return [
            TextInput::make('to')->label(__('To'))->required()->helperText(__('Several addresses separated by commas.')),
            TextInput::make('cc')->label(__('Cc')),
            TextInput::make('subject')->label(__('Subject'))->required()->maxLength(500),
            Textarea::make('body')->label(__('Text'))->required()->rows(14),
            CheckboxList::make('document_ids')->label(__('Attach documents of the vehicle file'))->options($documents)
                ->visible($documents !== [])->columns(2)->searchable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{body: string, subject: string|null, to: string|null, cc: string|null, document_ids: list<string>}
     */
    private static function payload(array $data): array
    {
        return [
            'body' => (string) ($data['body'] ?? ''),
            'subject' => $data['subject'] ?? null,
            'to' => $data['to'] ?? null,
            'cc' => $data['cc'] ?? null,
            'document_ids' => array_values(array_map('strval', (array) ($data['document_ids'] ?? []))),
        ];
    }

    private static function finish(EmailMessage $draft, bool $send): void
    {
        if (! $send) {
            Notification::make()->title(__('Draft saved. It is sent only when you click "Send".'))->success()->send();

            return;
        }

        app(SendEmail::class)($draft->refresh());
        Notification::make()->title(__('E-mail sent.'))->success()->send();
    }

    private static function quote(EmailMessage $message): string
    {
        $lines = array_map(fn (string $l): string => '> '.$l, preg_split('/\r?\n/', mb_substr((string) $message->body_text, 0, 20_000)) ?: []);

        return __('On :date :sender wrote:', ['date' => $message->sent_at?->format('d.m.Y H:i') ?? '', 'sender' => $message->sender()])."\n".implode("\n", $lines);
    }

    private static function can(string $ability, ?EmailMessage $record = null): bool
    {
        return auth()->user()?->can($ability, $record ?? EmailMessage::class) ?? false;
    }

    private static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();
            $action->halt();
        }
    }
}
