<?php

namespace OnlineEfd\Support;

use SimpleXMLElement;

final class Xml
{
    /**
     * TRA verifies the signature against the raw payload, so (like the legacy
     * implementation) characters that need escaping are replaced by a space
     * instead of being entity-encoded.
     */
    public static function text(?string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value) ?? '';

        return trim(str_replace(['&', '<', '>'], ' ', $value));
    }

    public static function tag(string $name, string|int|float|null $value): string
    {
        return "<{$name}>".self::text((string) $value)."</{$name}>";
    }

    /** Wrap a payload in the signed <EFDMS> envelope. */
    public static function envelope(string $payload, Certificate $certificate): string
    {
        return "<?xml version='1.0' encoding='UTF-8'?>"
            .'<EFDMS>'.$payload
            .'<EFDMSSIGNATURE>'.$certificate->sign($payload).'</EFDMSSIGNATURE>'
            .'</EFDMS>';
    }

    /** @return array{code: string, message: string} first ACKCODE/ACKMSG found anywhere in the response */
    public static function ack(SimpleXMLElement $xml): array
    {
        $code = $xml->xpath('//ACKCODE');
        $message = $xml->xpath('//ACKMSG');

        return [
            'code' => isset($code[0]) ? trim((string) $code[0]) : '',
            'message' => isset($message[0]) ? trim((string) $message[0]) : '',
        ];
    }
}
