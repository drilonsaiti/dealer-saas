<?php

namespace App\Domain\Checklists\Actions;

use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Checklists\Models\ChecklistTemplate;

/**
 * The checklists every dealer starts with: the handover and the documents a leasing bank
 * needs (Cembra's list from the concept). Safe to run again (adds only missing templates).
 */
class InstallDefaultChecklists
{
    /**
     * key => [required, auto rule, de, fr, it, en]
     */
    public const HANDOVER = [
        'commitments' => [true, 'commitments.all_done', 'Zusagen an den Kunden erledigt', 'Promesses au client réalisées', 'Promesse al cliente evase', 'Promises to the customer done'],
        'paid' => [true, 'sale.paid', 'Bezahlt bzw. Leasing ausbezahlt', 'Payé ou leasing versé', 'Pagato o leasing versato', 'Paid or leasing paid out'],
        'revocation' => [false, 'financing.revocation_ended', 'Widerrufsfrist Leasing abgelaufen', 'Délai de révocation du leasing échu', 'Termine di revoca del leasing scaduto', 'Leasing revocation period over'],
        'warranty' => [true, 'warranty.registered', 'Garantie registriert (Policennummer)', 'Garantie enregistrée (numéro de police)', 'Garanzia registrata (numero di polizza)', 'Warranty registered (policy number)'],
        'contract_signed' => [false, 'document:sales_contract:signed', 'Kaufvertrag unterschrieben', 'Contrat de vente signé', 'Contratto di vendita firmato', 'Sales contract signed'],
        'keys' => [true, null, 'Schlüssel, Fahrzeugausweis und Serviceheft übergeben', 'Clés, permis de circulation et carnet d’entretien remis', 'Chiavi, licenza di circolazione e libretto di servizio consegnati', 'Keys, registration document and service book handed over'],
        'protocol' => [false, 'document:handover_protocol', 'Übergabeprotokoll im Dossier', 'Procès-verbal de livraison dans le dossier', 'Verbale di consegna nel fascicolo', 'Handover protocol in the file'],
    ];

    public const FINANCING_PARTNER = [
        'leasing_contract' => [true, 'document:leasing_contract', 'Leasingvertrag unterschrieben', 'Contrat de leasing signé', 'Contratto di leasing firmato', 'Leasing contract signed'],
        'insurance' => [true, null, 'Bestätigung Vollkaskoversicherung', 'Confirmation d’assurance casco complète', 'Conferma assicurazione casco totale', 'Comprehensive insurance confirmation'],
        'general_terms' => [true, null, 'AGB unterschrieben', 'Conditions générales signées', 'Condizioni generali firmate', 'General terms signed'],
        'budget' => [true, 'document:budget_calculation', 'Budgetberechnung unterschrieben', 'Calcul du budget signé', 'Calcolo del budget firmato', 'Budget calculation signed'],
        'form_a' => [true, null, 'Formular A', 'Formulaire A', 'Modulo A', 'Form A'],
        'purchase_contract' => [true, 'document:sales_contract:signed', 'Kaufvertrag unterschrieben und gestempelt', 'Contrat de vente signé et tamponné', 'Contratto di vendita firmato e timbrato', 'Sales contract signed and stamped'],
        'handover_confirmation' => [true, null, 'Übergabebestätigung von Leasingnehmer und Händler unterschrieben', 'Confirmation de livraison signée par le preneur et le garage', 'Conferma di consegna firmata dal locatario e dal garage', 'Handover confirmation signed by lessee and dealer'],
        'invoice' => [true, 'invoice.issued', 'Rechnung an die Bank inkl. MWST', 'Facture à la banque TVA incluse', 'Fattura alla banca IVA inclusa', 'Invoice to the bank incl. VAT'],
    ];

    public function __invoke(): void
    {
        $this->install(ChecklistKind::Handover, ['de' => 'Übergabe', 'fr' => 'Livraison', 'it' => 'Consegna', 'en' => 'Handover'], self::HANDOVER);
        $this->install(ChecklistKind::FinancingPartner, ['de' => 'Leasing – Unterlagen für die Bank', 'fr' => 'Leasing – documents pour la banque', 'it' => 'Leasing – documenti per la banca', 'en' => 'Leasing – documents for the bank'], self::FINANCING_PARTNER);
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, array{0: bool, 1: string|null, 2: string, 3: string, 4: string, 5: string}>  $items
     */
    private function install(ChecklistKind $kind, array $name, array $items): void
    {
        if (ChecklistTemplate::query()->where('kind', $kind->value)->whereNull('partner_party_id')->exists()) {
            return;
        }

        $template = ChecklistTemplate::create(['kind' => $kind, 'name' => $name, 'version' => 1, 'is_active' => true]);
        $sort = 1;

        foreach ($items as $key => [$required, $rule, $de, $fr, $it, $en]) {
            $template->items()->create([
                'key' => $key,
                'label' => ['de' => $de, 'fr' => $fr, 'it' => $it, 'en' => $en],
                'required' => $required,
                'auto_rule' => $rule,
                'sort' => $sort++,
            ]);
        }
    }
}
