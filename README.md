# Subscription Engine (`ttpryg/subscription-engine`)

[![Tests](https://github.com/totoprayogo1916/subscription-engine/actions/workflows/tests.yml/badge.svg)](https://github.com/totoprayogo1916/subscription-engine/actions)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Framework-agnostic PHP 8.1+ Subscription, Recurring Billing, and Plan Management Engine built with Clean Architecture, Domain-Driven Design (DDD), and PSR-14 Event-Driven principles.

---

## Key Features

- **State Machine Lifecycle**: Strict, guarded lifecycle state transitions (`TRIALING` -> `ACTIVE` <-> `PAST_DUE` -> `CANCELED` / `EXPIRED` / `PAUSED`).
- **Decoupled Payment Gateway**: Framework-agnostic and gateway-neutral (Midtrans, Xendit, Stripe, Doku, etc.). The engine issues invoices and dispatches domain events; your application charges the customer and reports back.
- **Proration Engine**: Supports both scheduled plan changes (applied at the next renewal cycle) and immediate plan upgrades/downgrades with automatic proration calculation.
- **Atomic Concurrency & DB Safety**: Atomic upserts on MySQL/MariaDB and SQLite/PostgreSQL, safe idempotent webhook payment processing, and optional database transaction manager.
- **Time-Travel Testability**: Full `ClockInterface` support with `FixedClock` and `SystemClock` for reliable unit tests and time simulations.
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
- PDO Extension (`ext-pdo`) with MySQL/MariaDB or SQLite/PostgreSQL driver

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

---

## Quick Start Guide

### 1. Initializing Services

```php
use PDO;
use Ttpryg\EventDispatcher\Dispatcher;
use Ttpryg\SubscriptionEngine\Clock\SystemClock;
use Ttpryg\SubscriptionEngine\Repositories\PdoInvoiceRepository;
use Ttpryg\SubscriptionEngine\Repositories\PdoPlanRepository;
use Ttpryg\SubscriptionEngine\Repositories\PdoSubscriptionRepository;
use Ttpryg\SubscriptionEngine\Services\PlanService;
use Ttpryg\SubscriptionEngine\Services\SubscriptionService;

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
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;

// Create a Monthly Pro Plan with 7 days trial
$plan = $planService->createPlan(
    id: 'plan_pro_monthly',
    name: 'Pro Monthly',
    price: 150000.0, // 150,000 IDR
    interval: BillingInterval::MONTH,
    intervalCount: 1,
    currency: 'IDR',
    trialDays: 7,
    description: 'Full access to all Pro features'
);

// Update price or deactivate plan
$planService->changePrice('plan_pro_monthly', 175000.0);
$planService->deactivatePlan('plan_pro_monthly');
```

---

### 3. Creating & Managing Subscriptions

#### Starting a Subscription
```php
// User starts on a 7-day trial (status: TRIALING)
$sub = $subscriptionService->subscribe(
    id: 'sub_' . bin2hex(random_bytes(8)),
    userId: 'user_123',
    planId: 'plan_pro_monthly'
);

// Start directly without trial (status: ACTIVE, issues initial invoice)
$sub = $subscriptionService->subscribe(
    id: 'sub_' . bin2hex(random_bytes(8)),
    userId: 'user_123',
    planId: 'plan_pro_monthly',
    skipTrial: true
);
```

#### Checking Access (Feature Gating)
```php
if ($subscriptionService->hasAccess(userId: 'user_123')) {
    // User has an active subscription or is currently trialing
}
```

#### Changing Plans (Scheduled vs Immediate Proration)
```php
// Option A: Schedule plan change for next billing renewal cycle (no proration)
$subscriptionService->changePlan('sub_123', 'plan_enterprise', immediate: false);

// Option B: Immediate upgrade with automatic prorated invoice
$subscriptionService->changePlan('sub_123', 'plan_enterprise', immediate: true);
```

#### Cancellation & Pause
```php
// Cancel at period end (user keeps access until end of current billing cycle)
$subscriptionService->cancel('sub_123', atPeriodEnd: true);

// Undo scheduled cancellation
$subscriptionService->undoCancellation('sub_123');

// Immediate cancellation (revokes access and voids outstanding unpaid invoices)
$subscriptionService->cancel('sub_123', atPeriodEnd: false);

// Pause subscription (extends billing period by paused duration when resumed)
$subscriptionService->pause('sub_123');
$subscriptionService->resume('sub_123');
```

---

### 4. Payment Collection & Webhook Handling

Because payment collection is decoupled, you listen to `SubscriptionRenewalDueEvent` to trigger a charge with your payment gateway:

```php
use Ttpryg\SubscriptionEngine\Events\SubscriptionRenewalDueEvent;

$dispatcher->listen(SubscriptionRenewalDueEvent::class, function (SubscriptionRenewalDueEvent $event) {
    $invoice = $event->invoice;
    
    // Charge customer card or create a Payment Gateway checkout (e.g. Midtrans, Xendit, Stripe)
    // $gateway->charge($event->subscription->userId, $invoice->amount, $invoice->id);
});
```

When your payment gateway webhook arrives, report the result:

```php
// Webhook: Payment Success (Safe & Idempotent)
$subscriptionService->recordPayment(
    invoiceId: $payload['order_id'],
    paymentReference: $payload['transaction_id']
);

// Webhook: Payment Failure
$subscriptionService->recordPaymentFailure(
    invoiceId: $payload['order_id'],
    reason: $payload['status_message']
);
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
vendor/bin/phpstan analyse src tests --level=8

# Check code formatting & refactoring
composer fix:check

# Apply code formatting & refactoring
composer fix
```

---

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).
