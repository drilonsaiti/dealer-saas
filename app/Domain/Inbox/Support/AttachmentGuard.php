<?php

namespace App\Domain\Inbox\Support;

use App\Domain\Inbox\Mime\Attachment;

/**
 * Decides whether an e-mail attachment may go into the document archive: no executables or
 * macro documents (by extension, also hidden behind a second extension, and by content),
 * a size limit, and, when configured, a ClamAV scan. Returns the reason when it is refused.
 */
class AttachmentGuard
{
    public function __construct(private readonly ClamAv $clamAv) {}

    public function refuse(Attachment $attachment): ?string
    {
        $extensions = array_map('strtolower', array_slice(explode('.', $attachment->filename), 1));
        $blocked = (array) config('dealer.mail.blocked_extensions', []);

        foreach ($extensions as $extension) {
            if (in_array($extension, $blocked, true)) {
                return __('File type .:extension is not accepted.', ['extension' => $extension]);
            }
        }

        if (strlen($attachment->content) > (int) config('dealer.mail.max_attachment_mb', 25) * 1024 * 1024) {
            return __('Larger than :mb MB.', ['mb' => (int) config('dealer.mail.max_attachment_mb', 25)]);
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($attachment->content) ?: '';

        if (in_array($detected, ['application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary', 'application/x-elf'], true)
            || str_starts_with($attachment->content, 'MZ')) {
            return __('The file contains a program.');
        }

        return $this->clamAv->scan($attachment->content);
    }
}
