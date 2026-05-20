<?php

namespace App\Services\Fel;

class FelCertifiedXmlParser
{
    /**
     * @return array{uuid?: string, series?: string, number?: string, certification_date?: string}
     */
    public function parse(string $xml): array
    {
        $result = [];

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $document) {
            return $result;
        }

        $authorizationNodes = $document->xpath('//*[local-name()="NumeroAutorizacion"]') ?: [];

        if ($authorizationNodes !== []) {
            $node = $authorizationNodes[0];
            $attributes = $node->attributes();
            $result['uuid'] = trim((string) $node) ?: null;
            $result['series'] = filled((string) ($attributes['Serie'] ?? '')) ? (string) $attributes['Serie'] : null;
            $result['number'] = filled((string) ($attributes['Numero'] ?? '')) ? (string) $attributes['Numero'] : null;
        }

        $dateNodes = $document->xpath('//*[local-name()="FechaHoraCertificacion"]') ?: [];

        if ($dateNodes !== []) {
            $result['certification_date'] = trim((string) $dateNodes[0]) ?: null;
        }

        return array_filter($result, fn ($value) => filled($value));
    }
}
