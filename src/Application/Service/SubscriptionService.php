<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\Service;

use Closure;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\CancelSubscriptionRequest;
use Ttpryg\SubscriptionEngine\Application\DTO\ChangePlanRequest;
use Ttpryg\SubscriptionEngine\Application\DTO\RecordPaymentFailureRequest;
use Ttpryg\SubscriptionEngine\Application\DTO\RecordPaymentRequest;
use Ttpryg\SubscriptionEngine\Application\DTO\SubscribeRequest;
use Ttpryg\SubscriptionEngine\Application\UseCase\CancelSubscriptionUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\ChangePlanUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\PauseSubscriptionUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\ProcessRenewalsUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\RecordPaymentFailureUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\RecordPaymentUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\ResumeSubscriptionUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\SubscribeUseCase;
use Ttpryg\SubscriptionEngine\Application\UseCase\UndoCancellationUseCase;
use Ttpryg\SubscriptionEngine\Domain\Clock\SystemClock;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Service\ProrationCalculator;

/**
 * Thin application facade over the subscription use cases.
 *
 * Lifecycle:
 *   subscribe ──► TRIALING ──(trial ends)──► ACTIVE ◄──(payment)── PAST_DUE
 *                    │                         │  (invoice overdue) ──►┘   │
 *                    └──────── pause/resume ───┤                          │
 *                                              ▼                          ▼
 *                                  CANCELED / EXPIRED ◄──(period ends unpaid)
 *
 * Payment collection is intentionally decoupled: listen to SubscriptionRenewalDueEvent,
 * charge the invoice with your gateway, then call recordPayment() or recordPaymentFailure().
 *
 * A PDO connection (or a custom `$transactionManager`) makes every write use case run inside
 * a database transaction, so billing mutations are atomic.
 */
class SubscriptionService
{
    private readonly ClockInterface $clock;

    private readonly Closure $idGenerator;

    private readonly ?Closure $transactionManager;

    private readonly InvoiceService $invoiceService;

    private readonly SubscriptionQueryService $queryService;

    private readonly ProrationCalculator $prorationCalculator;

    /**
     * @param  int  $graceDays  Days after an invoice is issued before the subscription becomes PAST_DUE.
     * @param  (callable(): string)|null  $idGenerator  Generates invoice IDs (defaults to UUID v4).
     * @param  (callable(callable(): mixed): mixed)|null  $transactionManager  Wraps execution in a DB transaction.
     * @param  PDO|null  $pdo  PDO connection used to build a default transaction manager when none is given.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        ?ClockInterface $clock = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        int $graceDays = 3,
        ?callable $idGenerator = null,
        ?callable $transactionManager = null,
        ?PDO $pdo = null
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->idGenerator = $idGenerator !== null
            ? Closure::fromCallable($idGenerator)
            : Closure::fromCallable(new Uuid4Generator);
        $this->transactionManager = $transactionManager !== null
            ? Closure::fromCallable($transactionManager)
            : ($pdo instanceof \PDO
                ? Closure::fromCallable(fn (callable $operation): mixed => $this->runInTransaction($pdo, $operation))
                : null);
        $this->invoiceService = new InvoiceService(
            $invoiceRepository,
            $this->clock,
            $eventDispatcher,
            $graceDays,
            $this->idGenerator
        );
        $this->queryService = new SubscriptionQueryService($subscriptionRepository, $invoiceRepository, $this->clock);
        $this->prorationCalculator = new ProrationCalculator;
    }

    public function subscribe(SubscribeRequest $request): Subscription
    {
        return (new SubscribeUseCase(
            $this->planRepository,
            $this->subscriptionRepository,
            $this->invoiceService,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($request);
    }

    public function getSubscription(string $id): Subscription
    {
        return $this->queryService->getSubscription($id);
    }

    /** @return Subscription[] */
    public function getUserSubscriptions(string $userId, ?string $storeId = null): array
    {
        return $this->queryService->getUserSubscriptions($userId, $storeId);
    }

    /** Access check, e.g. for feature gating / middleware. */
    public function hasAccess(string $userId, ?string $planId = null, ?string $storeId = null): bool
    {
        return $this->queryService->hasAccess($userId, $planId, $storeId);
    }

    /** @return SubscriptionInvoice[] */
    public function getInvoices(string $subscriptionId): array
    {
        return $this->queryService->getInvoices($subscriptionId);
    }

    public function recordPayment(RecordPaymentRequest $request): SubscriptionInvoice
    {
        return (new RecordPaymentUseCase(
            $this->invoiceRepository,
            $this->subscriptionRepository,
            $this->invoiceService,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($request);
    }

    public function recordPaymentFailure(RecordPaymentFailureRequest $request): SubscriptionInvoice
    {
        return (new RecordPaymentFailureUseCase(
            $this->invoiceRepository,
            $this->subscriptionRepository,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($request);
    }

    public function changePlan(ChangePlanRequest $request): Subscription
    {
        return (new ChangePlanUseCase(
            $this->planRepository,
            $this->subscriptionRepository,
            $this->invoiceService,
            $this->prorationCalculator,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($request);
    }

    public function cancel(CancelSubscriptionRequest $request): Subscription
    {
        return (new CancelSubscriptionUseCase(
            $this->subscriptionRepository,
            $this->invoiceService,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($request);
    }

    public function undoCancellation(string $subscriptionId): Subscription
    {
        return (new UndoCancellationUseCase(
            $this->subscriptionRepository,
            $this->clock,
            $this->transactionManager
        ))->execute($subscriptionId);
    }

    public function pause(string $subscriptionId): Subscription
    {
        return (new PauseSubscriptionUseCase(
            $this->subscriptionRepository,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($subscriptionId);
    }

    public function resume(string $subscriptionId): Subscription
    {
        return (new ResumeSubscriptionUseCase(
            $this->subscriptionRepository,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute($subscriptionId);
    }

    /**
     * Run periodically (e.g. cron every 5–15 minutes). Idempotent per moment in time.
     *
     * @return array{past_due: int, renewed: int, expired: int}
     */
    public function processRenewals(): array
    {
        return (new ProcessRenewalsUseCase(
            $this->planRepository,
            $this->subscriptionRepository,
            $this->invoiceRepository,
            $this->invoiceService,
            $this->clock,
            $this->eventDispatcher,
            $this->transactionManager
        ))->execute();
    }

    /**
     * Run a callable inside a PDO transaction.
     */
    private function runInTransaction(PDO $pdo, callable $operation): mixed
    {
        $pdo->beginTransaction();

        try {
            $result = $operation();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
