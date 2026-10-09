<?php

namespace App\Domain\Payments\Support;

use App\Domain\Settings\Support\Iban;
use App\Support\BusinessRuleException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads ISO 20022 bank statements: camt.053 (account statement) and camt.054 (credit/debit
 * notification), any version, as sent by Swiss banks. One row per transaction (a batch
 * booking with several QR payments gives several rows).
 */
final class CamtParser
{
    /**
     * @return array{iban: string|null, entries: list<array{entry_key: string, booked_on: string, value_on: string|null, amount_rp: int, currency: string, reference: string|null, counterparty: string|null, remittance: string|null, raw: array<string, mixed>}>}
     */
    public static function parse(string $xml): array
    {
        $dom = new DOMDocument;

        if (trim($xml) === '' || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new BusinessRuleException(__('The file is not a readable camt.053 or camt.054 bank statement.'));
        }

        $x = new DOMXPath($dom);
        $root = $x->query('/*[local-name()="Document"]/*')->item(0);

        if (! $root instanceof DOMElement || ! in_array($root->localName, ['BkToCstmrStmt', 'BkToCstmrDbtCdtNtfctn', 'BkToCstmrAcctRpt'], true)) {
            throw new BusinessRuleException(__('The file is not a readable camt.053 or camt.054 bank statement.'));
        }

        $iban = self::text($x, './/*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]', $root);
        $entries = [];
        $index = 0;

        foreach ($x->query('.//*[local-name()="Ntry"]', $root) ?: [] as $entry) {
            /** @var DOMElement $entry */
            $sign = self::text($x, './*[local-name()="CdtDbtInd"]', $entry) === 'DBIT' ? -1 : 1;
            $booked = self::date($x, './*[local-name()="BookgDt"]', $entry);
            $value = self::date($x, './*[local-name()="ValDt"]', $entry);
            $entryRef = self::text($x, './*[local-name()="AcctSvcrRef"]', $entry) ?? self::text($x, './*[local-name()="NtryRef"]', $entry);
            $transactions = $x->query('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $entry);

            if ($transactions === false || $transactions->length === 0) {
                $transactions = [$entry];
            }

            foreach ($transactions as $tx) {
                /** @var DOMElement $tx */
                $index++;
                $amount = self::text($x, './*[local-name()="Amt"]', $tx) ?? self::text($x, './*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"]', $tx) ?? self::text($x, './*[local-name()="Amt"]', $entry);
                $currency = self::attr($x, './*[local-name()="Amt"]', $tx, 'Ccy') ?? self::attr($x, './*[local-name()="Amt"]', $entry, 'Ccy') ?? 'CHF';
                $txSign = ($tx !== $entry && ($indicator = self::text($x, './*[local-name()="CdtDbtInd"]', $tx)) !== null) ? ($indicator === 'DBIT' ? -1 : 1) : $sign;
                $reference = self::text($x, './/*[local-name()="RmtInf"]/*[local-name()="Strd"]/*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]', $tx);
                $party = $txSign > 0 ? 'Dbtr' : 'Cdtr';
                $counterparty = self::text($x, './/*[local-name()="RltdPties"]/*[local-name()="'.$party.'"]//*[local-name()="Nm"]', $tx);
                $remittance = self::text($x, './/*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $tx) ?? self::text($x, './*[local-name()="AddtlNtryInf"]', $entry);
                $txRef = self::text($x, './/*[local-name()="Refs"]/*[local-name()="AcctSvcrRef"]', $tx) ?? self::text($x, './/*[local-name()="Refs"]/*[local-name()="EndToEndId"]', $tx);

                if ($amount === null || $booked === null) {
                    continue;
                }

                $amountRp = $txSign * (int) round((float) $amount * 100);
                $reference = $reference === null ? null : strtoupper(str_replace(' ', '', $reference));

                $entries[] = [
                    // Stable across re-imports of the same or overlapping statements.
                    'entry_key' => hash('sha256', implode('|', [Iban::normalize((string) $iban), $entryRef ?? '', $txRef ?? '', $booked, $amountRp, $reference ?? '', $entryRef === null && $txRef === null ? $index : ''])),
                    'booked_on' => $booked,
                    'value_on' => $value,
                    'amount_rp' => $amountRp,
                    'currency' => $currency,
                    'reference' => $reference,
                    'counterparty' => $counterparty,
                    'remittance' => $remittance === null ? null : mb_substr($remittance, 0, 500),
                    'raw' => array_filter(['entry_ref' => $entryRef, 'tx_ref' => $txRef]),
                ];
            }
        }

        return ['iban' => $iban === null ? null : Iban::normalize($iban), 'entries' => $entries];
    }

    private static function text(DOMXPath $x, string $query, DOMElement $context): ?string
    {
        $node = $x->query($query, $context)->item(0);
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private static function attr(DOMXPath $x, string $query, DOMElement $context, string $name): ?string
    {
        $node = $x->query($query, $context)->item(0);

        return $node instanceof DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    private static function date(DOMXPath $x, string $query, DOMElement $context): ?string
    {
        $value = self::text($x, $query.'/*[local-name()="Dt"]', $context) ?? self::text($x, $query.'/*[local-name()="DtTm"]', $context);

        return $value === null ? null : substr($value, 0, 10);
    }
}
