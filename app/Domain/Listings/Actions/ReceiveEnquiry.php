<?php

namespace App\Domain\Listings\Actions;

use App\Domain\Api\Support\Webhooks;
use App\Domain\Listings\Mail\EnquiryReceivedMail;
use App\Domain\Listings\Models\Enquiry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Parties\Support\PhoneNumber;
use App\Domain\Tenancy\TenantContext;
use App\Filament\App\Resources\Enquiries\EnquiryResource;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * An enquiry from the website: linked to the car and to the contact (found by e-mail, then
 * phone; otherwise created as a customer), the dealer gets an e-mail, webhooks are sent.
 */
class ReceiveEnquiry
{
    public function __construct(
        private readonly Webhooks $webhooks,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, email, phone, message, locale, ip, source
     */
    public function __invoke(array $data, ?Listing $listing = null): Enquiry
    {
        $email = filled($data['email'] ?? null) ? Str::lower(trim((string) $data['email'])) : null;
        $phone = filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null;

        if ($email === null && $phone === null) {
            throw new BusinessRuleException(__('Enter an e-mail address or a phone number.'));
        }

        return DB::transaction(function () use ($data, $listing, $email, $phone): Enquiry {
            $party = $this->party((string) $data['name'], $email, $phone, $data['locale'] ?? null);

            $enquiry = Enquiry::create([
                'listing_id' => $listing?->getKey(),
                'stock_cycle_id' => $listing?->stock_cycle_id,
                'party_id' => $party->getKey(),
                'source' => $data['source'] ?? 'website',
                'name' => trim((string) $data['name']),
                'email' => $email,
                'phone' => $phone,
                'message' => trim((string) $data['message']),
                'locale' => $data['locale'] ?? null,
                'ip' => $data['ip'] ?? null,
            ]);

            $this->webhooks->dispatch('enquiry.received', [
                'enquiry_id' => $enquiry->getKey(),
                'vehicle_id' => $listing?->getKey(),
                'name' => $enquiry->name,
                'email' => $enquiry->email,
                'phone' => $enquiry->phone,
                'message' => $enquiry->message,
            ]);

            $tenant = $this->context->tenant();

            if ($tenant !== null && filled($tenant->email)) {
                Mail::to($tenant->email)->queue((new EnquiryReceivedMail(
                    $enquiry->name,
                    $enquiry->email,
                    $enquiry->phone,
                    $enquiry->message,
                    $listing?->getTranslation('title', 'de'),
                    EnquiryResource::getUrl('index', tenant: $tenant),
                ))->afterCommit());
            }

            return $enquiry;
        });
    }

    private function party(string $name, ?string $email, ?string $phone, ?string $locale): Party
    {
        $normalized = PhoneNumber::normalize($phone);
        $existing = ($email !== null ? Party::query()->whereRaw('lower(email) = ?', [$email])->first() : null)
            ?? ($normalized !== null ? Party::query()->where(fn ($q) => $q->where('phone_normalized', $normalized)->orWhere('mobile_normalized', $normalized))->first() : null);

        if ($existing !== null) {
            return $existing;
        }

        $parts = preg_split('/\s+/', trim($name), 2) ?: [$name];

        return Party::create([
            'kind' => 'person',
            'roles' => [PartyRole::Customer->value],
            'first_name' => count($parts) > 1 ? $parts[0] : null,
            'last_name' => count($parts) > 1 ? $parts[1] : $parts[0],
            'email' => $email,
            'mobile' => $phone,
            'locale' => in_array($locale, (array) config('dealer.locales'), true) ? $locale : ($this->context->tenant()->default_locale ?? 'de'),
        ]);
    }
}
