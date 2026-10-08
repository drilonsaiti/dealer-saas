<?php

namespace App\Domain\Signatures\Actions;

use App\Domain\Signatures\Enums\SignerRole;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Signatures\Models\Signer;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One signer signs: read and accepted, place, drawn signature, plus the evidence (time, IP,
 * device, how they were identified). The last signature completes the request.
 */
class RecordSignature
{
    public const CODE_VALID_FOR_SIGNING_MINUTES = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CompleteSigning $complete,
    ) {}

    /**
     * @param  array{ip?: string|null, user_agent?: string|null}  $context
     * @param  array{doc_type?: string|null, doc_number?: string|null}|null  $idCheck  showroom ID check of the customer
     */
    public function __invoke(Signer $signer, string $signatureDataUrl, string $place, bool $accepted, array $context, ?array $idCheck = null, ?User $by = null): Signer
    {
        $request = $signer->loadMissing('request.signers')->request;

        if (! $request->isOpen()) {
            throw new BusinessRuleException(__('This signature request is no longer open.'));
        }

        if ($request->nextSigner()?->getKey() !== $signer->getKey()) {
            throw new BusinessRuleException($signer->hasSigned() ? __('Already signed.') : __('Someone else has to sign first.'));
        }

        if (! $accepted) {
            throw new BusinessRuleException(__('Please confirm that you have read and accept the document.'));
        }

        if (trim($place) === '') {
            throw new BusinessRuleException(__('Please enter the place.'));
        }

        $png = $this->decode($signatureDataUrl);
        $identification = $this->identification($signer, $idCheck, $by);

        $path = sprintf('tenants/%s/signatures/%s/%s.png', $this->context->id(), $request->getKey(), $signer->getKey());
        Storage::disk((string) config('dealer.documents.disk'))->put($path, $png);

        DB::transaction(function () use ($signer, $place, $context, $identification, $path, $png, $by): void {
            $signer->forceFill([
                'status' => Signer::STATUS_SIGNED,
                'signed_at' => now(),
                'place' => trim($place),
                'ip' => $context['ip'] ?? null,
                'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 255) : null,
                'identification' => $identification,
                'signature_path' => $path,
                'signature_sha256' => hash('sha256', $png),
                // The dealer may countersign as another user than the one who started.
                ...($signer->role === SignerRole::Dealer && $by !== null ? ['user_id' => $by->getKey(), 'name' => $by->name, 'email' => $by->email] : []),
            ])->save();
        });

        $request->unsetRelation('signers');

        if ($request->nextSigner() === null) {
            ($this->complete)($request);
        }

        return $signer->refresh();
    }

    /**
     * @param  array{doc_type?: string|null, doc_number?: string|null}|null  $idCheck
     * @return array<string, mixed>
     */
    private function identification(Signer $signer, ?array $idCheck, ?User $by): array
    {
        if ($signer->role === SignerRole::Dealer) {
            if ($by === null) {
                throw new BusinessRuleException(__('The dealer signs while logged in.'));
            }

            return ['method' => 'login', 'user' => $by->email, 'two_factor' => filled($by->getAppAuthenticationSecret()) || $by->hasEmailAuthentication()];
        }

        if ($signer->method === SigningMethod::Link) {
            if ($signer->code_verified_at === null || $signer->code_verified_at->lt(now()->subMinutes(self::CODE_VALID_FOR_SIGNING_MINUTES))) {
                throw new BusinessRuleException(__('Please confirm the code first.'));
            }

            return [
                'method' => 'link_code',
                'email' => $signer->email,
                'code_channel' => $signer->code_channel,
                'code_sent_to' => $signer->code_channel === 'sms' ? $signer->maskedPhone() : $signer->email,
                'code_verified_at' => $signer->code_verified_at->toIso8601String(),
            ];
        }

        if (blank($idCheck['doc_type'] ?? null) || blank($idCheck['doc_number'] ?? null) || $by === null) {
            throw new BusinessRuleException(__('Check the customer’s ID and enter its type and number.'));
        }

        return [
            'method' => 'id_check',
            'doc_type' => (string) $idCheck['doc_type'],
            'doc_number' => (string) $idCheck['doc_number'],
            'checked_by' => $by->name,
        ];
    }

    private function decode(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            throw new BusinessRuleException(__('Please sign in the field.'));
        }

        $png = base64_decode($m[1], true);

        if ($png === false || ! str_starts_with($png, "\x89PNG") || strlen($png) > 1_000_000) {
            throw new BusinessRuleException(__('Please sign in the field.'));
        }

        $image = @imagecreatefromstring($png);

        if ($image === false || $this->isBlank($image)) {
            throw new BusinessRuleException(__('Please sign in the field.'));
        }

        return $png;
    }

    /**
     * True when (nearly) no pixel is drawn, e.g. the field was submitted empty.
     */
    private function isBlank(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $drawn = 0;

        for ($x = 0; $x < $width; $x += 3) {
            for ($y = 0; $y < $height; $y += 3) {
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;

                if ($alpha < 100 && (imagecolorat($image, $x, $y) & 0xFFFFFF) !== 0xFFFFFF) {
                    $drawn++;

                    if ($drawn > 20) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
