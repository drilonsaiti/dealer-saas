<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Adds a user to a dealer with a role. New users get a random password and a
 * password-reset link by email, so they choose their own password.
 */
class InviteMember
{
    public function __invoke(Tenant $tenant, string $email, ?string $name, Role $role, bool $sendInvitation = true): TenantMembership
    {
        $email = Str::lower(trim($email));

        $user = User::query()->where('email', $email)->first();
        $isNew = $user === null;

        if ($user === null) {
            $user = User::create([
                'name' => filled($name) ? $name : Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'locale' => $tenant->default_locale,
            ]);
        }

        $membership = $tenant->addMember($user, $role);

        if ($isNew && $sendInvitation) {
            $this->sendInvitation($user);
        }

        return $membership;
    }

    private function sendInvitation(User $user): void
    {
        Password::broker()->sendResetLink(
            ['email' => $user->email],
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                $notification = new ResetPassword($token);
                $notification->url = Filament::getPanel('app')->getResetPasswordUrl($token, $user);

                /** @var User $user */
                $user->notify($notification);
            },
        );
    }
}
