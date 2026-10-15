<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Repositories\Concerns;

use DateTimeImmutable;
use PDO;

/** Shared helpers for PDO repositories (portable across MariaDB/MySQL/SQLite). */
trait InteractsWithPdo
{
    private static function toDb(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }

    private static function fromDb(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }

    /**
     * Atomic upsert across MySQL/MariaDB, SQLite, and PostgreSQL, with generic fallback.
     *
     * @param  array<string, mixed>  $data
     */
    private function upsert(PDO $pdo, string $table, array $data): void
    {
        $columns = array_keys($data);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            $sets = implode(', ', array_map(
                static fn (string $c): string => sprintf('%s = EXCLUDED.%s', $c, $c),
                array_diff($columns, ['id'])
            ));
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(id) DO UPDATE SET %s',
                $table,
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $c): string => ':'.$c, $columns)),
                $sets
            );
            $pdo->prepare($sql)->execute($data);

            return;
        }

        if ($driver === 'mysql') {
            $sets = implode(', ', array_map(
                static fn (string $c): string => sprintf('%s = VALUES(%s)', $c, $c),
                array_diff($columns, ['id'])
            ));
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $c): string => ':'.$c, $columns)),
                $sets
            );
            $pdo->prepare($sql)->execute($data);

            return;
        }

        $exists = $pdo->prepare(sprintf('SELECT 1 FROM %s WHERE id = :id', $table));
        $exists->execute(['id' => $data['id']]);

        if ($exists->fetchColumn() !== false) {
            $sets = implode(', ', array_map(static fn (string $c): string => sprintf('%s = :%s', $c, $c), array_diff($columns, ['id'])));
            $sql = sprintf('UPDATE %s SET %s WHERE id = :id', $table, $sets);
        } else {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $c): string => ':'.$c, $columns))
            );
        }

        $pdo->prepare($sql)->execute($data);
    }
}
