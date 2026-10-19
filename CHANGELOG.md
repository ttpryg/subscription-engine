# Changelog

All notable changes to `ttpryg/subscription-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

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
