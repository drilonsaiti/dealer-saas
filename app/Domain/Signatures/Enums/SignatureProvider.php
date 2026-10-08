<?php

namespace App\Domain\Signatures\Enums;

/**
 * own = our simple electronic signature (SES); paper = printed, signed, scanned back;
 * skribble = qualified signature (QES) for written-form documents, add-on (not built yet).
 */
enum SignatureProvider: string
{
    case Own = 'own';
    case Paper = 'paper';
    case Skribble = 'skribble';
}
