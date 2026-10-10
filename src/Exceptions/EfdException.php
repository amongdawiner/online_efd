<?php

namespace OnlineEfd\Exceptions;

use RuntimeException;

class EfdException extends RuntimeException
{
    /** The stored receipt this failure belongs to, when there is one (failed posts keep their receipt for retry). */
    public ?\OnlineEfd\Models\EfdReceipt $receipt = null;

    public function withReceipt(\OnlineEfd\Models\EfdReceipt $receipt): static
    {
        $this->receipt = $receipt;

        return $this;
    }

    public static function notConfigured(string $what): self
    {
        return new self("EFD is not configured: {$what}.");
    }

    public static function notRegistered(): self
    {
        return new self('This EFD profile is not registered with TRA yet.');
    }

    public static function certificate(string $reason): self
    {
        return new self("Certificate error: {$reason}");
    }

    public static function transport(string $reason): self
    {
        return new self("Could not reach TRA: {$reason}");
    }

    public static function invalidResponse(string $body): self
    {
        return new self('Unexpected response from TRA: '.mb_substr($body, 0, 300));
    }

    public static function rejected(string $code, string $message): self
    {
        return new self("TRA rejected the request (code {$code}): {$message}");
    }
}
