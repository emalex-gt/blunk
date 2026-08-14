<?php

namespace App\Support;

class TextEncoding
{
    public static function stringDiagnostics(?string $value): array
    {
        $value = (string) $value;

        return [
            'visible' => $value,
            'characters' => mb_check_encoding($value, 'UTF-8') ? mb_strlen($value, 'UTF-8') : null,
            'bytes' => strlen($value),
            'codepoints' => self::codepoints($value),
            'hex_utf8' => self::hexBytes($value),
            'contains_u_fffd' => str_contains($value, "\xEF\xBF\xBD"),
            'has_mojibake' => self::hasMojibake($value),
        ];
    }

    public static function codepoints(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '-';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'invalid-utf8';
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            fn (string $character): string => 'U+'.str_pad(strtoupper(dechex(mb_ord($character, 'UTF-8'))), 4, '0', STR_PAD_LEFT),
            $characters,
        ));
    }

    public static function hexBytes(?string $value): string
    {
        $hex = strtoupper(bin2hex((string) $value));

        return $hex === '' ? '-' : implode(' ', str_split($hex, 2));
    }

    public static function normalizeResponseBody(?string $body, ?string $contentType = null): array
    {
        $body = (string) $body;
        $contentType = (string) $contentType;
        $declaredCharset = self::charsetFromContentType($contentType);

        if ($body === '') {
            return [
                'body' => '',
                'encoding' => $declaredCharset ?: 'empty',
                'converted' => false,
                'valid_utf8_before' => true,
            ];
        }

        $validUtf8Before = mb_check_encoding($body, 'UTF-8');

        if ($validUtf8Before) {
            return [
                'body' => $body,
                'encoding' => $declaredCharset ?: 'UTF-8',
                'converted' => false,
                'valid_utf8_before' => true,
            ];
        }

        $candidates = [];

        if ($declaredCharset) {
            $candidates[] = $declaredCharset;
        }

        foreach (['Windows-1252', 'ISO-8859-1'] as $candidate) {
            if (! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (self::isUtf8Charset($candidate)) {
                continue;
            }

            $converted = @mb_convert_encoding($body, 'UTF-8', $candidate);

            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return [
                    'body' => $converted,
                    'encoding' => $candidate,
                    'converted' => true,
                    'valid_utf8_before' => $validUtf8Before,
                ];
            }
        }

        return [
            'body' => $body,
            'encoding' => $declaredCharset ?: ($validUtf8Before ? 'UTF-8' : 'unknown'),
            'converted' => false,
            'valid_utf8_before' => $validUtf8Before,
        ];
    }

    public static function hasMojibake(?string $value): bool
    {
        $value = (string) $value;

        if ($value === '') {
            return false;
        }

        $badSequences = [
            "\xEF\xBF\xBD",
            "\xC3\x83",
            "\xC3\x82",
            "\xC3\xAF\xC2\xBF\xC2\xBD",
            "\xC3\xA2\xE2\x82\xAC",
        ];

        foreach ($badSequences as $sequence) {
            if (str_contains($value, $sequence)) {
                return true;
            }
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1;
    }

    public static function fieldsWithMojibake(array $fields): array
    {
        $affected = [];

        foreach ($fields as $field => $value) {
            if (is_string($value) && self::hasMojibake($value)) {
                $affected[] = $field;
            }
        }

        return $affected;
    }

    public static function payloadHasMojibake(mixed $payload): bool
    {
        if (is_string($payload)) {
            return self::hasMojibake($payload);
        }

        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $value) {
            if (self::payloadHasMojibake($value)) {
                return true;
            }
        }

        return false;
    }

    private static function charsetFromContentType(string $contentType): ?string
    {
        if (! preg_match('/charset=([^;]+)/i', $contentType, $matches)) {
            return null;
        }

        return trim($matches[1], " \t\n\r\0\x0B\"'");
    }

    private static function isUtf8Charset(?string $charset): bool
    {
        return in_array(strtoupper(str_replace('_', '-', (string) $charset)), ['UTF-8', 'UTF8'], true);
    }
}
