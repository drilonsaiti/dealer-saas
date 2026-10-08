<?php

namespace App\Domain\Signatures\Actions;

use App\Domain\Signatures\Mail\SigningCodeMail;
use App\Domain\Signatures\Models\Signer;
use App\Domain\Signatures\Support\SmsSender;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * One-time code for a customer signing by link: proves the person with the link also has
 * the phone (or mailbox) on file. Valid 10 minutes, 5 attempts, one new code per minute.
 */
class SendSigningCode
{
    public const VALID_MINUTES = 10;

    public function __construct(private readonly SmsSender $sms) {}

    public function __invoke(Signer $signer, string $dealerName): string
    {
        if ($signer->code_sent_at !== null && $signer->code_sent_at->gt(now()->subMinute())) {
            throw new BusinessRuleException(__('A code was just sent. Please wait a minute before asking for a new one.'));
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $channel = config('dealer.signatures.code_channel') === 'sms' && filled($signer->phone) ? 'sms' : 'email';

        $signer->forceFill([
            'code_hash' => Hash::make($code),
            'code_channel' => $channel,
            'code_sent_at' => now(),
            'code_attempts' => 0,
            'code_verified_at' => null,
        ])->save();

        if ($channel === 'sms') {
            $this->sms->send((string) $signer->phone, (string) __('Your code to sign the document of :dealer: :code', ['dealer' => $dealerName, 'code' => $code], $signer->locale));
        } else {
            Mail::to((string) $signer->email)->locale($signer->locale)->send(new SigningCodeMail($code, $dealerName));
        }

        return $channel;
    }

    public function verify(Signer $signer, string $code): void
    {
        if ($signer->code_hash === null || $signer->code_sent_at === null) {
            throw new BusinessRuleException(__('Ask for a code first.'));
        }

        if ($signer->code_attempts >= 5 || $signer->code_sent_at->lt(now()->subMinutes(self::VALID_MINUTES))) {
            throw new BusinessRuleException(__('This code is no longer valid. Ask for a new one.'));
        }

        $signer->increment('code_attempts');

        if (! Hash::check(preg_replace('/\D/', '', $code) ?? '', $signer->code_hash)) {
            throw new BusinessRuleException(__('The code is not correct.'));
        }

        $signer->forceFill(['code_verified_at' => now()])->save();
    }
}
