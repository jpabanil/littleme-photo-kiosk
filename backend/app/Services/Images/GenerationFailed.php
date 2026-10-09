<?php

namespace App\Services\Images;

use RuntimeException;

final class GenerationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $transient = false,
    ) {
        parent::__construct($errorCode);
    }
}
