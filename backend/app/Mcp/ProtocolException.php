<?php

declare(strict_types=1);

namespace App\Mcp;

use RuntimeException;

final class ProtocolException extends RuntimeException
{
    public function __construct(int $code, string $message, public readonly mixed $errorData = null)
    {
        parent::__construct($message, $code);
    }
}
