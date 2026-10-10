<?php

namespace App\Providers;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\MorphMap;
use App\Domain\Checklists\Models\ChecklistTemplate;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Listings\Listeners\AnnounceListingChanges;
use App\Domain\Listings\Models\Enquiry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Payments\Models\Payment;
use App\Domain\Preparation\Models\ConditionReport;
use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Signatures\Support\DocumentSealer;
use App\Domain\Signatures\Support\LogSmsSender;
use App\Domain\Signatures\Support\PyHankoSealer;
use App\Domain\Signatures\Support\SmsSender;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Domain\Vehicles\Events\StockCycleStatusChanged;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\TyreSet;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Policies\ApiTokenPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BankAccountPolicy;
use App\Policies\BankTransactionPolicy;
use App\Policies\BuybackObligationPolicy;
use App\Policies\ChecklistTemplatePolicy;
use App\Policies\CommitmentPolicy;
use App\Policies\ConditionReportPolicy;
use App\Policies\CostCategoryPolicy;
use App\Policies\CostPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentTemplatePolicy;
use App\Policies\EnquiryPolicy;
use App\Policies\FinancingPolicy;
use App\Policies\ImportRunPolicy;
use App\Policies\IntegrationAccountPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ListingPolicy;
use App\Policies\NumberSequencePolicy;
use App\Policies\PartyPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\RepairOrderPolicy;
use App\Policies\SalePolicy;
use App\Policies\StockCyclePolicy;
use App\Policies\TenantMembershipPolicy;
use App\Policies\TenantPolicy;
use App\Policies\TyreSetPolicy;
use App\Policies\VatPeriodPolicy;
use App\Policies\VatProfilePolicy;
use App\Policies\VehiclePolicy;
use App\Policies\WarrantyClaimPolicy;
use App\Policies\WarrantyPolicy;
use App\Policies\WarrantyProductPolicy;
use App\Policies\WebhookEndpointPolicy;
use App\Support\SwissFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(DocumentSealer::class, PyHankoSealer::class);
        $this->app->bind(SmsSender::class, fn () => match (config('dealer.signatures.sms_driver')) {
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable names in polymorphic columns (audit log, status history) instead of PHP class names.
        Relation::enforceMorphMap(MorphMap::MAP);

        $this->registerPolicies();
        $this->configureFilamentLayout();
        $this->carryTenantIntoQueuedJobs();

        // A rollback also rolls back set_config() calls made inside the transaction;
        // re-sync the database settings with the context the application believes in.
        Event::listen(TransactionRolledBack::class, fn () => $this->app->make(TenantContext::class)->reapply());

        Event::listen(StockCycleStatusChanged::class, AnnounceListingChanges::class);

        // Public API: per token (the website plugin), enquiries additionally per visitor IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('token:'.($request->attributes->get('api_token_id') ?? $request->ip())));
        RateLimiter::for('api-enquiries', fn (Request $request) => [
            Limit::perMinute(10)->by('token:'.($request->attributes->get('api_token_id') ?? 'none')),
            Limit::perHour(5)->by('visitor:'.($request->input('visitor_ip') ?: $request->ip())),
        ]);
    }

    /**
     * Filament's root form schema has two columns, so a lone Grid or Section would fill only the
     * left half of every modal and page. Ours always take the full width and lay out their own columns.
     */
    private function configureFilamentLayout(): void
    {
        Grid::configureUsing(fn (Grid $grid): Grid => $grid->columnSpanFull());
        Section::configureUsing(fn (Section $section): Section => $section->columnSpanFull());

        // Swiss date format (14.07.2026) in every UI language.
        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat(SwissFormat::DATE)
            ->defaultDateTimeDisplayFormat(SwissFormat::DATE_TIME));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultDateDisplayFormat(SwissFormat::DATE)
            ->defaultDateTimeDisplayFormat(SwissFormat::DATE_TIME));
        DatePicker::configureUsing(fn (DatePicker $picker): DatePicker => $picker
            ->native(false)
            ->displayFormat(SwissFormat::DATE)
            ->firstDayOfWeek(1));
    }

    private function registerPolicies(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(TenantMembership::class, TenantMembershipPolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(NumberSequence::class, NumberSequencePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(StockCycle::class, StockCyclePolicy::class);
        Gate::policy(TyreSet::class, TyreSetPolicy::class);
        Gate::policy(Party::class, PartyPolicy::class);
        Gate::policy(Purchase::class, PurchasePolicy::class);
        Gate::policy(Cost::class, CostPolicy::class);
        Gate::policy(CostCategory::class, CostCategoryPolicy::class);
        Gate::policy(Commitment::class, CommitmentPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(ImportRun::class, ImportRunPolicy::class);
        Gate::policy(DocumentTemplate::class, DocumentTemplatePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(BankTransaction::class, BankTransactionPolicy::class);
        Gate::policy(VatProfile::class, VatProfilePolicy::class);
        Gate::policy(VatPeriod::class, VatPeriodPolicy::class);
        Gate::policy(Financing::class, FinancingPolicy::class);
        Gate::policy(BuybackObligation::class, BuybackObligationPolicy::class);
        Gate::policy(Warranty::class, WarrantyPolicy::class);
        Gate::policy(WarrantyClaim::class, WarrantyClaimPolicy::class);
        Gate::policy(WarrantyProduct::class, WarrantyProductPolicy::class);
        Gate::policy(ChecklistTemplate::class, ChecklistTemplatePolicy::class);
        Gate::policy(ConditionReport::class, ConditionReportPolicy::class);
        Gate::policy(RepairOrder::class, RepairOrderPolicy::class);
        Gate::policy(Listing::class, ListingPolicy::class);
        Gate::policy(Enquiry::class, EnquiryPolicy::class);
        Gate::policy(ApiToken::class, ApiTokenPolicy::class);
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
        Gate::policy(IntegrationAccount::class, IntegrationAccountPolicy::class);
    }

    /**
     * A job dispatched while a tenant is active runs as that tenant, so RLS keeps protecting it.
     */
    private function carryTenantIntoQueuedJobs(): void
    {
        Queue::createPayloadUsing(function (): array {
            $context = $this->app->make(TenantContext::class);

            return ['tenant_id' => $context->id()];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            // Synchronous jobs run inside the caller's request and already share its context.
            if ($event->connectionName === 'sync') {
                return;
            }

            $context = $this->app->make(TenantContext::class);
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            $context->clear();

            if (is_string($tenantId)) {
                $tenant = $context->bypass(fn (): ?Tenant => Tenant::query()->find($tenantId));
                $context->set($tenant);
            }
        });

        $clear = function (JobProcessed|JobExceptionOccurred $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(TenantContext::class)->clear();
            }
        };

        Event::listen(JobProcessed::class, $clear);
        Event::listen(JobExceptionOccurred::class, $clear);
    }
}
