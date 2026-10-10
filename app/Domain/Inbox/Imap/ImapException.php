<?php

namespace App\Domain\Inbox\Imap;

use RuntimeException;

/**
 * The mail server refused or the connection failed; the message is shown to the dealer.
 */
class ImapException extends RuntimeException {}
