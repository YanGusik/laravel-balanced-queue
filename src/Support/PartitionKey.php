<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Support;

/**
 * Helper class for partition key generation and manipulation.
 */
class PartitionKey
{
    /**
     * Generate a partition key from user ID.
     */
    public static function fromUser(int|string $userId): string
    {
        return "user:{$userId}";
    }

    /**
     * Generate a partition key from tenant ID.
     */
    public static function fromTenant(int|string $tenantId): string
    {
        return "tenant:{$tenantId}";
    }

    /**
     * Generate a partition key from any entity.
     */
    public static function from(string $type, int|string $id): string
    {
        return "{$type}:{$id}";
    }

    /**
     * Parse a partition key to extract type and ID.
     *
     * @return array{type: string, id: string}|null
     */
    public static function parse(string $key): ?array
    {
        $parts = explode(':', $key, 2);

        if (count($parts) !== 2) {
            return null;
        }

        return [
            'type' => $parts[0],
            'id' => $parts[1],
        ];
    }

    /**
     * Hash a partition key for distribution (useful for sharding).
     */
    public static function hash(string $key, int $buckets = 16): int
    {
        return abs(crc32($key)) % $buckets;
    }
}
