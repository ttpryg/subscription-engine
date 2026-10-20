<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Service;

/**
 * Wraps a unit of work in a transaction (or equivalent).
 *
 * Implementations should guarantee atomicity: if the callable throws, all
 * changes made inside it must be rolled back.
 */
interface TransactionManagerInterface
{
    /**
     * Execute $operation inside a transaction.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function execute(callable $operation): mixed;
}
