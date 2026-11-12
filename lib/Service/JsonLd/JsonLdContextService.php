<?php

/**
 * OpenRegister JSON-LD Context Service
 *
 * Builds JSON-LD `@context` documents from OpenRegister Schema/Register
 * definitions. The context is derived from `Schema.properties` (JSON Schema)
 * plus the optional `configuration.jsonld` vocabulary-mapping block. Every
 * schema yields a valid, dereferenceable context out of the box (zero config).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\JsonLd
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

namespace OCA\OpenRegister\Service\JsonLd;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IURLGenerator;

/**
 * Derives JSON-LD context documents from schema definitions.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
class JsonLdContextService implements JsonLdContextServiceInterface {

	/**
	 * The OpenRegister metadata namespace IRI. Used for the `or:` prefix that
	 * carries `@self`-derived metadata terms in serialized objects.
	 *
	 * @var string
	 */
	public const OR_NAMESPACE = 'https://openregister.app/ns#';

	/**
	 * The XML Schema datatypes namespace, used for `@type` coercions.
	 *
	 * @var string
	 */
	public const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema#';

	/**
	 * Request-scoped cache of derived schema contexts, keyed by
	 * `{schemaId}:{updatedTimestamp}` so a schema edit busts the entry.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $contextCache = [];

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator for context-document URLs.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Build the absolute URL of a per-schema context document.
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The schema.
	 *
	 * @return string The absolute context-document URL.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function getSchemaContextUrl(Register $register, Schema $schema): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'openregister.contexts.schema',
			[
				'register' => JsonLdRouteSlug::of(entity: $register),
				'schema' => JsonLdRouteSlug::of(entity: $schema),
			]
		);
	}//end getSchemaContextUrl()

	/**
	 * Resolve the class IRI used as a serialized object's `@type` for a schema.
	 *
	 * Defaults to the schema slug (a term resolvable in the schema context),
	 * or the explicit `jsonld.type` mapping when configured.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The `@type` value (class IRI or schema-slug term).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function getTypeForSchema(Schema $schema): string {
		$jsonld = $this->jsonLdConfig(schema: $schema);
		if (isset($jsonld['type']) === true && is_string($jsonld['type']) === true && $jsonld['type'] !== '') {
			return $jsonld['type'];
		}

		return JsonLdRouteSlug::of(entity: $schema);
	}//end getTypeForSchema()

	/**
	 * Compute the canonical semantic types a schema implements.
	 *
	 * The implemented type set is the union of, in order:
	 *   1. `configuration.implements[]` — absolute IRIs (non-IRI entries dropped);
	 *   2. `configuration.jsonld.type` — the single object `@type` IRI, when set;
	 *   3. a schema-level `x-schema-org` marker (top-level field, or under
	 *      `configuration`) carrying a compact schema.org CURIE such as
	 *      `schema:Organization`, expanded to `https://schema.org/Organization`.
	 *      Both string and array forms are accepted, so existing schema.org-
	 *      annotated schemas (ADR-011) become resolvable with no edit.
	 *
	 * When `configuration.implements` is present it is authoritative and the
	 * `jsonld.type` / `x-schema-org` defaults are NOT merged (an explicit list
	 * is an explicit contract). Otherwise the `jsonld.type` and `x-schema-org`
	 * markers are unioned.
	 *
	 * Unlike {@see getTypeForSchema()} this never falls back to the schema
	 * slug: a schema with no absolute-IRI semantic marker implements nothing
	 * resolvable and MUST NOT be picked up by a URI lookup. Only schema-LEVEL
	 * markers count — property-level `x-schema-org` annotations describe a
	 * field, not the schema, and are ignored here.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The list of implemented absolute-IRI types (may be empty).
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: Schemas advertise the canonical types they implement)
	 */
	public function getImplementedTypes(Schema $schema): array {
		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		// Explicit `implements` list is authoritative; keep only absolute IRIs.
		$implements = ($configuration['implements'] ?? null);
		if (is_array($implements) === true) {
			return array_values(array_unique($this->absoluteIris(candidates: $implements)));
		}

		// Otherwise union the default markers.
		return array_values(array_unique($this->defaultImplementedTypes(schema: $schema, configuration: $configuration)));
	}//end getImplementedTypes()

	/**
	 * Keep the absolute-IRI entries of a list, in order.
	 *
	 * @param array<mixed> $candidates The candidate entries.
	 *
	 * @return array<int, string> The absolute IRIs.
	 */
	private function absoluteIris(array $candidates): array {
		$types = [];
		foreach ($candidates as $candidate) {
			if ($this->isAbsoluteIri(value: $candidate) === true) {
				$types[] = $candidate;
			}
		}

		return $types;
	}//end absoluteIris()

	/**
	 * The default implemented types: an absolute `jsonld.type`, then the
	 * schema-level `x-schema-org` marker(s).
	 *
	 * @param Schema               $schema        The schema.
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return array<int, string> The types, possibly with duplicates.
	 */
	private function defaultImplementedTypes(Schema $schema, array $configuration): array {
		$types = [];

		// (a) `jsonld.type` IRI, when absolute.
		$jsonld = $this->jsonLdConfig(schema: $schema);
		$type = ($jsonld['type'] ?? null);
		if ($this->isAbsoluteIri(value: $type) === true) {
			$types[] = $type;
		}

		// (b) schema-level `x-schema-org` CURIE(s) — top-level or in configuration.
		$xSchemaOrg = ($configuration['x-schema-org'] ?? null);
		foreach ($this->expandSchemaOrgMarker(marker: $xSchemaOrg) as $iri) {
			$types[] = $iri;
		}

		return $types;
	}//end defaultImplementedTypes()

	/**
	 * Expand a schema-level `x-schema-org` marker into absolute schema.org IRIs.
	 *
	 * Accepts a single value or an array. Each entry may be a compact CURIE
	 * (`schema:Organization` → `https://schema.org/Organization`) or an already-
	 * absolute IRI (passed through). Non-string / unresolvable entries are
	 * dropped.
	 *
	 * @param mixed $marker The raw `x-schema-org` marker value (string, array, or null).
	 *
	 * @return array<int, string> Expanded absolute IRIs (may be empty).
	 */
	private function expandSchemaOrgMarker(mixed $marker): array {
		if ($marker === null) {
			return [];
		}

		if (is_array($marker) === true) {
			$entries = $marker;
		} else {
			$entries = [$marker];
		}

		$iris = [];
		foreach ($entries as $entry) {
			$iri = $this->expandSchemaOrgEntry(entry: $entry);
			if ($iri !== null) {
				$iris[] = $iri;
			}
		}

		return $iris;
	}//end expandSchemaOrgMarker()

	/**
	 * Expand one `x-schema-org` entry: a `schema:` CURIE becomes a schema.org
	 * IRI, an absolute IRI passes through, anything else is dropped.
	 *
	 * @param mixed $entry The marker entry.
	 *
	 * @return string|null The absolute IRI, or null when the entry is dropped.
	 */
	private function expandSchemaOrgEntry(mixed $entry): ?string {
		if ($this->isNonEmptyString(value: $entry) === false) {
			return null;
		}

		// Compact schema.org CURIE, e.g. `schema:Organization`.
		if (str_starts_with($entry, 'schema:') === true) {
			return $this->schemaOrgIri(term: substr($entry, strlen('schema:')));
		}

		// Already an absolute IRI — accept as-is.
		if ($this->isAbsoluteIri(value: $entry) === true) {
			return $entry;
		}

		return null;
	}//end expandSchemaOrgEntry()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a string other than `''`.
	 */
	private function isNonEmptyString(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isNonEmptyString()

	/**
	 * The schema.org IRI of a CURIE's local part.
	 *
	 * @param string $term The part after `schema:`.
	 *
	 * @return string|null The IRI, or null for an empty term.
	 */
	private function schemaOrgIri(string $term): ?string {
		if ($term === '') {
			return null;
		}

		return 'https://schema.org/' . $term;
	}//end schemaOrgIri()

	/**
	 * Build the JSON-LD context map for a single schema.
	 *
	 * The returned array is the *value* of a `@context` key, i.e.
	 * `{"or": "...", "name": {...}, ...}`. It always defines the `or:` prefix,
	 * an `xsd:` prefix, a `@vocab` when configured, the schema class term, and
	 * one term per schema property (mapped IRI or zero-config fragment term).
	 *
	 * Result is request-cached per (schema id, updated timestamp).
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The JSON-LD context map.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function buildSchemaContext(Register $register, Schema $schema): array {
		$cacheKey = $this->cacheKey(schema: $schema);
		if (isset($this->contextCache[$cacheKey]) === true) {
			return $this->contextCache[$cacheKey];
		}

		$source  = new JsonLdContextSource(
			schema: $schema,
			jsonld: $this->jsonLdConfig(schema: $schema),
			contextUrl: $this->getSchemaContextUrl(register: $register, schema: $schema)
		);
		$context = $this->schemaContextHead(source: $source);

		foreach ($this->propertyTerms(source: $source) as $name => $term) {
			$context[$name] = $term;
		}

		$this->contextCache[$cacheKey] = $context;
		return $context;
	}//end buildSchemaContext()

	/**
	 * One context term per named schema property (mapped IRI or fragment term).
	 *
	 * @param JsonLdContextSource $source The schema, its JSON-LD configuration and context URL.
	 *
	 * @return array<string, mixed> The property terms, in property order.
	 */
	private function propertyTerms(JsonLdContextSource $source): array {
		$propMap = $this->mappedIris(jsonld: $source->jsonld);

		$terms = [];
		foreach ($source->schema->getProperties() as $name => $definition) {
			if ($this->isNonEmptyString(value: $name) === false) {
				continue;
			}

			if (is_array($definition) === false) {
				$definition = [];
			}

			$terms[$name] = $this->buildPropertyTerm(
				id: $this->termId(mappedIri: ($propMap[$name] ?? null), fallback: $source->contextUrl . '#' . $name),
				definition: $definition
			);
		}

		return $terms;
	}//end propertyTerms()

	/**
	 * The explicit property => vocabulary IRI map of a JSON-LD configuration.
	 *
	 * @param array<string, mixed> $jsonld The schema's JSON-LD configuration.
	 *
	 * @return array<mixed> The map; empty when absent or not an array.
	 */
	private function mappedIris(array $jsonld): array {
		$propMap = ($jsonld['properties'] ?? []);
		if (is_array($propMap) === false) {
			return [];
		}

		return $propMap;
	}//end mappedIris()

	/**
	 * The base prefixes, the vocabulary and the schema's class term of a schema context.
	 *
	 * @param JsonLdContextSource $source The schema, its JSON-LD configuration and context URL.
	 *
	 * @return array<string, mixed> The context so far.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	private function schemaContextHead(JsonLdContextSource $source): array {
		$jsonld = $source->jsonld;
		// Base prefixes always present.
		$context = [
			'or' => self::OR_NAMESPACE,
			'xsd' => self::XSD_NAMESPACE,
		];

		// Vocabulary base, when declared.
		$vocab = ($jsonld['@vocab'] ?? null);
		if (is_string($vocab) === true && $vocab !== '') {
			$context['@vocab'] = $vocab;
		}

		// The schema class term itself (used as @type when no jsonld.type IRI).
		$slug           = JsonLdRouteSlug::of(entity: $source->schema);
		$context[$slug] = $this->classTerm(jsonld: $jsonld, fallback: $source->contextUrl . '#' . $slug);

		return $context;
	}//end schemaContextHead()

	/**
	 * The schema class term: the configured `jsonld.type`, else the fallback.
	 *
	 * @param array<string, mixed> $jsonld   The schema's JSON-LD configuration.
	 * @param string               $fallback The fragment term of the schema slug.
	 *
	 * @return string The class term.
	 */
	private function classTerm(array $jsonld, string $fallback): string {
		if (isset($jsonld['type']) === true && is_string($jsonld['type']) === true && $jsonld['type'] !== '') {
			return $jsonld['type'];
		}

		return $fallback;
	}//end classTerm()

	/**
	 * Build the register-wide context document: a merged term block covering
	 * every schema in the register (later schemas win on term collisions, which
	 * is acceptable for a register-level convenience document; per-schema
	 * contexts remain authoritative).
	 *
	 * @param Register $register The register.
	 * @param array<Schema> $schemas The register's schema entities.
	 *
	 * @return array<string, mixed> The merged JSON-LD context map.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function buildRegisterContext(Register $register, array $schemas): array {
		$context = [
			'or' => self::OR_NAMESPACE,
			'xsd' => self::XSD_NAMESPACE,
		];

		foreach ($schemas as $schema) {
			if (($schema instanceof Schema) === false) {
				continue;
			}

			$context = $this->mergeSchemaTerms(
				context: $context,
				schemaContext: $this->buildSchemaContext(register: $register, schema: $schema)
			);
		}

		return $context;
	}//end buildRegisterContext()

	/**
	 * Merge a schema context into the register context; the shared `or`/`xsd`
	 * prefixes of the base are kept, every other term overwrites.
	 *
	 * @param array<string, mixed> $context       The register context so far.
	 * @param array<string, mixed> $schemaContext One schema's context.
	 *
	 * @return array<string, mixed> The merged context.
	 */
	private function mergeSchemaTerms(array $context, array $schemaContext): array {
		foreach ($schemaContext as $term => $value) {
			// Keep the shared prefixes from the base; merge the rest.
			if ($term === 'or' || $term === 'xsd') {
				continue;
			}

			$context[$term] = $value;
		}

		return $context;
	}//end mergeSchemaTerms()

	/**
	 * Validate a schema's `jsonld` mapping block. Term values must be absolute
	 * IRIs, or compact terms resolvable against a declared `@vocab`. Unknown
	 * property names are tolerated (schemas evolve).
	 *
	 * @param array<string, mixed> $jsonld The `jsonld` configuration block.
	 *
	 * @return array<int, string> A list of human-readable validation errors (empty when valid).
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function validateMapping(array $jsonld): array {
		$vocab = ($jsonld['@vocab'] ?? null);
		if (is_string($vocab) === true) {
			$vocabBase = $vocab;
		} else {
			$vocabBase = null;
		}

		return array_merge(
			$this->vocabErrors(jsonld: $jsonld, vocab: $vocab),
			$this->typeErrors(jsonld: $jsonld, vocab: $vocabBase),
			$this->propertiesErrors(jsonld: $jsonld, vocab: $vocabBase)
		);
	}//end validateMapping()

	/**
	 * The `@vocab` error: a declared `@vocab` must be an absolute IRI.
	 *
	 * @param array<string, mixed> $jsonld The `jsonld` configuration block.
	 * @param mixed                $vocab  The declared `@vocab` value.
	 *
	 * @return array<int, string> The error, or none.
	 */
	private function vocabErrors(array $jsonld, mixed $vocab): array {
		if (array_key_exists('@vocab', $jsonld) === true && $this->isAbsoluteIri(value: $vocab) === false) {
			return ['@vocab must be an absolute IRI'];
		}

		return [];
	}//end vocabErrors()

	/**
	 * The `type` error: a declared type must be a resolvable term.
	 *
	 * @param array<string, mixed> $jsonld The `jsonld` configuration block.
	 * @param string|null          $vocab  The vocabulary base, or null.
	 *
	 * @return array<int, string> The error, or none.
	 */
	private function typeErrors(array $jsonld, ?string $vocab): array {
		if (array_key_exists('type', $jsonld) === true
			&& $this->isResolvableTerm(value: $jsonld['type'], vocab: $vocab) === false
		) {
			return ['type must be an absolute IRI or a term resolvable against @vocab'];
		}

		return [];
	}//end typeErrors()

	/**
	 * The `properties` errors: the block must be an array of resolvable terms.
	 *
	 * @param array<string, mixed> $jsonld The `jsonld` configuration block.
	 * @param string|null          $vocab  The vocabulary base, or null.
	 *
	 * @return array<int, string> One error per bad entry, in order.
	 */
	private function propertiesErrors(array $jsonld, ?string $vocab): array {
		if (array_key_exists('properties', $jsonld) === false) {
			return [];
		}

		if (is_array($jsonld['properties']) === false) {
			return ['properties must be an object mapping property names to IRIs'];
		}

		return $this->unresolvablePropertyErrors(properties: $jsonld['properties'], vocab: $vocab);
	}//end propertiesErrors()

	/**
	 * One error per property mapping that is not a resolvable term.
	 *
	 * @param array<mixed> $properties The `properties` mapping block.
	 * @param string|null  $vocab      The vocabulary base, or null.
	 *
	 * @return array<int, string> The errors, in order.
	 */
	private function unresolvablePropertyErrors(array $properties, ?string $vocab): array {
		$errors = [];
		foreach ($properties as $property => $iri) {
			if ($this->isResolvableTerm(value: $iri, vocab: $vocab) === false) {
				$errors[] = sprintf(
					'properties.%s must be an absolute IRI or a term resolvable against @vocab',
					(string)$property
				);
			}
		}

		return $errors;
	}//end unresolvablePropertyErrors()

	/**
	 * Build a single JSON-LD context term for a schema property.
	 *
	 * @param string               $id         The term's `@id`.
	 * @param array<string, mixed> $definition The JSON-Schema property definition.
	 *
	 * @return array<string, mixed>|string The term definition (string IRI, or expanded term definition).
	 */
	private function buildPropertyTerm(string $id, array $definition): array|string {
		$coercion = $this->coercionForDefinition(definition: $definition);

		// Plain string term when there is no datatype/node coercion.
		if ($coercion === null) {
			return $id;
		}

		return [
			'@id' => $id,
			'@type' => $coercion,
		];
	}//end buildPropertyTerm()

	/**
	 * The `@id` of a property term: the explicit mapping, else the fragment term.
	 *
	 * @param mixed  $mappedIri An explicit vocabulary IRI, or null.
	 * @param string $fallback  The fragment term of the property in the context document.
	 *
	 * @return string The term's `@id`.
	 */
	private function termId(mixed $mappedIri, string $fallback): string {
		if (is_string($mappedIri) === true && $mappedIri !== '') {
			return $mappedIri;
		}

		return $fallback;
	}//end termId()

	/**
	 * Map a JSON-Schema property definition to a JSON-LD `@type` coercion.
	 *
	 * - relation properties (object/array with `$ref`, or `format: uri`) →
	 *   `@id` (node reference)
	 * - `format: date` → `xsd:date`
	 * - `format: date-time` → `xsd:dateTime`
	 * - everything else → null (no coercion)
	 *
	 * @param array<string, mixed> $definition The JSON-Schema property definition.
	 *
	 * @return string|null The coercion value, or null when none applies.
	 */
	private function coercionForDefinition(array $definition): ?string {
		$format = ($definition['format'] ?? null);

		if ($this->isRelationDefinition(definition: $definition) === true) {
			return '@id';
		}

		return match ($format) {
			'uri', 'uri-reference' => '@id',
			'date' => 'xsd:date',
			'date-time' => 'xsd:dateTime',
			default => null,
		};
	}//end coercionForDefinition()

	/**
	 * Determine whether a JSON-Schema property definition is a relation to
	 * another register object (carries a `$ref`, an `objectConfiguration`,
	 * or items with a `$ref`).
	 *
	 * @param array<string, mixed> $definition The JSON-Schema property definition.
	 *
	 * @return bool True when the property references another object.
	 */
	private function isRelationDefinition(array $definition): bool {
		if ($this->carriesReference(definition: $definition) === true) {
			return true;
		}

		$items = ($definition['items'] ?? null);
		return is_array($items) === true && $this->carriesReference(definition: $items) === true;
	}//end isRelationDefinition()

	/**
	 * Whether a definition carries a `$ref` or an `objectConfiguration`.
	 *
	 * @param array<mixed> $definition The definition (or its `items`).
	 *
	 * @return bool True when it references another object.
	 */
	private function carriesReference(array $definition): bool {
		return isset($definition['$ref']) === true || isset($definition['objectConfiguration']) === true;
	}//end carriesReference()

	/**
	 * Read the optional `jsonld` block from a schema's configuration.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The `jsonld` block, or an empty array.
	 */
	private function jsonLdConfig(Schema $schema): array {
		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === false) {
			return [];
		}

		$jsonld = ($configuration['jsonld'] ?? null);
		if (is_array($jsonld) === false) {
			return [];
		}

		return $jsonld;
	}//end jsonLdConfig()

	/**
	 * Compute the request-cache key for a schema context.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The cache key.
	 */
	private function cacheKey(Schema $schema): string {
		$updated = $schema->getUpdated();
		if ($updated !== null) {
			$stamp = $updated->format('U');
		} else {
			$stamp = '0';
		}

		return ((string)$schema->getId()) . ':' . $stamp;
	}//end cacheKey()

	/**
	 * Whether a value is an absolute IRI (has a scheme, e.g. `https:`).
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return bool True when the value is an absolute IRI.
	 */
	public function isAbsoluteIri(mixed $value): bool {
		if (is_string($value) === false || $value === '') {
			return false;
		}

		return preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $value) === 1;
	}//end isAbsoluteIri()

	/**
	 * Whether a value is a resolvable JSON-LD term: an absolute IRI, or a
	 * compact term that resolves against a declared `@vocab`.
	 *
	 * @param mixed $value The candidate term value.
	 * @param string|null $vocab The declared vocabulary base, or null.
	 *
	 * @return bool True when the term is resolvable.
	 */
	private function isResolvableTerm(mixed $value, ?string $vocab): bool {
		if (is_string($value) === false || $value === '') {
			return false;
		}

		if ($this->isAbsoluteIri(value: $value) === true) {
			return true;
		}

		// A bare term (no scheme, no whitespace) is acceptable only when a
		// @vocab base is declared to resolve it against.
		return $this->resolvesAgainstVocab(value: $value, vocab: $vocab);
	}//end isResolvableTerm()

	/**
	 * Whether a bare term resolves against a declared, non-empty `@vocab`.
	 *
	 * @param string      $value The term.
	 * @param string|null $vocab The declared vocabulary base, or null.
	 *
	 * @return bool True when a vocabulary is declared and the term has no whitespace.
	 */
	private function resolvesAgainstVocab(string $value, ?string $vocab): bool {
		return $vocab !== null && $vocab !== '' && preg_match('/\s/', $value) !== 1;
	}//end resolvesAgainstVocab()
}//end class
