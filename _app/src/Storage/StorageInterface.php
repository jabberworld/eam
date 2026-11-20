<?php
declare(strict_types=1);

namespace Eam\Storage;

interface StorageInterface
{
    public function createToken(string $kind, array $payload, int $ttl, ?string $ip = null): string;

    public function peekToken(string $kind, string $token): ?array;

    public function consumeToken(string $kind, string $token): ?array;

    public function isRateLimited(string $key, int $limit): bool;

    public function hitRate(string $key, int $window): void;

    public function clearRate(string $key): void;

    public function cleanup(): void;
}
