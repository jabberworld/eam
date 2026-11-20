<?php
declare(strict_types=1);

namespace Eam\Service;

final class Result
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $fields = [],
        public readonly array $data = []
    ) {
    }

    public static function success(?string $message = null, array $data = []): self
    {
        return new self('success', $message, [], $data);
    }

    public static function error(?string $message = null, array $fields = [], array $data = []): self
    {
        return new self('error', $message, $fields, $data);
    }

    public static function blocked(?string $message = null, array $fields = [], array $data = []): self
    {
        return new self('blocked', $message, $fields, $data);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
