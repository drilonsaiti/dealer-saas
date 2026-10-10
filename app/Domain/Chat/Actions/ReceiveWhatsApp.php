<?php

namespace App\Domain\Chat\Actions;

use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Chat\Providers\WhatsApp\WhatsAppCloud;
use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Support\DuplicateDocument;
use App\Domain\Inbox\Mime\Attachment;
use App\Domain\Inbox\Support\AttachmentGuard;
use App\Domain\Inbox\Support\MessageMatcher;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Support\IntegrationException;
use App\Domain\Parties\Models\Party;
use App\Domain\Parties\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * A webhook from WhatsApp: new customer messages (text, photos, documents) are stored and
 * matched to contact (by phone) and vehicle file (VIN, Stammnummer, file number, plate in the
 * text, or the contact's one open sale); delivery statuses update our sent messages.
 * Repeated webhooks change nothing (Meta's message id).
 */
class ReceiveWhatsApp
{
    public function __construct(
        private readonly WhatsAppCloud $whatsapp,
        private readonly MessageMatcher $matcher,
        private readonly AttachmentGuard $guard,
        private readonly StoreDocument $store,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int new messages
     */
    public function __invoke(IntegrationAccount $account, array $payload): int
    {
        $new = 0;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) Arr::get((array) $entry, 'changes', []) as $change) {
                $value = (array) Arr::get((array) $change, 'value', []);
                $names = collect((array) ($value['contacts'] ?? []))->mapWithKeys(fn ($c): array => [(string) Arr::get((array) $c, 'wa_id') => Arr::get((array) $c, 'profile.name')]);

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    if (is_array($message) && $this->message($account, $message, $names->get((string) ($message['from'] ?? '')))) {
                        $new++;
                    }
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        $this->status($account, $status);
                    }
                }
            }
        }

        return $new;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function message(IntegrationAccount $account, array $message, mixed $name): bool
    {
        $externalId = (string) ($message['id'] ?? '');
        $phone = PhoneNumber::normalize((string) ($message['from'] ?? ''));

        if ($externalId === '' || $phone === null || ChatMessage::query()->where('integration_account_id', $account->getKey())->where('external_id', $externalId)->exists()) {
            return false;
        }

        $type = (string) ($message['type'] ?? 'text');
        $media = in_array($type, ['image', 'document', 'video', 'audio'], true) ? (array) ($message[$type] ?? []) : [];
        $body = match ($type) {
            'text' => (string) Arr::get($message, 'text.body', ''),
            'button' => (string) Arr::get($message, 'button.text', ''),
            'interactive' => (string) (Arr::get($message, 'interactive.button_reply.title') ?? Arr::get($message, 'interactive.list_reply.title', '')),
            default => (string) ($media['caption'] ?? ''),
        };

        $party = Party::query()->where(fn ($q) => $q->where('mobile_normalized', $phone)->orWhere('phone_normalized', $phone))->orderBy('created_at')->first();
        $previous = ChatMessage::query()->where('phone', $phone)->latest('created_at')->first();
        $match = $this->matcher->match(null, '', $body, $party);

        $chat = new ChatMessage([
            'integration_account_id' => $account->getKey(),
            'direction' => ChatMessage::IN,
            'external_id' => $externalId,
            'phone' => $phone,
            'contact_name' => is_string($name) ? mb_substr($name, 0, 200) : null,
            'body' => mb_substr($body, 0, 10_000),
            'status' => 'received',
        ]);
        $chat->forceFill([
            'party_id' => $party?->getKey() ?? $previous?->party_id,
            'stock_cycle_id' => $match['cycle']?->getKey() ?? $previous?->stock_cycle_id,
        ]);

        if (is_numeric($message['timestamp'] ?? null)) {
            $chat->created_at = Carbon::createFromTimestamp((int) $message['timestamp']);
        }

        try {
            $chat->save();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($media !== [] && is_string($media['id'] ?? null)) {
            $this->media($account, $chat, $media, $type);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function media(IntegrationAccount $account, ChatMessage $chat, array $media, string $type): void
    {
        try {
            $file = $this->whatsapp->media($account, (string) $media['id']);
        } catch (IntegrationException $e) {
            $chat->forceFill(['error' => $e->getMessage()])->save();

            return;
        }

        $extension = match (true) {
            str_contains($file['mime'], 'jpeg') => 'jpg',
            str_contains($file['mime'], 'png') => 'png',
            str_contains($file['mime'], 'pdf') => 'pdf',
            str_contains($file['mime'], 'mp4') => 'mp4',
            str_contains($file['mime'], 'ogg') => 'ogg',
            default => 'bin',
        };
        $name = is_string($media['filename'] ?? null) ? basename((string) $media['filename']) : "whatsapp-{$type}.{$extension}";
        $refusal = $this->guard->refuse(new Attachment($name, $file['mime'], $file['content']));

        if ($refusal !== null) {
            $chat->forceFill(['error' => $name.': '.$refusal])->save();

            return;
        }

        $category = DocumentCategory::query()->where('key', 'correspondence')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'correspondence')->firstOrFail();
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'wa');
        file_put_contents($path, $file['content']);

        try {
            $document = ($this->store)($path, $category, [
                'title' => pathinfo($name, PATHINFO_FILENAME),
                'original_name' => $name,
                'document_on' => $chat->created_at->toDateString(),
                'source' => DocumentSource::Upload,
            ], array_values(array_filter([$chat->stockCycle, $chat->party])));
        } catch (DuplicateDocument $e) {
            $document = $e->existing;
        } finally {
            @unlink($path);
        }

        $chat->forceFill(['document_id' => $document?->getKey()])->save();
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function status(IntegrationAccount $account, array $status): void
    {
        $message = ChatMessage::query()->where('integration_account_id', $account->getKey())->where('external_id', (string) ($status['id'] ?? ''))->first();
        $value = (string) ($status['status'] ?? '');

        if ($message === null || ! in_array($value, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $order = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

        if ($order[$value] <= ($order[$message->status] ?? 0) && $value !== 'failed') {
            return; // webhooks may arrive out of order
        }

        $message->forceFill([
            'status' => $value,
            'error' => $value === 'failed' ? (string) (Arr::get($status, 'errors.0.title') ?? Arr::get($status, 'errors.0.message', 'failed')) : $message->error,
        ])->save();
    }
}
