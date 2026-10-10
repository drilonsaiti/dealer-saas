<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\FolderGroup;
use App\Domain\Documents\Models\DocumentCategory;

/**
 * Gives the current dealer the default document categories in the six vehicle-file folders.
 * Idempotent: existing keys (possibly renamed by the dealer) are left alone.
 */
class InstallDefaultDocumentCategories
{
    /**
     * key => [folder, sort, sensitive, de, fr, it, en]
     */
    public const DEFAULTS = [
        'purchase_contract' => [FolderGroup::Purchase, 10, false, 'Kaufvertrag Ankauf', 'Contrat d’achat', 'Contratto d’acquisto', 'Purchase contract'],
        'supplier_invoice' => [FolderGroup::Purchase, 20, false, 'Lieferantenrechnung', 'Facture du fournisseur', 'Fattura del fornitore', 'Supplier invoice'],
        'payment_proof' => [FolderGroup::Purchase, 30, false, 'Zahlungsbeleg', 'Preuve de paiement', 'Prova di pagamento', 'Proof of payment'],
        'seller_identity' => [FolderGroup::Purchase, 40, true, 'Ausweis Verkäufer', 'Pièce d’identité du vendeur', 'Documento d’identità del venditore', 'Seller ID'],
        'registration' => [FolderGroup::VehicleDocuments, 10, true, 'Fahrzeugausweis', 'Permis de circulation', 'Licenza di circolazione', 'Vehicle registration'],
        'coc' => [FolderGroup::VehicleDocuments, 20, false, 'COC / Typenschein', 'COC / certificat de conformité', 'COC / certificato di conformità', 'COC'],
        'inspection_report' => [FolderGroup::VehicleDocuments, 30, false, 'MFK-Bericht', 'Rapport d’expertise', 'Rapporto di collaudo', 'Inspection report (MFK)'],
        'service_history' => [FolderGroup::VehicleDocuments, 40, false, 'Serviceheft', 'Carnet d’entretien', 'Libretto di servizio', 'Service history'],
        'photo' => [FolderGroup::VehicleDocuments, 50, false, 'Foto', 'Photo', 'Foto', 'Photo'],
        'vehicle_other' => [FolderGroup::VehicleDocuments, 90, false, 'Sonstige Fahrzeugunterlagen', 'Autres documents du véhicule', 'Altri documenti del veicolo', 'Other vehicle documents'],
        'workshop_invoice' => [FolderGroup::CostsWorkshop, 10, false, 'Werkstattrechnung', 'Facture d’atelier', 'Fattura d’officina', 'Workshop invoice'],
        'parts_invoice' => [FolderGroup::CostsWorkshop, 20, false, 'Teilerechnung', 'Facture de pièces', 'Fattura ricambi', 'Parts invoice'],
        'transport_invoice' => [FolderGroup::CostsWorkshop, 30, false, 'Transportrechnung', 'Facture de transport', 'Fattura di trasporto', 'Transport invoice'],
        'diagnosis' => [FolderGroup::CostsWorkshop, 40, false, 'Diagnose / Zustandsbericht', 'Diagnostic / rapport d’état', 'Diagnosi / rapporto sullo stato', 'Diagnosis / condition report'],
        'offer' => [FolderGroup::SalePayments, 10, false, 'Offerte', 'Offre', 'Offerta', 'Offer'],
        'sales_contract' => [FolderGroup::SalePayments, 20, false, 'Kaufvertrag Verkauf', 'Contrat de vente', 'Contratto di vendita', 'Sales contract'],
        'invoice' => [FolderGroup::SalePayments, 30, false, 'Rechnung', 'Facture', 'Fattura', 'Invoice'],
        'credit_note' => [FolderGroup::SalePayments, 35, false, 'Gutschrift', 'Note de crédit', 'Nota di credito', 'Credit note'],
        'receipt' => [FolderGroup::SalePayments, 40, false, 'Quittung', 'Quittance', 'Ricevuta', 'Receipt'],
        'handover_protocol' => [FolderGroup::SalePayments, 50, false, 'Übergabeprotokoll', 'Procès-verbal de livraison', 'Verbale di consegna', 'Handover protocol'],
        'buyer_identity' => [FolderGroup::SalePayments, 60, true, 'Ausweis Käufer', 'Pièce d’identité de l’acheteur', 'Documento d’identità dell’acquirente', 'Buyer ID'],
        'customer_upload' => [FolderGroup::SalePayments, 65, false, 'Vom Kunden hochgeladen', 'Envoyé par le client', 'Caricato dal cliente', 'Uploaded by the customer'],
        'correspondence' => [FolderGroup::SalePayments, 70, false, 'Korrespondenz', 'Correspondance', 'Corrispondenza', 'Correspondence'],
        'warranty_policy' => [FolderGroup::Warranty, 10, false, 'Garantiepolice', 'Police de garantie', 'Polizza di garanzia', 'Warranty policy'],
        'warranty_claim' => [FolderGroup::Warranty, 20, false, 'Garantiefall', 'Cas de garantie', 'Caso di garanzia', 'Warranty claim'],
        'warranty_submission' => [FolderGroup::Warranty, 30, false, 'Meldung an Garantieanbieter', 'Annonce au garant', 'Notifica al garante', 'Notice to warranty provider'],
        'leasing_contract' => [FolderGroup::Financing, 10, false, 'Leasingvertrag', 'Contrat de leasing', 'Contratto di leasing', 'Leasing contract'],
        'budget_calculation' => [FolderGroup::Financing, 20, true, 'Budgetberechnung', 'Calcul du budget', 'Calcolo del budget', 'Budget calculation'],
        'financing_checklist' => [FolderGroup::Financing, 30, false, 'Checkliste Finanzierungspartner', 'Liste de contrôle du partenaire', 'Lista di controllo del partner', 'Partner checklist'],
        'vat_report' => [FolderGroup::Company, 10, false, 'MWST-Abrechnung', 'Décompte TVA', 'Rendiconto IVA', 'VAT return'],
        'vat_detail' => [FolderGroup::Company, 20, false, 'MWST-Detail', 'Détail TVA', 'Dettaglio IVA', 'VAT detail'],
        'vat_export' => [FolderGroup::Company, 30, false, 'MWST-Export ESTV', 'Export TVA AFC', 'Esportazione IVA AFC', 'VAT export (ESTV)'],
        'vat_confirmation' => [FolderGroup::Company, 40, false, 'MWST-Einreichungsbestätigung', 'Confirmation de dépôt TVA', 'Conferma di inoltro IVA', 'VAT submission confirmation'],
        'accounting_export' => [FolderGroup::Company, 50, false, 'Buchhaltungsexport', 'Export comptable', 'Esportazione contabile', 'Accounting export'],
        'payout_confirmation' => [FolderGroup::Financing, 40, false, 'Auszahlungsbestätigung', 'Confirmation de versement', 'Conferma di pagamento', 'Payout confirmation'],
    ];

    public function __invoke(): int
    {
        $existing = DocumentCategory::query()->pluck('key')->all();
        $created = 0;

        foreach (self::DEFAULTS as $key => [$folder, $sort, $sensitive, $de, $fr, $it, $en]) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            DocumentCategory::create([
                'key' => $key,
                'name' => ['de' => $de, 'fr' => $fr, 'it' => $it, 'en' => $en],
                'folder_group' => $folder,
                'sensitive' => $sensitive,
                'sort' => $sort,
            ]);

            $created++;
        }

        return $created;
    }
}
