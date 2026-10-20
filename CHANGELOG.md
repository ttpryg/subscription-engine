# Changelog

All notable changes to `ttpryg/subscription-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Application use cases (`src/Application/UseCase/*`) for subscription, payment, plan,
  pause, resume, cancellation, and renewal workflows. `SubscriptionService` is now a thin
  facade over these use cases, and read operations are exposed through
  `SubscriptionQueryService`.
- `Ttpryg\SubscriptionEngine\Domain\Service\ProrationCalculator` for mid-cycle plan-change
  proration. `InvoiceService` centralizes invoice issuing, voiding, and outstanding checks.
- PHP-based migrations: `MigrationRunner` now loads timestamped `YYYYMMDDHHMM_*.php`
  migration files returning a `Migration` instance, and exposes `rollback()` to revert
  the most recently applied migrations through `Migration::down()`.
- MySQL/MariaDB integration coverage: the PDO repository tests run against a MariaDB
  service when `SUBSCRIPTION_ENGINE_DB_DSN` is set, and CI runs a dedicated MariaDB job.
- Static analysis tooling: `phpstan.neon` (level 8 covering `src`, `infrastructure`,
  `database/migrations`, and `tests`), `phpstan/phpstan` as an explicit dev dependency,
  a `composer analyse` script, and a CI step running it alongside the lint check.
- Docker development tooling: `docker-compose.yml` and `.docker/php/Dockerfile` now
  provide a single PHP 8.1 CLI container for running `composer test`, `composer analyse`,
  and `composer fix` without a local PHP install. Documented in the README.
- Transaction management for billing mutations: `TransactionManagerInterface`
  (`Domain\Service`) and `PdoTransactionManager` (`Infrastructure\Database`) ensure
  atomic write operations across all write use cases (`subscribe`, `changePlan`,
  `recordPayment`, `recordPaymentFailure`, `cancel`, `pause`, `resume`, `undoCancellation`,
  `createPlan`). `SubscriptionService` injects a PDO-backed default transaction manager
  when a PDO connection is available.
- Row locking for concurrent-safe writes: `findById($id, lock: true)` added to
  `SubscriptionRepositoryInterface` and `PlanRepositoryInterface`; PDO implementations
  issue `SELECT ... FOR UPDATE`, in-memory adapters ignore the flag. All mutating use
  cases lock the aggregate root before modifying it.

### Changed

- **Breaking:** `SubscriptionService` and `PlanService` now accept request DTOs
  (`SubscribeRequest`, `ChangePlanRequest`, `RecordPaymentRequest`,
  `RecordPaymentFailureRequest`, `CancelSubscriptionRequest`, and `CreatePlanRequest`)
  instead of positional primitive parameters. Update call sites and imports accordingly.
- **Breaking:** the baseline migration moved from
  `database/migrations/001_add_subscription_indexes.sql` to the timestamped
  `202610190001_add_subscription_indexes.php`. Existing trackers that recorded the old
  name are still recognized, so the migration is not re-applied.
- `DatabaseMigrator` no longer replays `database/schema.sql` against existing databases;
  schema changes after a fresh install now flow exclusively through migrations.
- `database/schema.sql` is now portable across SQLite and MySQL/MariaDB: boolean columns
  use `BOOLEAN`, and index creation no longer relies on `CREATE INDEX IF NOT EXISTS`
  (which MySQL does not support). The index migration creates and drops indexes through
  driver-aware existence checks instead.
- **Breaking:** PostgreSQL is no longer listed as a supported database. The shipped
  `database/schema.sql` was never PostgreSQL-compatible (MySQL-specific column types and
  index syntax), so the documented support was inaccurate.
- **Breaking:** `SystemClock` and `FixedClock` moved from
  `Ttpryg\SubscriptionEngine\Clock` to `Ttpryg\SubscriptionEngine\Domain\Clock` so every
  Domain directory follows the singular naming convention.

- Split the module into explicit Domain and Application layers:
  - Entities, enums, events, exceptions, value objects, and repository ports now live under
    `Ttpryg\SubscriptionEngine\Domain\*`.
  - Application services now live under `Ttpryg\SubscriptionEngine\Application\Service\*`.
  - **Breaking:** all public class namespaces moved. Update imports accordingly.
- Moved database infrastructure out of the module core:
  - PDO and in-memory repositories now live under
    `Ttpryg\SubscriptionEngine\Infrastructure\Persistence\*` in `infrastructure/`.
  - `DatabaseMigrator` and `MigrationRunner` now live under
    `Ttpryg\SubscriptionEngine\Infrastructure\Database`.
  - Composer PSR-4 autoloading now maps the `Infrastructure\` namespace to `infrastructure/`.
  - **Breaking:** update imports for persistence adapters and the migration runner.
- Application services now type-hint `Psr\EventDispatcher\EventDispatcherInterface`
  instead of the concrete `Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface`.
- Removed the hard dependency on `ttpryg/event-dispatcher`; it is now listed as a
  suggested PSR-14 implementation. Consumers may use any PSR-14 dispatcher.
- Clock support now uses PSR-20 `Psr\Clock\ClockInterface`. The module-specific
  `Ttpryg\SubscriptionEngine\Contracts\ClockInterface` was removed; `SystemClock`,
  `FixedClock`, and `SubscriptionService` now use the PSR-20 contract. **Breaking**
  for consumers implementing the old interface.

### Removed

- Nginx application scaffolding (`.docker/nginx/default.conf`) that served an
  `index.php` which does not exist in this repository. The previous dual-service
  `docker-compose.yml` (PHP-FPM + nginx) was replaced by a PHP CLI-only dev container.

## [1.1.0] - 2026-10-18

### Added

- Root `ExceptionInterface` marker interface (`Ttpryg\SubscriptionEngine\Exceptions\ExceptionInterface`)
  that extends `Throwable` and is implemented by every custom exception, so consumers can
  catch all library-specific errors with a single type.
- Versioned migration system: `DatabaseMigrator` and `MigrationRunner` (tracker table
  `subscription_engine_migrations`), the baseline migration
  `database/migrations/001_add_subscription_indexes.sql`, and
  `tests/Integration/DatabaseMigratorTest.php`.
- `.editorconfig` for consistent editor settings across the project.

### Changed

- Added `declare(strict_types=1)` to all source files that were missing it.

### Documentation

- README: documented programmatic schema application via `DatabaseMigrator::migrate()`.

## [1.0.0] - 2026-10-16

### Added

- Initial release: `PlanService` and `SubscriptionService` covering the full subscription
  lifecycle (create, activate, pause/resume, cancel, change plan, renewal, past-due,
  payment success/failure, and expiry).
- Entities `Plan`, `Subscription`, and `SubscriptionInvoice`; enums `BillingInterval`,
  `SubscriptionStatus`, and `InvoiceStatus`; and the `BillingPeriod` value object.
- Pluggable repositories (in-memory and PDO) for plans, subscriptions, and invoices.
- Pluggable clock (`ClockInterface` with `SystemClock` and `FixedClock`) for deterministic
  time-based tests.
- Domain events: `PlanCreatedEvent`, `SubscriptionCreatedEvent`, `SubscriptionRenewedEvent`,
  `SubscriptionRenewalDueEvent`, `SubscriptionPlanChangedEvent`, `SubscriptionPausedEvent`,
  `SubscriptionResumedEvent`, `SubscriptionCanceledEvent`, `SubscriptionExpiredEvent`,
  `SubscriptionPastDueEvent`, `SubscriptionPaymentSucceededEvent`, and
  `SubscriptionPaymentFailedEvent`.
- Unit and integration tests.

[Unreleased]: https://github.com/ttpryg/subscription-engine/compare/1.1.0...HEAD
[1.0.0]: https://github.com/ttpryg/subscription-engine/releases/tag/1.0.0
