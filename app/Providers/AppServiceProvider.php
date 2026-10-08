<?php

namespace App\Providers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\MorphMap;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Parties\Models\Party;
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
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\TyreSet;
use App\Domain\Vehicles\Models\Vehicle;
use App\Policies\AuditLogPolicy;
use App\Policies\BankAccountPolicy;
use App\Policies\CommitmentPolicy;
use App\Policies\CostCategoryPolicy;
use App\Policies\CostPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentTemplatePolicy;
use App\Policies\ImportRunPolicy;
use App\Policies\NumberSequencePolicy;
use App\Policies\PartyPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\SalePolicy;
use App\Policies\StockCyclePolicy;
use App\Policies\TenantMembershipPolicy;
use App\Policies\TenantPolicy;
use App\Policies\TyreSetPolicy;
use App\Policies\VehiclePolicy;
use App\Support\SwissFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
