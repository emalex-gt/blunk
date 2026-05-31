<?php

namespace App\Support;

use App\Services\Fel\FelException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FelPhraseRenderer
{
    public static function visiblePhrases(Collection|array $phrases): array
    {
        return collect($phrases)
            ->map(fn ($phrase) => self::renderPhrase($phrase))
            ->filter()
            ->values()
            ->all();
    }

    public static function validate(Collection|array $phrases): void
    {
        foreach ($phrases as $phrase) {
            $type = self::value($phrase, 'phrase_type') ?: self::value($phrase, 'type_value');
            $scenario = self::value($phrase, 'scenario_code') ?: self::value($phrase, 'scenario_value');

            if ((string) $type === '1' && (string) $scenario === '3') {
                if (! filled(self::value($phrase, 'resolution_number')) || ! filled(self::value($phrase, 'resolution_date'))) {
                    throw new FelException('Faltan datos de resolución para la frase FEL de pago directo ISR.');
                }
            }
        }
    }

    private static function renderPhrase(mixed $phrase): ?string
    {
        $type = (string) (self::value($phrase, 'phrase_type') ?: self::value($phrase, 'type_value'));
        $scenario = (string) (self::value($phrase, 'scenario_code') ?: self::value($phrase, 'scenario_value'));

        if ($type === '1' && $scenario === '1') {
            return 'SUJETO A PAGOS TRIMESTRALES';
        }

        if ($type === '1' && $scenario === '2') {
            return 'SUJETO A RETENCIÓN DEFINITIVA';
        }

        if ($type === '1' && $scenario === '3') {
            $resolutionNumber = self::value($phrase, 'resolution_number');
            $resolutionDate = self::value($phrase, 'resolution_date');

            if (! filled($resolutionNumber) || ! filled($resolutionDate)) {
                self::logUnmapped($type, $scenario, 'missing resolution data');

                return null;
            }

            return sprintf(
                'SUJETO A PAGO DIRECTO (%s - %s)',
                $resolutionNumber,
                self::formatDate($resolutionDate),
            );
        }

        if ($type === '2' && $scenario === '1') {
            return 'AGENTE DE RETENCIÓN DEL IVA';
        }

        if ($type === '3' && $scenario === '1') {
            return 'NO GENERA DERECHO A CRÉDITO FISCAL';
        }

        self::logUnmapped($type, $scenario);

        return null;
    }

    private static function value(mixed $phrase, string $key): mixed
    {
        if (is_array($phrase)) {
            return $phrase[$key] ?? null;
        }

        if (is_object($phrase)) {
            return $phrase->{$key} ?? null;
        }

        return null;
    }

    private static function formatDate(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance($date)->format('d/m/Y');
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    private static function logUnmapped(string $type, string $scenario, ?string $reason = null): void
    {
        if (! config('app.debug') || ! app()->environment('local')) {
            return;
        }

        Log::debug('Unmapped FEL visible phrase skipped', [
            'phrase_type' => $type,
            'scenario_code' => $scenario,
            'reason' => $reason,
        ]);
    }
}
