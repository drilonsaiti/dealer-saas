<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Enums\TemplateType;

/**
 * Clauses every new dealer starts with, in all four languages. Dealers change them under
 * Settings → Templates (as a new version). The texts must be checked by a Swiss lawyer and
 * a native speaker per language before launch (technical concept, section 15).
 */
final class DefaultTemplates
{
    /**
     * @return array<string, list<string>>
     */
    public static function clauses(TemplateType $type): array
    {
        return match ($type) {
            TemplateType::SalesContract => self::SALES,
            TemplateType::PurchaseContract => self::PURCHASE,
        };
    }

    private const SALES = [
        'de' => [
            'Das Fahrzeug wird in dem Zustand verkauft, in dem es sich bei der Besichtigung und Probefahrt befunden hat. Der Käufer bestätigt, das Fahrzeug besichtigt zu haben.',
            'Jede Gewährleistung des Verkäufers ist ausgeschlossen, soweit gesetzlich zulässig und soweit in diesem Vertrag keine Garantie vereinbart ist. Vorbehalten bleiben arglistig verschwiegene Mängel.',
            'Zusicherungen und Nebenabreden gelten nur, wenn sie in diesem Vertrag festgehalten sind.',
            'Das Fahrzeug wird erst nach vollständiger Bezahlung des Kaufpreises übergeben. Nutzen und Gefahr gehen mit der Übergabe auf den Käufer über.',
            'Der angegebene Kilometerstand entspricht der Anzeige des Kilometerzählers. Für die tatsächliche Laufleistung wird keine Gewähr übernommen, sofern sie nicht ausdrücklich zugesichert ist.',
            'Tritt der Käufer ohne wichtigen Grund vom Vertrag zurück oder nimmt er das Fahrzeug nicht ab, schuldet er dem Verkäufer eine Entschädigung von 15 % des Kaufpreises. Ein höherer Schaden bleibt vorbehalten.',
            'Ein in Zahlung genommenes Fahrzeug wird im beschriebenen Zustand übergeben. Verschwiegene Mängel berechtigen den Verkäufer, den Anrechnungswert anzupassen.',
            'Kosten für Zulassung, Kontrollschilder und Versicherung trägt der Käufer, sofern nichts anderes vereinbart ist.',
            'Es gilt schweizerisches Recht. Gerichtsstand ist der Sitz des Verkäufers.',
        ],
        'fr' => [
            'Le véhicule est vendu dans l’état dans lequel il se trouvait lors de la visite et de l’essai. L’acheteur confirme avoir examiné le véhicule.',
            'Toute garantie du vendeur est exclue dans la mesure permise par la loi et pour autant qu’aucune garantie ne soit convenue dans le présent contrat. Les défauts dissimulés frauduleusement sont réservés.',
            'Les assurances et accords accessoires ne sont valables que s’ils figurent dans le présent contrat.',
            'Le véhicule n’est remis qu’après le paiement intégral du prix de vente. Les profits et les risques passent à l’acheteur lors de la remise.',
            'Le kilométrage indiqué correspond à l’affichage du compteur. Aucune garantie n’est donnée quant au kilométrage effectif, sauf s’il est expressément garanti.',
            'Si l’acheteur se départit du contrat sans juste motif ou ne prend pas livraison du véhicule, il doit au vendeur une indemnité de 15 % du prix de vente. Un dommage supérieur est réservé.',
            'Un véhicule repris est remis dans l’état décrit. Des défauts dissimulés autorisent le vendeur à adapter la valeur de reprise.',
            'Les frais d’immatriculation, de plaques et d’assurance sont à la charge de l’acheteur, sauf convention contraire.',
            'Le droit suisse est applicable. Le for est au siège du vendeur.',
        ],
        'it' => [
            'Il veicolo è venduto nello stato in cui si trovava al momento della visione e della prova su strada. L’acquirente conferma di aver esaminato il veicolo.',
            'È esclusa ogni garanzia del venditore, nella misura consentita dalla legge e salvo che nel presente contratto sia pattuita una garanzia. Sono riservati i difetti taciuti intenzionalmente.',
            'Assicurazioni e accordi accessori sono validi solo se riportati nel presente contratto.',
            'Il veicolo è consegnato solo dopo il pagamento integrale del prezzo di vendita. Utili e rischi passano all’acquirente con la consegna.',
            'Il chilometraggio indicato corrisponde a quello del contachilometri. Non si garantisce il chilometraggio effettivo, salvo che sia espressamente garantito.',
            'Se l’acquirente recede dal contratto senza giustificato motivo o non ritira il veicolo, deve al venditore un’indennità del 15 % del prezzo di vendita. È riservato un danno maggiore.',
            'Un veicolo dato in permuta è consegnato nello stato descritto. Difetti taciuti autorizzano il venditore ad adeguare il valore di permuta.',
            'Le spese di immatricolazione, targhe e assicurazione sono a carico dell’acquirente, salvo diverso accordo.',
            'Si applica il diritto svizzero. Il foro competente è la sede del venditore.',
        ],
        'en' => [
            'The vehicle is sold in the condition it was in at the viewing and test drive. The buyer confirms having inspected the vehicle.',
            'Any warranty by the seller is excluded to the extent permitted by law and unless a warranty is agreed in this contract. Defects fraudulently concealed are reserved.',
            'Assurances and side agreements are only valid if they are recorded in this contract.',
            'The vehicle is handed over only after the purchase price has been paid in full. Benefit and risk pass to the buyer on handover.',
            'The stated mileage is the reading of the odometer. No warranty is given for the actual mileage unless it is expressly assured.',
            'If the buyer withdraws from the contract without good cause or does not take delivery of the vehicle, the buyer owes the seller compensation of 15 % of the purchase price. Higher damages are reserved.',
            'A trade-in vehicle is handed over in the condition described. Concealed defects entitle the seller to adjust the trade-in value.',
            'Costs for registration, number plates and insurance are borne by the buyer unless agreed otherwise.',
            'Swiss law applies. The place of jurisdiction is the seller’s registered office.',
        ],
    ];

    private const PURCHASE = [
        'de' => [
            'Der Verkäufer bestätigt, dass er Eigentümer des Fahrzeugs ist, frei darüber verfügen kann und dass es weder verpfändet noch geleast oder mit Rechten Dritter belastet ist, soweit in diesem Vertrag nichts anderes angegeben ist.',
            'Der Verkäufer bestätigt die Richtigkeit seiner Angaben zu Unfällen, Schäden, Mängeln und Kilometerstand. Bekannte Mängel sind in diesem Vertrag aufgeführt.',
            'Verschweigt der Verkäufer wesentliche Mängel oder Unfallschäden, kann der Käufer vom Vertrag zurücktreten oder den Kaufpreis mindern.',
            'Nutzen und Gefahr gehen mit der Übergabe des Fahrzeugs samt Fahrzeugausweis, allen Schlüsseln und Unterlagen auf den Käufer über.',
            'Eine Ablösung an ein Leasing- oder Finanzierungsinstitut wird direkt an dieses bezahlt und vom Kaufpreis abgezogen.',
            'Es gilt schweizerisches Recht. Gerichtsstand ist der Sitz des Käufers.',
        ],
        'fr' => [
            'Le vendeur confirme être propriétaire du véhicule, pouvoir en disposer librement et que celui-ci n’est ni gagé, ni en leasing, ni grevé de droits de tiers, sauf indication contraire dans le présent contrat.',
            'Le vendeur confirme l’exactitude de ses indications concernant les accidents, dommages, défauts et le kilométrage. Les défauts connus sont mentionnés dans le présent contrat.',
            'Si le vendeur dissimule des défauts importants ou des dommages dus à un accident, l’acheteur peut se départir du contrat ou réduire le prix.',
            'Les profits et les risques passent à l’acheteur lors de la remise du véhicule avec le permis de circulation, toutes les clés et tous les documents.',
            'Un solde dû à une société de leasing ou de financement lui est payé directement et déduit du prix d’achat.',
            'Le droit suisse est applicable. Le for est au siège de l’acheteur.',
        ],
        'it' => [
            'Il venditore conferma di essere proprietario del veicolo, di poterne disporre liberamente e che lo stesso non è dato in pegno, in leasing né gravato da diritti di terzi, salvo diversa indicazione nel presente contratto.',
            'Il venditore conferma l’esattezza delle sue indicazioni su incidenti, danni, difetti e chilometraggio. I difetti noti sono indicati nel presente contratto.',
            'Se il venditore tace difetti importanti o danni da incidente, l’acquirente può recedere dal contratto o ridurre il prezzo.',
            'Utili e rischi passano all’acquirente con la consegna del veicolo, della licenza di circolazione, di tutte le chiavi e di tutti i documenti.',
            'Un saldo dovuto a una società di leasing o di finanziamento è pagato direttamente a questa e dedotto dal prezzo d’acquisto.',
            'Si applica il diritto svizzero. Il foro competente è la sede dell’acquirente.',
        ],
        'en' => [
            'The seller confirms being the owner of the vehicle, being free to dispose of it, and that it is not pledged, leased or encumbered with third-party rights unless stated otherwise in this contract.',
            'The seller confirms that the information on accidents, damage, defects and mileage is correct. Known defects are listed in this contract.',
            'If the seller conceals significant defects or accident damage, the buyer may withdraw from the contract or reduce the price.',
            'Benefit and risk pass to the buyer on handover of the vehicle together with the registration document, all keys and all documents.',
            'Any amount owed to a leasing or financing company is paid to it directly and deducted from the purchase price.',
            'Swiss law applies. The place of jurisdiction is the buyer’s registered office.',
        ],
    ];
}
