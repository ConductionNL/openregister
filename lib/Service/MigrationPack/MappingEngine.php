<?php

/**
 * Applies a validated migration-pack definition to one parsed source row.
 *
 * Format-agnostic by design: `resolveSource()` accepts either a flat key
 * (CSV/Excel column name, or a top-level JSON key) or a JSON-Pointer-style
 * `/a/b/c` path (nested JSON), so the same engine serves CSV, Excel, and
 * JSON imports without a format-specific code path. The caller (ImportService)
 * merges the mapped `data` back into the row shape the existing
 * schema-driven import pipeline already expects (target property name =>
 * value, with `id` recognised by the existing update-by-id convention), so
 * the single write path (`ObjectService::saveObjects()`/`saveObject()`) is
 * unchanged.
 *
 * Literal-leak guard (fleet lesson — a transform/template reference that
 * doesn't resolve must ERROR the row, never pass the literal through): the
 * `lookup` transform errors the row when the source value is present but
 * has no entry in the map and no `default` is configured, rather than
 * silently passing the raw, unmapped source value through to the target
 * schema property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\MigrationPack;

use DateTime;

/**
 * Maps one source row (CSV row / Excel row / decoded JSON object) onto a set
 * of target schema-property values, per a migration-pack definition.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MappingEngine
{
    /**
     * Apply a pack definition to one source row.
     *
     * @param array<string, mixed> $pack      The validated pack definition (decoded JSON).
     * @param array<string, mixed> $sourceRow The parsed source row (flat for CSV/Excel, possibly nested for JSON).
     * @param int                  $rowNumber 1-based row number, used only to label errors.
     *
     * @return array{data: array<string, mixed>, errors: list<array{row: int, source: string, target: ?string, transform: ?string, message: string}>}
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md#the-mapping-engine-must-apply-pack-transforms-per-row-and-never-leak-unresolved-references
     */
    public function mapRow(array $pack, array $sourceRow, int $rowNumber): array
    {
        $data   = $pack['defaults'] ?? [];
        $errors = [];

        foreach (($pack['fieldMappings'] ?? []) as $mapping) {
            $source      = (string) ($mapping['source'] ?? '');
            $target      = (string) ($mapping['target'] ?? '');
            $required    = ($mapping['required'] ?? false) === true;
            $transform   = $mapping['transform'] ?? null;
            $transformId = null;
            if (is_array($transform) === true) {
                $transformId = ($transform['type'] ?? null);
            }

            $rawValue = $this->resolveSource(row: $sourceRow, pointer: $source);
            $isEmpty  = ($rawValue === null || $rawValue === '');

            if ($required === true && $isEmpty === true) {
                $errors[] = [
                    'row'       => $rowNumber,
                    'source'    => $source,
                    'target'    => $target,
                    'transform' => $transformId,
                    'message'   => sprintf('Required source field "%s" is missing or empty', $source),
                ];
                continue;
            }

            // A `const` transform always applies, regardless of the source value.
            // Every other transform is skipped (leaving any seeded default in
            // place) when the source is empty and the mapping is optional —
            // there is nothing to map, and nothing to error.
            if ($isEmpty === true && $transformId !== 'const') {
                continue;
            }

            $result = $this->applyTransform(
                value: $rawValue,
                transform: $transform,
                sourceRow: $sourceRow
            );

            if ($result['error'] !== null) {
                $errors[] = [
                    'row'       => $rowNumber,
                    'source'    => $source,
                    'target'    => $target,
                    'transform' => $transformId,
                    'message'   => $result['error'],
                ];
                continue;
            }

            $data[$target] = $result['value'];
        }//end foreach

        $data = $this->applyIdStrategy(pack: $pack, sourceRow: $sourceRow, data: $data);

        return [
            'data'   => $data,
            'errors' => $errors,
        ];
    }//end mapRow()

    /**
     * Whether a given (1-based) row number is listed in the pack's `skipRows`.
     *
     * @param array<string, mixed> $pack      The pack definition.
     * @param int                  $rowNumber 1-based row number.
     *
     * @return bool
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function isRowSkipped(array $pack, int $rowNumber): bool
    {
        $skipRows = $pack['skipRows'] ?? [];
        return in_array($rowNumber, $skipRows, true);
    }//end isRowSkipped()

    /**
     * Resolve the id/uuid target from `idStrategy`, mutating `data['id']`
     * when the strategy is `sourceField` and a value is present. The
     * `generate` strategy is a no-op here — leaving `data['id']` unset lets
     * the existing import pipeline treat the row as a create, exactly as it
     * already does for CSV/JSON rows with no id column.
     *
     * @param array<string, mixed> $pack      The pack definition.
     * @param array<string, mixed> $sourceRow The source row.
     * @param array<string, mixed> $data      The mapped target data so far.
     *
     * @return array<string, mixed> The (possibly id-augmented) target data.
     */
    private function applyIdStrategy(array $pack, array $sourceRow, array $data): array
    {
        $idStrategy = $pack['idStrategy'] ?? ['type' => 'generate'];
        if (($idStrategy['type'] ?? 'generate') !== 'sourceField') {
            return $data;
        }

        $idValue = $this->resolveSource(row: $sourceRow, pointer: (string) ($idStrategy['field'] ?? ''));
        if ($idValue !== null && $idValue !== '') {
            $data['id'] = (string) $idValue;
        }

        return $data;
    }//end applyIdStrategy()

    /**
     * Resolve a source value from a row, given either a flat key or a
     * JSON-Pointer-style `/a/b/c` path.
     *
     * @param array<string, mixed> $row     The source row.
     * @param string               $pointer A flat key or a leading-`/` pointer path.
     *
     * @return mixed The resolved value, or null when not found.
     */
    private function resolveSource(array $row, string $pointer)
    {
        if ($pointer === '') {
            return null;
        }

        if ($pointer[0] !== '/') {
            return $row[$pointer] ?? null;
        }

        $segments = explode('/', ltrim($pointer, '/'));
        $cursor   = $row;
        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }//end resolveSource()

    /**
     * Apply one transform to a resolved source value.
     *
     * @param mixed                     $value     The resolved source value (never null/'' — callers filter
     *                                             that).
     * @param array<string, mixed>|null $transform The transform block, or null for identity passthrough.
     * @param array<string, mixed>      $sourceRow The full source row (needed by `concat` to resolve extra fields).
     *
     * @return array{value: mixed, error: ?string}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per transform type.
     */
    private function applyTransform($value, ?array $transform, array $sourceRow): array
    {
        if ($transform === null) {
            return ['value' => $value, 'error' => null];
        }

        switch ($transform['type'] ?? null) {
            case 'trim':
                $stringValue = (string) $value;
                if (is_string($value) === true) {
                    $stringValue = $value;
                }
                return ['value' => trim($stringValue), 'error' => null];

            case 'date':
                return $this->applyDateTransform(value: $value, transform: $transform);

            case 'bool-map':
                return $this->applyMapTransform(value: $value, transform: $transform, coerceBool: true);

            case 'lookup':
                return $this->applyMapTransform(value: $value, transform: $transform, coerceBool: false);

            case 'concat':
                return $this->applyConcatTransform(value: $value, transform: $transform, sourceRow: $sourceRow);

            case 'const':
                return ['value' => ($transform['value'] ?? null), 'error' => null];

            default:
                return ['value' => null, 'error' => 'Unknown transform type "'.(string) ($transform['type'] ?? '').'"'];
        }//end switch
    }//end applyTransform()

    /**
     * `date` transform: parse the source value with `sourceFormat` (or a
     * best-effort `DateTime` parse when omitted) and re-emit it as `targetFormat`
     * (default `Y-m-d`).
     *
     * @param mixed                $value     The resolved source value.
     * @param array<string, mixed> $transform The transform block.
     *
     * @return array{value: mixed, error: ?string}
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DateTime::createFromFormat is the standard PHP idiom for a strict-format parse.
     */
    private function applyDateTransform($value, array $transform): array
    {
        $sourceFormat = $transform['sourceFormat'] ?? null;
        $targetFormat = $transform['targetFormat'] ?? 'Y-m-d';
        $stringValue  = (string) $value;

        try {
            $date = null;
            if (is_string($sourceFormat) === true && $sourceFormat !== '') {
                $date = DateTime::createFromFormat($sourceFormat, $stringValue);
                if ($date === false) {
                    return [
                        'value' => null,
                        'error' => sprintf('Could not parse date "%s" with format "%s"', $stringValue, $sourceFormat),
                    ];
                }
            }

            if ($date === null) {
                $date = new DateTime($stringValue);
            }
        } catch (\Throwable $e) {
            return ['value' => null, 'error' => sprintf('Could not parse date "%s": %s', $stringValue, $e->getMessage())];
        }

        return ['value' => $date->format($targetFormat), 'error' => null];
    }//end applyDateTransform()

    /**
     * Shared implementation for `bool-map` and `lookup` — both resolve the
     * source value through a `map`, with an optional `default` and, absent a
     * default, an error on an unresolved key (the literal-leak guard).
     *
     * @param mixed                $value      The resolved source value.
     * @param array<string, mixed> $transform  The transform block.
     * @param bool                 $coerceBool Whether to cast the mapped value to bool (bool-map) or return it as-is (lookup).
     *
     * @return array{value: mixed, error: ?string}
     */
    private function applyMapTransform($value, array $transform, bool $coerceBool): array
    {
        $map = $transform['map'] ?? [];
        $key = (string) $value;

        if (array_key_exists($key, $map) === true) {
            $mapped = $map[$key];
            if ($coerceBool === true) {
                $mapped = (bool) $mapped;
            }

            return ['value' => $mapped, 'error' => null];
        }

        if (array_key_exists('default', $transform) === true) {
            $default = $transform['default'];
            if ($coerceBool === true) {
                $default = (bool) $default;
            }

            return ['value' => $default, 'error' => null];
        }

        // Literal-leak guard: an unresolved map key is a data-quality problem the
        // migration operator must see and fix, never a value that silently passes
        // through unmapped into the target schema property.
        return [
            'value' => null,
            'error' => sprintf('Value "%s" has no mapping and no default is configured', $key),
        ];
    }//end applyMapTransform()

    /**
     * `concat` transform: join the primary source value with 0+ additional
     * source fields, using `separator` (default a single space). Additional
     * fields that resolve to nothing are treated as empty strings — a
     * missing *optional* extra field is not itself a literal-leak case, since
     * there is no map lookup involved.
     *
     * @param mixed                $value     The resolved primary source value.
     * @param array<string, mixed> $transform The transform block.
     * @param array<string, mixed> $sourceRow The full source row.
     *
     * @return array{value: mixed, error: ?string}
     */
    private function applyConcatTransform($value, array $transform, array $sourceRow): array
    {
        $separator = $transform['separator'] ?? ' ';
        $parts     = [(string) $value];

        foreach (($transform['fields'] ?? []) as $extraSource) {
            $extraValue = $this->resolveSource(row: $sourceRow, pointer: (string) $extraSource);
            $parts[]    = (string) ($extraValue ?? '');
        }

        return ['value' => implode($separator, $parts), 'error' => null];
    }//end applyConcatTransform()
}//end class
