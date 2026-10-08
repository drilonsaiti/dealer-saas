<?php

namespace App\Domain\Signatures\Actions;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Models\Document;
use App\Domain\Signatures\Enums\SignerRole;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Signatures\Mail\SigningLinkMail;
use App\Domain\Signatures\Models\SignatureRequest;
use App\Domain\Signatures\Models\Signer;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends a finalised contract out for signature: first the customer (here on the device or
 * by link), then the dealer. The document is "out for signature" until all have signed or
 * the request is withdrawn.
 */
class StartSigning
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{name?: string|null, email?: string|null, phone?: string|null, locale?: string|null}  $customer  contact details for this signature (prefilled from the contact)
     */
    public function __invoke(Document $document, SigningMethod $method, array $customer, User $dealer): SignatureRequest
    {
        $version = $document->currentVersion;

        if (TemplateType::tryFrom((string) $document->type_key) === null || $version === null || $version->locked_at === null) {
            throw new BusinessRuleException(__('Only a finalised contract can be signed.'));
        }

        if ($document->status !== DocumentStatus::Final) {
            throw new BusinessRuleException(match ($document->status) {
                DocumentStatus::OutForSignature => __('This document is already out for signature.'),
                DocumentStatus::Signed => __('This document is already signed.'),
                default => __('Only a finalised contract can be signed.'),
            });
        }

        $party = $document->parties()->first();
        $name = trim((string) ($customer['name'] ?? $party?->displayName() ?? ''));
        $email = filled($customer['email'] ?? null) ? (string) $customer['email'] : $party?->email;
        $phone = filled($customer['phone'] ?? null) ? (string) $customer['phone'] : ($party?->mobile ?: $party?->phone);
        $locale = in_array($customer['locale'] ?? null, (array) config('dealer.locales'), true) ? (string) $customer['locale'] : ($document->locale ?? 'de');

        if ($name === '') {
            throw new BusinessRuleException(__('Enter the name of the customer who signs.'));
        }

        if ($method === SigningMethod::Link && blank($email)) {
            throw new BusinessRuleException(__('Signing by link needs the customer’s email address.'));
        }

        if ($method === SigningMethod::Link && config('dealer.signatures.code_channel') === 'sms' && blank($phone)) {
            throw new BusinessRuleException(__('Signing by link needs the customer’s mobile number for the code.'));
        }

        $token = null;

        $request = DB::transaction(function () use ($document, $version, $method, $party, $name, $email, $phone, $locale, $dealer, &$token): SignatureRequest {
            $request = SignatureRequest::create([
                'document_id' => $document->getKey(),
                'document_version_id' => $version->getKey(),
                'expires_at' => $method === SigningMethod::Link ? now()->addDays((int) config('dealer.signatures.link_valid_days')) : null,
            ]);

            $signer = new Signer([
                'signature_request_id' => $request->getKey(),
                'position' => 1,
                'role' => SignerRole::Customer,
                'method' => $method,
                'party_id' => $party?->getKey(),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'locale' => $locale,
            ]);

            if ($method === SigningMethod::Link) {
                $token = Str::random(48);
                $signer->token_hash = hash('sha256', $token);
            }

            $signer->save();

            Signer::create([
                'signature_request_id' => $request->getKey(),
                'position' => 2,
                'role' => SignerRole::Dealer,
                'method' => SigningMethod::OnDevice,
                'user_id' => $dealer->getKey(),
                'name' => $dealer->name,
                'email' => $dealer->email,
                'locale' => $locale,
            ]);

            $document->forceFill(['status' => DocumentStatus::OutForSignature])->save();

            return $request;
        });

        if ($token !== null) {
            $this->sendLink($request->signers()->firstOrFail(), $token);
        }

        return $request->load('signers');
    }

    /**
     * (Re)sends the signing link; a new link replaces the old one.
     */
    public function resendLink(Signer $signer): void
    {
        if ($signer->method !== SigningMethod::Link || $signer->hasSigned() || ! $signer->loadMissing('request.document')->request->isOpen()) {
            throw new BusinessRuleException(__('There is no open link to send.'));
        }

        $token = Str::random(48);
        $signer->forceFill(['token_hash' => hash('sha256', $token)])->save();
        $this->sendLink($signer, $token);
    }

    private function sendLink(Signer $signer, string $token): void
    {
        $request = $signer->loadMissing('request.document')->request;
        $tenant = $this->context->tenant();

        Mail::to((string) $signer->email)->locale($signer->locale)->send(new SigningLinkMail(
            $signer->name,
            $request->document->title,
            (string) ($tenant?->legal_name ?: $tenant?->name),
            route('signing.show', $token),
            $request->expires_at,
        ));
    }
}
