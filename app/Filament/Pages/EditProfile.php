<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

/**
 * Profile page of both panels: name, email, password, and the user's own language.
 * The language applies to the whole app (see SetLocale) and wins over APP_LOCALE and the browser.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Select::make('locale')
                ->label(__('Language'))
                ->options(config('dealer.locale_names'))
                ->placeholder(Filament::getCurrentPanel()?->getId() === 'platform' ? __('Automatic (browser)') : __('Automatic (language of the dealer)'))
                ->native(false),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    /**
     * Reload so the page, menu and messages switch to the new language right away.
     */
    protected function getRedirectUrl(): ?string
    {
        return Filament::getProfileUrl();
    }
}
