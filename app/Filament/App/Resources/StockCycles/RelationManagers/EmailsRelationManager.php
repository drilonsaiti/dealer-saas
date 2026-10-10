<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Inbox\Models\EmailMessage;
use App\Filament\App\Resources\EmailMessages\EmailMessageResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The e-mails of a vehicle file (received and sent), opened in the inbox.
 */
class EmailsRelationManager extends RelationManager
{
    protected static string $relationship = 'emailMessages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('E-mails');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('direction')->label('')
                    ->formatStateUsing(fn (string $state): string => $state === EmailMessage::IN ? '←' : '→'),
                TextColumn::make('from_address')->label(__('From / to'))
                    ->state(fn (EmailMessage $record): string => $record->direction === EmailMessage::IN ? $record->sender() : implode(', ', array_column($record->to ?? [], 'email')))
                    ->limit(40),
                TextColumn::make('subject')->label(__('Subject'))->limit(80)->wrap(),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->recordUrl(fn (EmailMessage $record): string => EmailMessageResource::getUrl('view', ['record' => $record]));
    }
}
