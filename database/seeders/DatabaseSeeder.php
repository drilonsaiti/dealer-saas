<?php

namespace Database\Seeders;

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\HandOverVehicle;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vehicles\Actions\RecordVehicle;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local demo data: one platform admin and two dealers (to see tenant separation).
 * Password for all demo users: "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantContext $context, CreateTenant $createTenant): void
    {
        User::query()->firstOrCreate(
            ['email' => 'platform@example.ch'],
            ['name' => 'Platform Admin', 'password' => Hash::make('password'), 'is_platform_admin' => true, 'email_verified_at' => now()],
        );

        $demo = [
            ['name' => 'Demo Garage Bern', 'street' => 'Freiburgstrasse 251', 'zip' => '3018', 'city' => 'Bern', 'vat_number' => 'CHE-123.456.788 MWST', 'default_locale' => 'de', 'admin' => 'admin@demo-bern.example.ch'],
            ['name' => 'Garage Démo Vevey', 'street' => 'Avenue de Gilamont 40', 'zip' => '1800', 'city' => 'Vevey', 'vat_number' => null, 'default_locale' => 'fr', 'admin' => 'admin@demo-vevey.example.ch'],
        ];

        foreach ($demo as $row) {
            if ($context->bypass(fn () => Tenant::query()->where('name', $row['name'])->exists())) {
                continue;
            }

            $tenant = $createTenant(
                ['name' => $row['name'], 'street' => $row['street'], 'zip' => $row['zip'], 'city' => $row['city'], 'uid' => $row['vat_number'] === null ? null : substr($row['vat_number'], 0, 15), 'vat_number' => $row['vat_number'], 'default_locale' => $row['default_locale']],
                $row['admin'],
                'Admin '.$row['city'],
                sendInvitation: false,
            );

            User::query()->where('email', $row['admin'])->update([
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);

            $context->run($tenant, fn () => BankAccount::factory()->create(['label' => 'Hauptkonto']));

            if ($row['city'] === 'Bern') {
                // Like Aziri: net tax rate method, 0.6 % for car trade, agreed consideration,
                // half-yearly. The ESTV activity code stays empty (it comes from the approval).
                $context->run($tenant, fn () => app(SaveVatProfile::class)(null, [
                    'valid_from' => now()->startOfYear()->toDateString(), 'vat_number' => $row['vat_number'],
                    'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year',
                ], [['activity' => 'Autohandel', 'rate' => '0.6']]));
                $context->run($tenant, fn () => $this->demoVehicles());
            }
        }
    }

    /**
     * A few cars in different stages, so every screen has something to show.
     */
    private function demoVehicles(): void
    {
        $record = app(RecordVehicle::class);
        $transition = app(TransitionStockCycle::class);

        $reflex = Party::factory()->company('Reflex Automobiles Sàrl')->create(['street' => 'Avenue de Morges 12', 'email' => 'achat@reflex-auto.example.ch']);
        $privateSeller = Party::create(['kind' => 'person', 'roles' => ['private_seller'], 'first_name' => 'Marco', 'last_name' => 'Keller', 'zip' => '3006', 'city' => 'Bern', 'mobile' => '079 555 12 34']);
        $purchase = fn (Party $seller, string $sellerKind, int $price, int $daysAgo): array => [
            'seller_party_id' => $seller->id,
            'seller_kind' => $sellerKind,
            'contract_on' => now()->subDays($daysAgo)->toDateString(),
            'price_rp' => $price,
            'vat_situation' => $sellerKind === 'private' ? 'private_no_vat' : 'company_no_vat_shown',
        ];

        $corolla = $record(
            ['stammnummer' => '683737537', 'vin' => 'JTDKB20U403012345', 'make' => 'Toyota', 'model' => 'Corolla', 'variant' => '1.8 Hybrid', 'fuel' => 'hybrid', 'transmission' => 'automatic', 'body_type' => 'hatchback', 'first_registration_on' => '2020-06-19', 'power_kw' => 90, 'color_exterior' => 'Weiss'],
            ['mileage_in' => 79310, 'planned_price_rp' => 1_650_000, 'list_price_rp' => 1_890_000],
            $purchase($reflex, 'company', 1_520_000, 48),
        );
        $transition($corolla, StockCycleStatus::Arrived, data: ['on' => now()->subDays(45)->toDateString()]);
        $transition($corolla, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(30)->toDateString()]);
        $this->demoPhoto($corolla, 'Toyota Corolla', [236, 236, 236]);
        $this->demoPdf($corolla, 'purchase_contract', 'Kaufvertrag Toyota Corolla 1.8 Hybrid, Stammnummer 683.737.537, Preis CHF 15200.00', now()->subDays(48)->toDateString());
        $transition($corolla, StockCycleStatus::Listed, data: ['on' => now()->subDays(29)->toDateString()]);

        $x3 = $record(
            ['stammnummer' => '412558903', 'make' => 'BMW', 'model' => 'X3', 'variant' => 'xDrive30i', 'internal_label' => 'BMW X3 Blau Shema', 'fuel' => 'petrol', 'transmission' => 'automatic', 'drive' => 'all_wheel', 'body_type' => 'suv', 'first_registration_on' => '2019-03-12', 'power_kw' => 185, 'color_exterior' => 'Blau'],
            ['mileage_in' => 98200, 'planned_price_rp' => 2_690_000],
            $purchase($reflex, 'company', 2_350_000, 95),
        );
        $transition($x3, StockCycleStatus::Arrived);
        $transition($x3, StockCycleStatus::InPreparation);

        $category = fn (string $key): string => (string) CostCategory::query()->where('key', $key)->value('id');
        $recordCost = app(RecordCost::class);
        $transport = $recordCost(['stock_cycle_id' => $corolla->id, 'category_id' => $category('transport'), 'incurred_on' => now()->subDays(46)->toDateString(), 'description' => 'Transport Lausanne – Bern', 'gross_rp' => 35_000]);
        app(ConfirmCost::class)($transport);
        $recordCost(['stock_cycle_id' => $corolla->id, 'category_id' => $category('preparation'), 'incurred_on' => now()->subDays(32)->toDateString(), 'description' => 'Innen- und Aussenreinigung', 'gross_rp' => 28_000]);
        $recordCost(['stock_cycle_id' => $x3->id, 'category_id' => $category('repair'), 'incurred_on' => now()->subDays(5)->toDateString(), 'description' => 'Bremsen vorne (Offerte)', 'gross_rp' => 120_000, 'is_estimate' => true]);
        Commitment::create(['stock_cycle_id' => $corolla->id, 'description' => '4 neue Sommerreifen', 'estimated_cost_rp' => 64_000]);

        $record(
            ['stammnummer' => '653461306', 'make' => 'VW', 'model' => 'Golf', 'variant' => '2.0 TDI', 'fuel' => 'diesel', 'body_type' => 'hatchback'],
            ['mileage_in' => 121000, 'planned_price_rp' => 1_190_000],
        );

        $tucson = $record(
            ['stammnummer' => '507112840', 'make' => 'Hyundai', 'model' => 'Tucson', 'variant' => '1.6 T-GDi', 'fuel' => 'hybrid', 'body_type' => 'suv'],
            ['mileage_in' => 42000, 'list_price_rp' => 2_450_000],
            $purchase($privateSeller, 'private', 2_050_000, 120),
        );
        $transition($tucson, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(100)->toDateString()]);
        $this->demoPhoto($tucson, 'Hyundai Tucson', [60, 70, 80]);
        $transition($tucson, StockCycleStatus::Listed, data: ['on' => now()->subDays(99)->toDateString()]);
        $buyer = Party::create(['kind' => 'person', 'roles' => ['customer'], 'salutation' => 'ms', 'first_name' => 'Anna', 'last_name' => 'Meier', 'street' => 'Länggassstrasse 20', 'zip' => '3012', 'city' => 'Bern', 'email' => 'anna.meier@example.ch', 'mobile' => '079 222 33 44']);
        $sale = app(ContractSale::class)($tucson, [
            'buyer_party_id' => $buyer->id,
            'price_rp' => 2_450_000,
            'discount_rp' => 50_000,
            'payment_type' => 'bank',
            'sale_on' => now()->subDays(50)->toDateString(),
            'items' => [['kind' => 'warranty', 'description' => 'Garantie 12 Monate', 'qty' => 1, 'unit_price_rp' => 49_000]],
            'trade_in' => [
                'vehicle' => ['stammnummer' => '311908112', 'make' => 'VW', 'model' => 'Polo', 'variant' => '1.0 TSI'],
                'mileage' => 112_000,
                'value_rp' => 450_000,
            ],
        ]);
        // Historical demo sale: its handover checklist is taken as done.
        app(SyncChecklist::class)->handover($sale)->items->each(fn (ChecklistItem $item) => $item->forceFill(['done_at' => now()->subDays(45), 'auto' => false])->save());
        app(HandOverVehicle::class)($sale, 42_150, now()->subDays(45)->toDateString());

        $octavia = $record(
            ['stammnummer' => '228461775', 'make' => 'Skoda', 'model' => 'Octavia', 'variant' => 'Combi 2.0 TDI 4x4', 'fuel' => 'diesel', 'drive' => 'all_wheel', 'body_type' => 'estate'],
            ['mileage_in' => 64_500, 'list_price_rp' => 2_290_000],
            $purchase($reflex, 'company', 1_880_000, 20),
        );
        $transition($octavia, StockCycleStatus::ReadyForSale, data: ['on' => now()->subDays(12)->toDateString()]);
        $this->demoPhoto($octavia, 'Skoda Octavia', [40, 80, 140]);
        $transition($octavia, StockCycleStatus::Listed, data: ['on' => now()->subDays(12)->toDateString()]);
        $customer = Party::create(['kind' => 'person', 'roles' => ['customer'], 'salutation' => 'mr', 'first_name' => 'Luca', 'last_name' => 'Rossi', 'zip' => '6900', 'city' => 'Lugano', 'locale' => 'it', 'mobile' => '076 111 22 33']);
        app(ReserveVehicle::class)($octavia, ['buyer_party_id' => $customer->id, 'price_rp' => 2_290_000, 'locale' => 'it', 'reserved_until' => now()->addDay()->toDateString()]);

        // Leasing bank and warranty products for the Phase 2 leasing / warranty workflow.
        Party::create(['kind' => 'company', 'roles' => ['financing_partner'], 'company_name' => 'Demo Leasing Bank AG', 'street' => 'Bahnhofstrasse 1', 'zip' => '8001', 'city' => 'Zürich', 'email' => 'leasing@example.ch']);
        $provider = Party::create(['kind' => 'company', 'roles' => ['warranty_provider'], 'company_name' => 'Demo Garantie AG', 'zip' => '6300', 'city' => 'Zug']);
        WarrantyProduct::create(['provider_party_id' => $provider->id, 'name' => ['de' => 'Garantie Plus 12 Monate', 'fr' => 'Garantie Plus 12 mois', 'it' => 'Garanzia Plus 12 mesi', 'en' => 'Warranty Plus 12 months'], 'duration_months' => 12, 'km_limit' => 20_000, 'coverage_limit_rp' => 1_000_000, 'deductible_rp' => 20_000, 'cost_rp' => 39_000, 'price_rp' => 69_000]);
        WarrantyProduct::create(['name' => ['de' => 'Eigene Garantie 6 Monate', 'fr' => 'Garantie maison 6 mois', 'it' => 'Garanzia propria 6 mesi', 'en' => 'Own warranty 6 months'], 'duration_months' => 6, 'km_limit' => 10_000, 'price_rp' => 0]);
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function demoPhoto(StockCycle $cycle, string $label, array $rgb): void
    {
        $image = imagecreatetruecolor(800, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        $ink = array_sum($rgb) > 380 ? imagecolorallocate($image, 30, 30, 30) : imagecolorallocate($image, 245, 245, 245);
        imagestring($image, 5, 30, 30, $label, $ink);
        $path = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        imagejpeg($image, $path, 85);

        $category = DocumentCategory::query()->where('key', 'photo')->firstOrFail();
        app(StoreDocument::class)($path, $category, ['original_name' => 'front.jpg'], [$cycle]);
        unlink($path);
    }

    /**
     * A one-page PDF with a line of real text (so search and OCR have something to find).
     */
    private function demoPdf(StockCycle $cycle, string $categoryKey, string $text, string $date): void
    {
        $stream = 'BT /F1 11 Tf 50 780 Td ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'demo').'.pdf';
        file_put_contents($path, $pdf);

        $category = DocumentCategory::query()->where('key', $categoryKey)->firstOrFail();
        app(StoreDocument::class)($path, $category, ['original_name' => 'kaufvertrag.pdf', 'document_on' => $date, 'locale' => 'de'], [$cycle]);
        unlink($path);
    }
}
