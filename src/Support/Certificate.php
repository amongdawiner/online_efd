<?php

namespace OnlineEfd\Support;

use Carbon\CarbonImmutable;
use OnlineEfd\Exceptions\EfdException;

/** A taxpayer's TRA-issued PKCS#12 (.pfx) certificate, used to sign every request. */
final class Certificate
{
    private function __construct(
        private readonly \OpenSSLAsymmetricKey $privateKey,
        public readonly string $serialHex,
        public readonly CarbonImmutable $expiresAt,
    ) {
    }

    public static function fromPkcs12(string $binary, string $password): self
    {
        if ($binary === '') {
            throw EfdException::certificate('the certificate file is empty.');
        }

        // Drain stale errors so the message we report belongs to this call.
        while (openssl_error_string() !== false) {
            // intentionally empty
        }

        if (! openssl_pkcs12_read($binary, $bundle, $password)) {
            $reason = openssl_error_string() ?: 'unknown error';

            throw EfdException::certificate(
                "could not open the .pfx — wrong password or an unsupported legacy format ({$reason})."
            );
        }

        $key = openssl_pkey_get_private($bundle['pkey']);
        $parsed = openssl_x509_parse($bundle['cert']);

        if ($key === false || $parsed === false) {
            throw EfdException::certificate('the .pfx does not contain a usable key pair.');
        }

        return new self(
            $key,
            $parsed['serialNumberHex'],
            CarbonImmutable::createFromTimestamp($parsed['validTo_time_t']),
        );
    }

    /** Base64 of the serial's hex string — the value TRA expects in the Cert-Serial header. */
    public function serialHeader(): string
    {
        return base64_encode($this->serialHex);
    }

    /** SHA1withRSA, base64 — as required by the TRA VFD API. */
    public function sign(string $payload): string
    {
        if (! openssl_sign($payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA1)) {
            throw EfdException::certificate('signing failed.');
        }

        return base64_encode($signature);
    }
}
