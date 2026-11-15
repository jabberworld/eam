<?php
declare(strict_types=1);

namespace Eam\Ejabberd;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $command,
        public readonly int $status,
        public readonly string $body,
        public readonly array $arguments = []
    ) {
        parent::__construct(sprintf('ejabberd API %s failed with HTTP %d: %s', $command, $status, $body));
    }

    public function isKnownVcardMissing(): bool
    {
        return $this->command === 'get_vcard' && str_contains($this->body, 'error_no_vcard_found');
    }
}
