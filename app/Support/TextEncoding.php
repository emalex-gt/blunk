<?php

namespace App\Support;

class TextEncoding
{
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
}
