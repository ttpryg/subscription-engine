# Subscription Engine (`ttpryg/subscription-engine`)

[![Tests](https://github.com/ttpryg/subscription-engine/actions/workflows/tests.yml/badge.svg)](https://github.com/ttpryg/subscription-engine/actions)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Framework-agnostic PHP 8.1+ Subscription, Recurring Billing, and Plan Management Engine built with Clean Architecture, Domain-Driven Design (DDD), and PSR-14 Event-Driven principles.

---

## Key Features

- **State Machine Lifecycle**: Strict, guarded lifecycle state transitions (`TRIALING` -> `ACTIVE` <-> `PAST_DUE` -> `CANCELED` / `EXPIRED` / `PAUSED`).
- **Decoupled Payment Gateway**: Framework-agnostic and gateway-neutral (Midtrans, Xendit, Stripe, Doku, etc.). The engine issues invoices and dispatches domain events; your application charges the customer and reports back.
- **Proration Engine**: Supports both scheduled plan changes (applied at the next renewal cycle) and immediate plan upgrades/downgrades with automatic proration calculation.
- **Atomic Concurrency & DB Safety**: Atomic upserts on MySQL/MariaDB and SQLite, safe idempotent webhook payment processing, and optional database transaction manager.
- **Time-Travel Testability**: Full PSR-20 `Psr\Clock\ClockInterface` support with `FixedClock` and `SystemClock` for reliable unit tests and time simulations.
- **Leap Year & Month Clamping**: Month and year calendar math automatically clamps to the end of the month (e.g., Jan 31 + 1 month = Feb 28/29) without overflowing.

---

## Architecture Overview

```
                      ┌──────────────┐
                      │  subscribe   │
                      └──────┬───────┘
                             │
            ┌────────────────┴────────────────┐
            ▼                                 ▼
      ┌───────────┐                     ┌───────────┐
      │ TRIALING  │────(trial ends)────►│  ACTIVE   │◄───(payment)────┐
      └─────┬─────┘                     └─────┬─────┘                 │
            │                                 │   (invoice overdue)   │
            │         ┌───────────────┐       │                       │
            ├────────►│    PAUSED     │◄──────┤                       │
            │         └───────┬───────┘       │                       │
            │                 │ (resume)      ▼                       ▼
            │                 └─────────► CANCELED /              PAST_DUE
            ▼                             EXPIRED                     │
      (cancel/expire)                         ▲                       │
            │                                 │ (period ends unpaid)  │
            └─────────────────────────────────┴───────────────────────┘
```

---

## Installation

```bash
composer require ttpryg/subscription-engine
```

### Requirements
- PHP 8.1 or higher
- PDO Extension (`ext-pdo`) with a MySQL/MariaDB or SQLite driver

### Supported Databases
- **SQLite** — used by the default test suite.
- **MySQL 8+ / MariaDB** — integration-tested in CI.

PostgreSQL is not supported by the shipped `database/schema.sql`.

---

## Database Schema

Import `database/schema.sql` into your database:

```bash
mysql -u root -p my_database < database/schema.sql
```

The schema defines three main tables:
1. `subscription_plans`: Plan configurations, prices, billing intervals, and trial durations.
2. `subscriptions`: User subscriptions, period timestamps, statuses, and pending plan changes.
3. `subscription_invoices`: Issued invoices, amounts, due dates, payment references, and retry counters.

### Versioned Migrations

Instead of importing the SQL manually, you can apply the schema (and any future
incremental migrations) programmatically:

```php
use Ttpryg\SubscriptionEngine\Infrastructure\Database\DatabaseMigrator;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'root', 'secret');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

(new DatabaseMigrator($pdo))->migrate();
```

`DatabaseMigrator`:

- **Fresh install** — runs `database/schema.sql` (the latest schema snapshot)
  and baselines every migration so it is not re-applied.
- **Existing database** — runs only the incremental migrations in
  `database/migrations/` that are not yet recorded, leaving existing data
  untouched. Applied migrations are tracked in the `subscription_engine_migrations`
  table, so `migrate()` is safe to run repeatedly.

Migration files are PHP classes named `YYYYMMDDHHMM_description.php` and return
a `Migration` instance:

```php
use Ttpryg\SubscriptionEngine\Infrastructure\Database\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX idx_example ON subscriptions(user_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX idx_example');
    }
};
```

Revert the most recently applied migration with `rollback()`:

```php
use Ttpryg\SubscriptionEngine\Infrastructure\Database\MigrationRunner;

// Revert the last applied migration (throws if it has no safe down()).
(new MigrationRunner($pdo))->rollback();

// Revert the last two migrations.
(new MigrationRunner($pdo))->rollback(2);
```

---

## Quick Start Guide

### 1. Initializing Services

The example below uses `ttpryg/event-dispatcher`. Install it with
`composer require ttpryg/event-dispatcher`, or pass any
`Psr\EventDispatcher\EventDispatcherInterface` implementation.

```php
use PDO;
use Ttpryg\EventDispatcher\Dispatcher;
use Ttpryg\SubscriptionEngine\Domain\Clock\SystemClock;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\Pdo\PdoInvoiceRepository;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\Pdo\PdoPlanRepository;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\Pdo\PdoSubscriptionRepository;
use Ttpryg\SubscriptionEngine\Application\Service\PlanService;
use Ttpryg\SubscriptionEngine\Application\Service\SubscriptionService;

// Setup PDO connection
$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', 'user', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Initialize Repositories
$planRepo = new PdoPlanRepository($pdo);
$subscriptionRepo = new PdoSubscriptionRepository($pdo);
$invoiceRepo = new PdoInvoiceRepository($pdo);

// Initialize Event Dispatcher (PSR-14)
$dispatcher = new Dispatcher();

// Initialize Services
$planService = new PlanService($planRepo, $dispatcher);

$subscriptionService = new SubscriptionService(
    planRepository: $planRepo,
    subscriptionRepository: $subscriptionRepo,
    invoiceRepository: $invoiceRepo,
    clock: new SystemClock(),
    eventDispatcher: $dispatcher,
    graceDays: 3,
    transactionManager: function (callable $fn) use ($pdo) {
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
);
```

---

### 2. Managing Plans

```php
use Ttpryg\SubscriptionEngine\Application\DTO\CreatePlanRequest;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;

// Create a Monthly Pro Plan with 7 days trial
$plan = $planService->createPlan(new CreatePlanRequest(
    id: 'plan_pro_monthly',
    name: 'Pro Monthly',
    price: 150000.0, // 150,000 IDR
    interval: BillingInterval::MONTH,
    intervalCount: 1,
    currency: 'IDR',
    trialDays: 7,
    description: 'Full access to all Pro features'
));

// Update price or deactivate plan
$planService->changePrice('plan_pro_monthly', 175000.0);
$planService->deactivatePlan('plan_pro_monthly');
```

---

### 3. Creating & Managing Subscriptions

#### Starting a Subscription
```php
use Ttpryg\SubscriptionEngine\Application\DTO\SubscribeRequest;

// User starts on a 7-day trial (status: TRIALING)
$sub = $subscriptionService->subscribe(new SubscribeRequest(
    id: 'sub_' . bin2hex(random_bytes(8)),
    userId: 'user_123',
    planId: 'plan_pro_monthly'
));

// Start directly without trial (status: ACTIVE, issues initial invoice)
$sub = $subscriptionService->subscribe(new SubscribeRequest(
    id: 'sub_' . bin2hex(random_bytes(8)),
    userId: 'user_123',
    planId: 'plan_pro_monthly',
    skipTrial: true
));
```

#### Checking Access (Feature Gating)
```php
if ($subscriptionService->hasAccess(userId: 'user_123')) {
    // User has an active subscription or is currently trialing
}
```

#### Changing Plans (Scheduled vs Immediate Proration)
```php
use Ttpryg\SubscriptionEngine\Application\DTO\ChangePlanRequest;

// Option A: Schedule plan change for next billing renewal cycle (no proration)
$subscriptionService->changePlan(new ChangePlanRequest(
    subscriptionId: 'sub_123',
    newPlanId: 'plan_enterprise',
    immediate: false
));

// Option B: Immediate upgrade with automatic prorated invoice
$subscriptionService->changePlan(new ChangePlanRequest(
    subscriptionId: 'sub_123',
    newPlanId: 'plan_enterprise',
    immediate: true
));
```

#### Cancellation & Pause
```php
use Ttpryg\SubscriptionEngine\Application\DTO\CancelSubscriptionRequest;

// Cancel at period end (user keeps access until end of current billing cycle)
$subscriptionService->cancel(new CancelSubscriptionRequest(
    subscriptionId: 'sub_123',
    atPeriodEnd: true
));

// Undo scheduled cancellation
$subscriptionService->undoCancellation('sub_123');

// Immediate cancellation (revokes access and voids outstanding unpaid invoices)
$subscriptionService->cancel(new CancelSubscriptionRequest(
    subscriptionId: 'sub_123',
    atPeriodEnd: false
));

// Pause subscription (extends billing period by paused duration when resumed)
$subscriptionService->pause('sub_123');
$subscriptionService->resume('sub_123');
```

---

### 4. Payment Collection & Webhook Handling

Because payment collection is decoupled, you listen to `SubscriptionRenewalDueEvent` to trigger a charge with your payment gateway:

```php
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionRenewalDueEvent;

$dispatcher->listen(SubscriptionRenewalDueEvent::class, function (SubscriptionRenewalDueEvent $event) {
    $invoice = $event->invoice;
    
    // Charge customer card or create a Payment Gateway checkout (e.g. Midtrans, Xendit, Stripe)
    // $gateway->charge($event->subscription->userId, $invoice->amount, $invoice->id);
});
```

When your payment gateway webhook arrives, report the result:

```php
use Ttpryg\SubscriptionEngine\Application\DTO\RecordPaymentFailureRequest;
use Ttpryg\SubscriptionEngine\Application\DTO\RecordPaymentRequest;

// Webhook: Payment Success (Safe & Idempotent)
$subscriptionService->recordPayment(new RecordPaymentRequest(
    invoiceId: $payload['order_id'],
    paymentReference: $payload['transaction_id']
));

// Webhook: Payment Failure
$subscriptionService->recordPaymentFailure(new RecordPaymentFailureRequest(
    invoiceId: $payload['order_id'],
    reason: $payload['status_message']
));
```

---

### 5. Scheduled Renewal Cron Worker

Run `processRenewals()` periodically via cron (e.g., every 5 to 15 minutes):

```php
// bin/process-renewals.php
$summary = $subscriptionService->processRenewals();

echo sprintf(
    "Renewals processed: %d past due, %d renewed, %d expired\n",
    $summary['past_due'],
    $summary['renewed'],
    $summary['expired']
);
```

Cron setup:
```bash
*/10 * * * * php /path/to/project/bin/process-renewals.php >> /var/log/renewals.log 2>&1
```

---

## Domain Events

| Event | Dispatched When |
| :--- | :--- |
| `PlanCreatedEvent` | A new plan is created. |
| `SubscriptionCreatedEvent` | A new subscription is created (trialing or active). |
| `SubscriptionRenewalDueEvent` | A new invoice is issued and awaiting payment collection. |
| `SubscriptionPaymentSucceededEvent` | An invoice is marked paid. |
| `SubscriptionPaymentFailedEvent` | A payment attempt fails (increments attempt count). |
| `SubscriptionPlanChangedEvent` | A plan change is scheduled or immediately applied. |
| `SubscriptionPausedEvent` | A subscription is paused. |
| `SubscriptionResumedEvent` | A paused subscription is resumed. |
| `SubscriptionPastDueEvent` | An unpaid invoice exceeds the grace period. |
| `SubscriptionRenewedEvent` | A subscription is renewed into a new billing period. |
| `SubscriptionCanceledEvent` | A subscription is canceled. |
| `SubscriptionExpiredEvent` | A subscription period ends without renewal. |

---

## Testing & Code Quality

Run tests and code quality tools:

```bash
# Run unit & integration tests
composer test

# Run static analysis
composer analyse

# Check code formatting & refactoring
composer fix:check

# Apply code formatting & refactoring
composer fix
```

### Development with Docker

If you don't want to install PHP locally, a PHP 8.1 CLI development container is
provided (no web server — this is a library, not an application):

```bash
# Build & start the dev container
docker compose up -d --build

# Install dependencies inside the container
docker compose exec app composer install

# Run the quality tools
docker compose exec app composer test
docker compose exec app composer analyse
docker compose exec app composer fix

# Stop & remove the container
docker compose down
```

---

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).
