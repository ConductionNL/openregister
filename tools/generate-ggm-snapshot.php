<?php

/**
 * Build-time generator for the bundled GGM normalised snapshot.
 *
 * Converts a published GGM (Gemeentelijk Gegevensmodel) release export into the
 * normalised intermediate JSON consumed by GgmSnapshot / GgmImporter, so that
 * no EAP/UML parsing ever happens at runtime. The committed snapshot under
 * lib/Resources/ggm/ggm-snapshot.json is the output of this script; refresh it
 * by re-running against a newer GGM export.
 *
 * Usage:
 *   php tools/generate-ggm-snapshot.php <ggm-export.json> [<out.json>]
 *
 * The input is expected to expose objecttypes with their Dutch name/definition,
 * attribuutsoorten (name/definition/datatype), referentielijst bindings, and
 * relations. This reference implementation handles the already-flat GGM JSON
 * export shape; adapt the readObjecttypes() mapping for other export forms.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tools
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/generate-ggm-snapshot.php <ggm-export.json> [<out.json>]\n");
    exit(1);
}

$inputPath  = $argv[1];
$outputPath = ($argv[2] ?? __DIR__.'/../lib/Resources/ggm/ggm-snapshot.json');

if (is_file($inputPath) === false) {
    fwrite(STDERR, "Input file not found: $inputPath\n");
    exit(1);
}

$raw     = (string) file_get_contents($inputPath);
$decoded = json_decode($raw, associative: true);
if (is_array($decoded) === false) {
    fwrite(STDERR, "Input is not valid JSON.\n");
    exit(1);
}

/**
 * Normalise the export into the intermediate objecttype list.
 *
 * @param array<string, mixed> $export The decoded GGM export.
 *
 * @return array<int, array<string, mixed>> Normalised objecttypes.
 */
function readObjecttypes(array $export): array
{
    $source = ($export['objecttypen'] ?? $export['objecttypes'] ?? []);
    if (is_array($source) === false) {
        return [];
    }

    $result = [];
    foreach ($source as $objecttype) {
        if (is_array($objecttype) === false) {
            continue;
        }

        $attributes = [];
        foreach (($objecttype['attribuutsoorten'] ?? []) as $attribute) {
            if (is_array($attribute) === false || isset($attribute['naam']) === false) {
                continue;
            }

            $normalised = [
                'naam'      => (string) $attribute['naam'],
                'definitie' => (string) ($attribute['definitie'] ?? ''),
                'type'      => (string) ($attribute['type'] ?? 'tekst'),
            ];

            if (isset($attribute['referentielijst']) === true) {
                $normalised['referentielijst'] = $attribute['referentielijst'];
            }

            if (isset($attribute['doelObjecttype']) === true) {
                $normalised['doelObjecttype'] = (string) $attribute['doelObjecttype'];
            }

            $attributes[] = $normalised;
        }//end foreach

        $result[] = [
            'id'               => (string) ($objecttype['id'] ?? strtoupper((string) ($objecttype['naam'] ?? ''))),
            'naam'             => (string) ($objecttype['naam'] ?? ''),
            'definitie'        => (string) ($objecttype['definitie'] ?? ''),
            'attribuutsoorten' => $attributes,
        ];
    }//end foreach

    return $result;
}//end readObjecttypes()

$snapshot = [
    'standard'    => 'ggm',
    'version'     => (string) ($decoded['version'] ?? 'ggm-unknown'),
    'objecttypen' => readObjecttypes($decoded),
];

$json = json_encode($snapshot, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if ($json === false) {
    fwrite(STDERR, "Failed to encode normalised snapshot.\n");
    exit(1);
}

file_put_contents($outputPath, $json."\n");
fwrite(STDOUT, "Wrote ".count($snapshot['objecttypen'])." objecttypes to $outputPath\n");
exit(0);
