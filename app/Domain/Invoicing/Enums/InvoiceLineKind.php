<?php

namespace App\Domain\Invoicing\Enums;

enum InvoiceLineKind: string
{
    case Vehicle = 'vehicle';
    case Item = 'item';
    case Deposit = 'deposit';
    case DepositDeduction = 'deposit_deduction';
    case CollectionCredit = 'collection_credit';
    case Other = 'other';
}
