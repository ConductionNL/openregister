<?php

/**
 * OpenRegister Schema Import — DialectDetector.
 *
 * Conservatively sniffs the dialect of an uploaded schema document by
 * unambiguous structural markers only. Anything that matches no marker
 * returns null so the upload path can fail with HTTP 422 rather than
 * silently mis-importing arbitrary JSON as JSON Schema. Pure function of its
 * input — no Nextcloud dependencies, fully unit-testable.
 *
 * Markers:
 *   - `$schema` key (or a JSON-Schema property/type shape) → `json-schema`
 *   - `openapi` + `components` → `openapi`
 *   - `@context` referencing schema.org (or schema.org `@type` IRIs) → `schema.org`
 *   - GGM export root markers (`objecttypen` / `objecttype` + GGM hints) → `ggm`
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * Detects the dialect of an uploaded schema document.
 *
 * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
 */
class DialectDetector
{

    /**
     * The dialect for plain JSON Schema documents.
     *
     * @var string
     */
    public const DIALECT_JSON_SCHEMA = 'json-schema';

    /**
     * The dialect for OpenAPI documents.
     *
     * @var string
     */
    public const DIALECT_OPENAPI = 'openapi';

    /**
     * The dialect for Schema.org JSON-LD documents.
     *
     * @var string
     */
    public const DIALECT_SCHEMA_ORG = 'schema.org';

    /**
     * The dialect for GGM export documents.
     *
     * @var string
     */
    public const DIALECT_GGM = 'ggm';

    /**
     * The supported dialect identifiers.
     *
     * @return array<int, string> The dialect keys.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public static function supportedDialects(): array
    {
        return [
            self::DIALECT_JSON_SCHEMA,
            self::DIALECT_OPENAPI,
            self::DIALECT_SCHEMA_ORG,
            self::DIALECT_GGM,
        ];
    }//end supportedDialects()

    /**
     * Detect the dialect of a decoded document.
     *
     * Order matters: the most specific, unambiguous markers are checked first.
     * Returns null when no marker matches (caller fails with 422).
     *
     * @param array<string, mixed> $document The decoded JSON document.
     *
     * @return string|null The detected dialect, or null when undetectable.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each marker check adds one conservative branch.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Each marker check adds one conservative branch.
     */
    public function detect(array $document): ?string
    {
        // OpenAPI: unambiguous `openapi` version + `components`.
        if (isset($document['openapi']) === true && isset($document['components']) === true) {
            return self::DIALECT_OPENAPI;
        }

        // Schema.org JSON-LD: a @context referencing schema.org.
        if ($this->referencesSchemaOrg($document['@context'] ?? null) === true) {
            return self::DIALECT_SCHEMA_ORG;
        }

        if (isset($document['@type']) === true
            && is_string($document['@type']) === true
            && str_contains($document['@type'], 'schema.org') === true
        ) {
            return self::DIALECT_SCHEMA_ORG;
        }

        // GGM export root markers.
        if ($this->looksLikeGgm($document) === true) {
            return self::DIALECT_GGM;
        }

        // JSON Schema: explicit $schema, or a recognisable JSON-Schema shape.
        if (isset($document['$schema']) === true) {
            return self::DIALECT_JSON_SCHEMA;
        }

        if ($this->looksLikeJsonSchema($document) === true) {
            return self::DIALECT_JSON_SCHEMA;
        }

        return null;
    }//end detect()

    /**
     * Whether a value is a `@context` that references schema.org.
     *
     * Accepts a string context, or an array/object context with a value or
     * a `@vocab` referencing schema.org.
     *
     * @param mixed $context The `@context` value.
     *
     * @return bool True when it references schema.org.
     */
    private function referencesSchemaOrg(mixed $context): bool
    {
        if (is_string($context) === true) {
            return str_contains($context, 'schema.org');
        }

        if (is_array($context) === true) {
            foreach ($context as $value) {
                if (is_string($value) === true && str_contains($value, 'schema.org') === true) {
                    return true;
                }
            }
        }

        return false;
    }//end referencesSchemaOrg()

    /**
     * Whether a decoded document carries GGM export root markers.
     *
     * The normalised GGM intermediate (and exports converted to it) carry a
     * `standard: "ggm"` marker; raw exports are recognised by an
     * `objecttypen` collection alongside GGM-specific keys.
     *
     * @param array<string, mixed> $document The decoded document.
     *
     * @return bool True when GGM markers are present.
     */
    private function looksLikeGgm(array $document): bool
    {
        $standard = ($document['standard'] ?? null);
        if (is_string($standard) === true && strtolower($standard) === 'ggm') {
            return true;
        }

        if (isset($document['objecttypen']) === true && is_array($document['objecttypen']) === true) {
            return true;
        }

        // A single objecttype export: a GGM objecttype carries `attribuutsoorten`.
        if (isset($document['attribuutsoorten']) === true) {
            return true;
        }

        return false;
    }//end looksLikeGgm()

    /**
     * Whether a decoded document looks like a JSON Schema even without an
     * explicit `$schema` key: a `type: object` with a `properties` map, or a
     * top-level `properties` map.
     *
     * @param array<string, mixed> $document The decoded document.
     *
     * @return bool True when it has a JSON-Schema shape.
     */
    private function looksLikeJsonSchema(array $document): bool
    {
        $type = ($document['type'] ?? null);
        if ($type === 'object' && isset($document['properties']) === true && is_array($document['properties']) === true) {
            return true;
        }

        return false;
    }//end looksLikeJsonSchema()
}//end class
