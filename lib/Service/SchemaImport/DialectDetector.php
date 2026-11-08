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
 * @spec openspec/specs/schema-import/spec.md
 */
class DialectDetector {

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
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public static function supportedDialects(): array {
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
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function detect(array $document): ?string {
		return ($this->openApiMarker(document: $document)
			?? $this->schemaOrgMarker(document: $document)
			?? $this->ggmMarker(document: $document)
			?? $this->jsonSchemaMarker(document: $document));
	}//end detect()

	/**
	 * OpenAPI: an unambiguous `openapi` version plus `components`.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return string|null The OpenAPI dialect, or null when not marked.
	 */
	private function openApiMarker(array $document): ?string {
		if (isset($document['openapi']) === true && isset($document['components']) === true) {
			return self::DIALECT_OPENAPI;
		}

		return null;
	}//end openApiMarker()

	/**
	 * Schema.org JSON-LD: a @context referencing schema.org, or a schema.org `@type`.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return string|null The Schema.org dialect, or null when not marked.
	 */
	private function schemaOrgMarker(array $document): ?string {
		if ($this->referencesSchemaOrg(context: ($document['@context'] ?? null)) === true
			|| $this->typeReferencesSchemaOrg(document: $document) === true
		) {
			return self::DIALECT_SCHEMA_ORG;
		}

		return null;
	}//end schemaOrgMarker()

	/**
	 * Whether the document's `@type` is a string referencing schema.org.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return bool True when the type references schema.org.
	 */
	private function typeReferencesSchemaOrg(array $document): bool {
		return isset($document['@type']) === true
			&& is_string($document['@type']) === true
			&& str_contains($document['@type'], 'schema.org') === true;
	}//end typeReferencesSchemaOrg()

	/**
	 * GGM: export root markers.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return string|null The GGM dialect, or null when not marked.
	 */
	private function ggmMarker(array $document): ?string {
		if ($this->looksLikeGgm(document: $document) === true) {
			return self::DIALECT_GGM;
		}

		return null;
	}//end ggmMarker()

	/**
	 * JSON Schema: explicit $schema, or a recognisable JSON-Schema shape.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return string|null The JSON Schema dialect, or null when not marked.
	 */
	private function jsonSchemaMarker(array $document): ?string {
		if (isset($document['$schema']) === true || $this->looksLikeJsonSchema(document: $document) === true) {
			return self::DIALECT_JSON_SCHEMA;
		}

		return null;
	}//end jsonSchemaMarker()

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
	private function referencesSchemaOrg(mixed $context): bool {
		if (is_string($context) === true) {
			return str_contains($context, 'schema.org');
		}

		if (is_array($context) === true) {
			return $this->anyValueReferencesSchemaOrg(context: $context);
		}

		return false;
	}//end referencesSchemaOrg()

	/**
	 * Whether any string value of an array/object `@context` references schema.org.
	 *
	 * @param array<int|string, mixed> $context The `@context` array.
	 *
	 * @return bool True when one of its string values references schema.org.
	 */
	private function anyValueReferencesSchemaOrg(array $context): bool {
		foreach ($context as $value) {
			if (is_string($value) === true && str_contains($value, 'schema.org') === true) {
				return true;
			}
		}

		return false;
	}//end anyValueReferencesSchemaOrg()

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
	private function looksLikeGgm(array $document): bool {
		$standard = ($document['standard'] ?? null);
		if (is_string($standard) === true && strtolower($standard) === 'ggm') {
			return true;
		}

		return $this->hasGgmCollection(document: $document);
	}//end looksLikeGgm()

	/**
	 * Whether a document carries an `objecttypen` collection, or — for a
	 * single objecttype export — the `attribuutsoorten` a GGM objecttype carries.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return bool True when a GGM collection is present.
	 */
	private function hasGgmCollection(array $document): bool {
		if (isset($document['objecttypen']) === true && is_array($document['objecttypen']) === true) {
			return true;
		}

		return isset($document['attribuutsoorten']) === true;
	}//end hasGgmCollection()

	/**
	 * Whether a decoded document looks like a JSON Schema even without an
	 * explicit `$schema` key: a `type: object` with a `properties` map, or a
	 * top-level `properties` map.
	 *
	 * @param array<string, mixed> $document The decoded document.
	 *
	 * @return bool True when it has a JSON-Schema shape.
	 */
	private function looksLikeJsonSchema(array $document): bool {
		$type = ($document['type'] ?? null);
		if ($type === 'object' && isset($document['properties']) === true && is_array($document['properties']) === true) {
			return true;
		}

		return false;
	}//end looksLikeJsonSchema()
}//end class
