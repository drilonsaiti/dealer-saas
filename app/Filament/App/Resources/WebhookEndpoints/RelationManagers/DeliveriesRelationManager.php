<?php

namespace App\Filament\App\Resources\WebhookEndpoints\RelationManagers;

use App\Domain\Api\Jobs\DeliverWebhook;
use App\Domain\Api\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The delivery log of an endpoint: event, attempts, answer; send again by hand.
 */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Deliveries');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('event')->label(__('Event'))->fontFamily('mono'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'delivered' => 'success', 'failed' => 'danger', default => 'warning'
                    }),
                TextColumn::make('attempts')->label(__('Attempts')),
                TextColumn::make('response_code')->label(__('Answer'))->placeholder('–')
                    ->description(fn (WebhookDelivery $record): ?string => $record->status === 'delivered' ? null : str((string) $record->response_body)->limit(80)->toString()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('resend')
                    ->label(__('Send again'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (WebhookDelivery $record): void {
                        $record->forceFill(['status' => 'pending'])->save();
                        DeliverWebhook::dispatch($record->getKey());
                        Notification::make()->title(__('Queued.'))->success()->send();
                    }),
            ]);
    }
}
