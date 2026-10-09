<?php
declare(strict_types=1);

/** Error from an AI provider. */
class AiException extends RuntimeException
{
    /** @param string $kind 'failed' (retryable) or 'rejected' (photo not acceptable) */
    public function __construct(string $message, public readonly string $kind = 'failed', public readonly string $reason = '')
    {
        parent::__construct($message);
    }
}
