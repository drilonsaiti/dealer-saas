<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Signatures\Actions\RecordSignature;
use App\Domain\Signatures\Actions\SendSigningCode;
use App\Domain\Signatures\Models\Signer;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\BusinessRuleException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The customer's signing page behind the emailed link (no login): read the whole document,
 * confirm with a one-time code, tick "read and accepted", sign with finger or mouse.
 * The link token is stored only as a hash; the dealer is found through it.
 */
class SigningController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(string $token, DocumentRenderer $renderer): View
    {
        $signer = $this->resolve($token);
        $request = $signer->request;
        $version = $request->version;
        $isTurn = $request->isOpen() && $request->nextSigner()?->getKey() === $signer->getKey();

        return view('signing.show', [
            'token' => $token,
            'signer' => $signer,
            'request' => $request,
            'tenant' => $this->context->tenant(),
            'isTurn' => $isTurn,
            'documentHtml' => $isTurn ? $renderer->html((array) $version->data_snapshot) : null,
            'pdfUrl' => $isTurn ? Storage::disk($version->disk)->temporaryUrl($version->path, now()->addMinutes(30)) : null,
            'codeVerified' => $signer->code_verified_at !== null && $signer->code_verified_at->gt(now()->subMinutes(RecordSignature::CODE_VALID_FOR_SIGNING_MINUTES)),
        ]);
    }

    public function sendCode(string $token, SendSigningCode $send): RedirectResponse
    {
        $signer = $this->resolve($token);
        $this->ensureTurn($signer);

        try {
            $channel = $send($signer, $this->dealerName());
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', $channel === 'sms'
            ? __('We sent a code by SMS to :to.', ['to' => $signer->maskedPhone()])
            : __('We sent a code by email to :to.', ['to' => $signer->email]));
    }

    public function verify(string $token, Request $request, SendSigningCode $send): RedirectResponse
    {
        $signer = $this->resolve($token);
        $this->ensureTurn($signer);

        try {
            $send->verify($signer, (string) $request->input('code'));
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back();
    }

    public function sign(string $token, Request $request, RecordSignature $record): RedirectResponse
    {
        $signer = $this->resolve($token);
        $this->ensureTurn($signer);

        try {
            $record(
                $signer,
                (string) $request->input('signature'),
                (string) $request->input('place'),
                $request->boolean('accepted'),
                ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput($request->except('signature'))->withErrors(['signature' => $e->getMessage()]);
        }

        return redirect()->route('signing.show', $token);
    }

    private function resolve(string $token): Signer
    {
        $signer = $this->context->bypass(fn (): ?Signer => Signer::query()->with('request')->where('token_hash', hash('sha256', $token))->first());
        $tenant = $signer === null ? null : $this->context->bypass(fn (): ?Tenant => Tenant::query()->find($signer->tenant_id));

        if ($signer === null || $tenant === null || ! $tenant->isActive()) {
            abort(404);
        }

        $this->context->set($tenant);
        app()->terminating(fn () => $this->context->clear());
        app()->setLocale($signer->locale);

        return $signer->setRelation('request', $signer->request()->with(['version', 'document', 'signers'])->firstOrFail());
    }

    private function ensureTurn(Signer $signer): void
    {
        $request = $signer->request;

        if (! $request->isOpen() || $request->nextSigner()?->getKey() !== $signer->getKey()) {
            abort(410);
        }
    }

    private function dealerName(): string
    {
        $tenant = $this->context->tenant();

        return (string) ($tenant?->legal_name ?: $tenant?->name);
    }
}
