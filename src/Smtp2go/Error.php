<?php

namespace Acelle\Plugin\Smtp2go\Smtp2go;

class Error extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
