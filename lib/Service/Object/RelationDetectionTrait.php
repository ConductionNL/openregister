<?php

/**
 * OpenRegister RelationDetectionTrait
 *
 * Single source of truth for deciding whether a scalar value scanned out of an
 * object's data should be recorded as a relation in `@self.relations`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Trait
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Relation detection rule shared by every relation-recording scan path.
 *
 * A scalar is recorded as a relation in `@self.relations` ONLY when it is a
 * genuine reference to another object:
 *  - its schema property declares it a reference (`type:object`,
 *    `format` uuid/uri/url, or a `$ref` / `inversedBy` on the property), or
 *  - the value itself matches a UUID / prefixed-UUID / URL pattern.
 *
 * The previous loose heuristic — "any 8+ char string with a hyphen or
 * underscore" — recorded dates, enum values and business identifiers as
 * relations, polluting `@self.relations` and causing the relation graph to do
 * wasted lookups. That heuristic is intentionally absent here.
 *
 * @category Trait
 * @package  OCA\OpenRegister\Service\Object
 */
trait RelationDetectionTrait
{
    /**
     * Decide whether a scalar value should be recorded as a relation.
     *
     * @param string     $value          The scalar value scanned from the object data.
     * @param array|null $propertyConfig  The schema property definition for this value, if known.
     *
     * @return bool True when the value is a genuine, recordable reference.
     */
    protected function isRecordableReference(string $value, ?array $propertyConfig=null): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Schema-declared references are authoritative.
        if ($this->isSchemaDeclaredReference(propertyConfig: $propertyConfig) === true) {
            return true;
        }

        // Otherwise only values that themselves look like a reference qualify.
        return $this->matchesReferencePattern(value: $value);
    }//end isRecordableReference()

    /**
     * Whether the schema property definition declares this value a reference.
     *
     * @param array|null $propertyConfig The schema property definition, if known.
     *
     * @return bool True when the property is a declared reference.
     */
    private function isSchemaDeclaredReference(?array $propertyConfig): bool
    {
        if ($propertyConfig === null) {
            return false;
        }

        $type   = $propertyConfig['type'] ?? '';
        $format = $propertyConfig['format'] ?? '';

        if ($type === 'object') {
            return true;
        }

        if ($type === 'text' && in_array($format, ['uuid', 'uri', 'url'], true) === true) {
            return true;
        }

        return isset($propertyConfig['$ref']) === true || isset($propertyConfig['inversedBy']) === true;
    }//end isSchemaDeclaredReference()

    /**
     * Whether a value matches a UUID / prefixed-UUID / URL reference pattern.
     *
     * @param string $value The trimmed scalar value.
     *
     * @return bool True when the value looks like a reference.
     */
    private function matchesReferencePattern(string $value): bool
    {
        // Canonical UUID (8-4-4-4-12).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        // UUID without dashes (32 hex chars).
        if (preg_match('/^[0-9a-f]{32}$/i', $value) === 1) {
            return true;
        }

        // Prefixed UUID (e.g. "id-<uuid>" with or without dashes).
        $prefixed = '/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i';
        if (preg_match($prefixed, $value) === 1) {
            return true;
        }

        // URLs.
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }//end matchesReferencePattern()
}//end trait
