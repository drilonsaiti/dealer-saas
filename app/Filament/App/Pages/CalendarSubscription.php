<?php

namespace App\Filament\App\Pages;

use App\Domain\Calendar\Actions\IssueCalendarFeed;
use App\Domain\Calendar\Models\CalendarFeed;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * "My calendar": a personal subscription link for Outlook, Google or Apple Calendar with the
 * dealer's important dates (handovers, MFK, payouts, buy-backs …).
 */
class CalendarSubscription extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'calendar';

    /** Shown once, right after the link was created. */
    public ?string $newUrl = null;

    public static function getNavigationLabel(): string
    {
        return __('My calendar');
    }

    public function getTitle(): string
    {
        return __('My calendar');
    }

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && auth()->user()->hasPermission(Permission::VehiclesView);
    }

    public function feed(): ?CalendarFeed
    {
        return CalendarFeed::query()->where('user_id', auth()->id())->whereNull('revoked_at')->latest()->first();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Subscribe in your calendar'))->schema([
                Text::make(__('Handovers, reservations ending, MFK due, preparation and repair targets, warranties ending, buy-back reminders and leasing payouts appear as all-day events in your own calendar (Outlook, Google, Apple). The calendar app updates them itself, usually every few hours.')),
                Text::make(__('The link is personal and works without login: do not share it. If it got out, create a new one; the old one stops working at once.')),
                TextEntry::make('url')->label(__('Your calendar link'))->state(fn (): ?string => $this->newUrl)->copyable()->fontFamily('mono')
                    ->visible(fn (): bool => $this->newUrl !== null)
                    ->helperText(__('Copy it now: for security it is shown only once. Outlook: Add calendar → From internet. Google: Other calendars → From URL. Apple: File → New calendar subscription.')),
                TextEntry::make('status')->label(__('Status'))
                    ->state(function (): string {
                        $feed = $this->feed();

                        return $feed === null ? __('No calendar link yet.') : __('Active since :date, last read :used.', [
                            'date' => $feed->created_at->format('d.m.Y'),
                            'used' => $feed->last_used_at?->diffForHumans() ?? __('never'),
                        ]);
                    }),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(fn (): string => $this->feed() === null ? __('Create calendar link') : __('Create new link'))
                ->icon(Heroicon::OutlinedLink)
                ->requiresConfirmation(fn (): bool => $this->feed() !== null)
                ->modalDescription(fn (): ?string => $this->feed() !== null ? __('The current link stops working; calendars using it must be subscribed again.') : null)
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    [, $this->newUrl] = app(IssueCalendarFeed::class)($user);
                    Notification::make()->title(__('Calendar link created. Copy it now.'))->success()->send();
                }),
            Action::make('revoke')
                ->label(__('Switch off'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (): bool => $this->feed() !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    app(IssueCalendarFeed::class)->revoke($user);
                    $this->newUrl = null;
                    Notification::make()->title(__('Calendar link switched off.'))->success()->send();
                }),
        ];
    }
}
