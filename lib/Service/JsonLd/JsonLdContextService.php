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
class JsonLdContextService
{

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
        private readonly IURLGenerator $urlGenerator
    ) {
    }//end __construct()

    /**
     * Build the absolute URL of a per-schema context document.
     *
     * @param Register $register The register the schema belongs to.
     * @param Schema   $schema   The schema.
     *
     * @return string The absolute context-document URL.
     *
     * @spec openspec/specs/json-ld-output/spec.md
     */
    public function getSchemaContextUrl(Register $register, Schema $schema): string
    {
        return $this->urlGenerator->linkToRouteAbsolute(
            'openregister.contexts.schema',
            [
                'register' => $this->slugOf(entity: $register),
                'schema'   => $this->slugOf(entity: $schema),
            ]
        );
    }//end getSchemaContextUrl()

    /**
     * Build the absolute URL of the register-wide context document.
     *
     * @param Register $register The register.
     *
     * @return string The absolute context-document URL.
     *
     * @spec openspec/specs/json-ld-output/spec.md
     */
    public function getRegisterContextUrl(Register $register): string
    {
        return $this->urlGenerator->linkToRouteAbsolute(
            'openregister.contexts.register',
            ['register' => $this->slugOf(entity: $register)]
        );
    }//end getRegisterContextUrl()

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
    public function getTypeForSchema(Schema $schema): string
    {
        $jsonld = $this->jsonLdConfig(schema: $schema);
        if (isset($jsonld['type']) === true && is_string($jsonld['type']) === true && $jsonld['type'] !== '') {
            return $jsonld['type'];
        }

        return $this->slugOf(entity: $schema);
    }//end getTypeForSchema()

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
     * @param Schema   $schema   The schema.
     *
     * @return array<string, mixed> The JSON-LD context map.
     *
     * @spec openspec/specs/json-ld-output/spec.md
     */
    public function buildSchemaContext(Register $register, Schema $schema): array
    {
        $cacheKey = $this->cacheKey(schema: $schema);
        if (isset($this->contextCache[$cacheKey]) === true) {
            return $this->contextCache[$cacheKey];
        }

        $jsonld  = $this->jsonLdConfig(schema: $schema);
        $vocab   = ($jsonld['@vocab'] ?? null);
        $propMap = ($jsonld['properties'] ?? []);
        if (is_array($propMap) === false) {
            $propMap = [];
        }

        $contextUrl = $this->getSchemaContextUrl(register: $register, schema: $schema);

        // Base prefixes always present.
        $context = [
            'or'  => self::OR_NAMESPACE,
            'xsd' => self::XSD_NAMESPACE,
        ];

        // Vocabulary base, when declared.
        if (is_string($vocab) === true && $vocab !== '') {
            $context['@vocab'] = $vocab;
        }

        // The schema class term itself (used as @type when no jsonld.type IRI).
        if (isset($jsonld['type']) === true && is_string($jsonld['type']) === true && $jsonld['type'] !== '') {
            $context[$this->slugOf(entity: $schema)] = $jsonld['type'];
        } else {
            $context[$this->slugOf(entity: $schema)] = $contextUrl.'#'.$this->slugOf(entity: $schema);
        }

        // One term per schema property.
        foreach ($schema->getProperties() as $name => $definition) {
            if (is_string($name) === false || $name === '') {
                continue;
            }

            if (is_array($definition) === true) {
                $propertyDefinition = $definition;
            } else {
                $propertyDefinition = [];
            }

            $context[$name] = $this->buildPropertyTerm(
                name: $name,
                definition: $propertyDefinition,
                mappedIri: ($propMap[$name] ?? null),
                contextUrl: $contextUrl
            );
        }

        $this->contextCache[$cacheKey] = $context;
        return $context;
    }//end buildSchemaContext()

    /**
     * Build the register-wide context document: a merged term block covering
     * every schema in the register (later schemas win on term collisions, which
     * is acceptable for a register-level convenience document; per-schema
     * contexts remain authoritative).
     *
     * @param Register      $register The register.
     * @param array<Schema> $schemas  The register's schema entities.
     *
     * @return array<string, mixed> The merged JSON-LD context map.
     *
     * @spec openspec/specs/json-ld-output/spec.md
     */
    public function buildRegisterContext(Register $register, array $schemas): array
    {
        $context = [
            'or'  => self::OR_NAMESPACE,
            'xsd' => self::XSD_NAMESPACE,
        ];

        foreach ($schemas as $schema) {
            if (($schema instanceof Schema) === false) {
                continue;
            }

            $schemaContext = $this->buildSchemaContext(register: $register, schema: $schema);
            foreach ($schemaContext as $term => $value) {
                // Keep the shared prefixes from the base; merge the rest.
                if ($term === 'or' || $term === 'xsd') {
                    continue;
                }

                $context[$term] = $value;
            }
        }

        return $context;
    }//end buildRegisterContext()

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
    public function validateMapping(array $jsonld): array
    {
        $errors = [];
        $vocab  = ($jsonld['@vocab'] ?? null);

        if (array_key_exists('@vocab', $jsonld) === true && $this->isAbsoluteIri(value: $vocab) === false) {
            $errors[] = '@vocab must be an absolute IRI';
        }

        if (is_string($vocab) === true) {
            $vocabBase = $vocab;
        } else {
            $vocabBase = null;
        }

        if (array_key_exists('type', $jsonld) === true) {
            $type = $jsonld['type'];
            if ($this->isResolvableTerm(value: $type, vocab: $vocabBase) === false) {
                $errors[] = 'type must be an absolute IRI or a term resolvable against @vocab';
            }
        }

        if (array_key_exists('properties', $jsonld) === true) {
            if (is_array($jsonld['properties']) === false) {
                $errors[] = 'properties must be an object mapping property names to IRIs';
            } else {
                foreach ($jsonld['properties'] as $property => $iri) {
                    if ($this->isResolvableTerm(value: $iri, vocab: $vocabBase) === false) {
                        $errors[] = sprintf(
                            "properties.%s must be an absolute IRI or a term resolvable against @vocab",
                            (string) $property
                        );
                    }
                }
            }
        }

        return $errors;
    }//end validateMapping()

    /**
     * Build a single JSON-LD context term for a schema property.
     *
     * @param string               $name       The property name.
     * @param array<string, mixed> $definition The JSON-Schema property definition.
     * @param mixed                $mappedIri  An explicit vocabulary IRI, or null.
     * @param string               $contextUrl The context-document URL (for fallback fragment terms).
     *
     * @return array<string, mixed>|string The term definition (string IRI, or expanded term definition).
     */
    private function buildPropertyTerm(string $name, array $definition, mixed $mappedIri, string $contextUrl): array | string
    {
        // Determine the @id of the term: explicit mapping, else fragment term.
        if (is_string($mappedIri) === true && $mappedIri !== '') {
            $id = $mappedIri;
        } else {
            $id = $contextUrl.'#'.$name;
        }

        $coercion = $this->coercionForDefinition(definition: $definition);

        // Plain string term when there is no datatype/node coercion.
        if ($coercion === null) {
            return $id;
        }

        return [
            '@id'   => $id,
            '@type' => $coercion,
        ];
    }//end buildPropertyTerm()

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
    private function coercionForDefinition(array $definition): ?string
    {
        $format = ($definition['format'] ?? null);

        if ($this->isRelationDefinition(definition: $definition) === true || $format === 'uri' || $format === 'uri-reference') {
            return '@id';
        }

        if ($format === 'date') {
            return 'xsd:date';
        }

        if ($format === 'date-time') {
            return 'xsd:dateTime';
        }

        return null;
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
    private function isRelationDefinition(array $definition): bool
    {
        if (isset($definition['$ref']) === true) {
            return true;
        }

        if (isset($definition['objectConfiguration']) === true) {
            return true;
        }

        $items = ($definition['items'] ?? null);
        if (is_array($items) === true && (isset($items['$ref']) === true || isset($items['objectConfiguration']) === true)) {
            return true;
        }

        return false;
    }//end isRelationDefinition()

    /**
     * Read the optional `jsonld` block from a schema's configuration.
     *
     * @param Schema $schema The schema.
     *
     * @return array<string, mixed> The `jsonld` block, or an empty array.
     */
    private function jsonLdConfig(Schema $schema): array
    {
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
    private function cacheKey(Schema $schema): string
    {
        $updated = $schema->getUpdated();
        if ($updated !== null) {
            $stamp = $updated->format('U');
        } else {
            $stamp = '0';
        }

        return ((string) $schema->getId()).':'.$stamp;
    }//end cacheKey()

    /**
     * Resolve the slug for an entity, falling back to its UUID then id so a
     * route parameter is always available.
     *
     * @param Register|Schema $entity The entity.
     *
     * @return string The slug-like identifier.
     */
    private function slugOf(Register | Schema $entity): string
    {
        $slug = $entity->getSlug();
        if (is_string($slug) === true && $slug !== '') {
            return $slug;
        }

        $uuid = $entity->getUuid();
        if (is_string($uuid) === true && $uuid !== '') {
            return $uuid;
        }

        return (string) $entity->getId();
    }//end slugOf()

    /**
     * Whether a value is an absolute IRI (has a scheme, e.g. `https:`).
     *
     * @param mixed $value The candidate value.
     *
     * @return bool True when the value is an absolute IRI.
     */
    private function isAbsoluteIri(mixed $value): bool
    {
        if (is_string($value) === false || $value === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $value) === 1;
    }//end isAbsoluteIri()

    /**
     * Whether a value is a resolvable JSON-LD term: an absolute IRI, or a
     * compact term that resolves against a declared `@vocab`.
     *
     * @param mixed       $value The candidate term value.
     * @param string|null $vocab The declared vocabulary base, or null.
     *
     * @return bool True when the term is resolvable.
     */
    private function isResolvableTerm(mixed $value, ?string $vocab): bool
    {
        if (is_string($value) === false || $value === '') {
            return false;
        }

        if ($this->isAbsoluteIri(value: $value) === true) {
            return true;
        }

        // A bare term (no scheme, no whitespace) is acceptable only when a
        // @vocab base is declared to resolve it against.
        if ($vocab !== null && $vocab !== '' && preg_match('/\s/', $value) !== 1) {
            return true;
        }

        return false;
    }//end isResolvableTerm()
}//end class
