<?php

/**
 * OpenRegister Schema Import — SchemaOrgImporter.
 *
 * Maps a Schema.org type (resolved from a bundled, versioned snapshot) into an
 * OpenRegister register schema: properties derived from the type's declared
 * properties, Schema.org datatypes mapped to JSON Schema types/formats, the
 * `configuration.jsonld` vocabulary block pre-filled (so json-ld-output emits
 * Schema.org-conformant objects with zero manual mapping), and a
 * `configuration.importSource` provenance block recording the baseline.
 *
 * Pure mapping over the injected {@see SchemaOrgSnapshot} — no Nextcloud or DB
 * dependencies — so the full mapping is unit-testable.
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

use OCA\OpenRegister\Exception\SchemaImportException;

/**
 * Imports Schema.org types as register schemas.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
class SchemaOrgImporter implements SchemaDialectImporter {

	/**
	 * The Schema.org `@vocab` base IRI written into the jsonld block.
	 *
	 * @var string
	 */
	private const VOCAB = 'https://schema.org/';

	/**
	 * Permissiveness rank for collapsing a multi-type range to one JSON type.
	 * Higher = more permissive; string wins over everything.
	 *
	 * @var array<string, int>
	 */
	private const PERMISSIVENESS = [
		'string' => 5,
		'number' => 4,
		'integer' => 3,
		'boolean' => 2,
	];

	/**
	 * JSON Schema fragments for the Schema.org datatype ranges; any other
	 * range is a class and becomes a URI reference.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const DATATYPE_FRAGMENTS = [
		'Text' => ['type' => 'string'],
		'PronounceableText' => ['type' => 'string'],
		'CssSelectorType' => ['type' => 'string'],
		'XPathType' => ['type' => 'string'],
		'Number' => ['type' => 'number'],
		'Float' => ['type' => 'number'],
		'Integer' => ['type' => 'integer'],
		'Boolean' => ['type' => 'boolean'],
		'Date' => ['type' => 'string', 'format' => 'date'],
		'DateTime' => ['type' => 'string', 'format' => 'date-time'],
		'Time' => ['type' => 'string', 'format' => 'time'],
		'URL' => ['type' => 'string', 'format' => 'uri'],
	];

	/**
	 * Constructor.
	 *
	 * @param SchemaOrgSnapshot $snapshot The indexed Schema.org vocabulary snapshot.
	 */
	public function __construct(
		private readonly SchemaOrgSnapshot $snapshot,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 *
	 * @return string The dialect key.
	 */
	public function dialect(): string {
		return DialectDetector::DIALECT_SCHEMA_ORG;
	}//end dialect()

	/**
	 * {@inheritDoc}
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 *
	 * @return string The snapshot version.
	 */
	public function snapshotVersion(): string {
		return $this->snapshot->version();
	}//end snapshotVersion()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query The search term.
	 *
	 * @return array<int, array<string, mixed>> Candidate type records.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function discover(string $query): array {
		$version = $this->snapshot->version();
		$results = [];
		foreach ($this->snapshot->searchClasses($query) as $class) {
			$parent = $this->parentNameOf(class: $class);

			$results[] = [
				'id' => $class['iri'],
				'label' => $class['label'],
				'description' => $class['comment'],
				'parent' => $parent,
				'snapshotVersion' => $version,
			];
		}

		return $results;
	}//end discover()

	/**
	 * The bare name of a class's parent, or null when it has none.
	 *
	 * @param array<string, mixed> $class The snapshot class.
	 *
	 * @return string|null The parent's bare name.
	 */
	private function parentNameOf(array $class): ?string {
		if (is_string($class['parent']) === true && $class['parent'] !== '') {
			return $this->snapshot->bareName($class['parent']);
		}

		return null;
	}//end parentNameOf()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reference The type IRI or bare name.
	 * @param ImportOptions $options Import options.
	 *
	 * @return ImportedSchema The mapped schema.
	 *
	 * @throws SchemaImportException When the type is unknown to the snapshot.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function import(string $reference, ImportOptions $options): ImportedSchema {
		$class = $this->snapshot->findClass($reference);
		if ($class === null) {
			throw SchemaImportException::unknownReference($reference, $this->dialect());
		}

		[$sourceProperties, $unknownRequested] = $this->requestedProperties(class: $class, options: $options);
		[$properties, $termMap] = $this->mapProperties(sourceProperties: $sourceProperties);

		return new ImportedSchema(
			heading: new ImportedSchemaHeading(title: $class['label'], description: $class['comment']),
			definition: new ImportedSchemaDefinition(properties: $properties, jsonld: self::jsonLd(class: $class, termMap: $termMap)),
			importSource: $this->importSource(class: $class, properties: $properties),
			unknownRequested: $unknownRequested
		);
	}//end import()

	/**
	 * The class's properties the import asked for, and the requested names the class lacks.
	 *
	 * @param array<string, mixed> $class   The snapshot class.
	 * @param ImportOptions        $options Import options: ancestors and an optional property subset.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>} The properties and the unknown names.
	 */
	private function requestedProperties(array $class, ImportOptions $options): array {
		$sourceProperties = $this->sourcePropertiesOf(
			className: $this->snapshot->bareName($class['iri']),
			includeAncestors: $options->includeAncestors
		);

		// Apply an explicit property subset, tracking unknown requests.
		if ($options->hasSubset() === false) {
			return [$sourceProperties, []];
		}

		$unknownRequested = $this->unknownNames(sourceProperties: $sourceProperties, subset: $options->propertySubset);
		return [$this->selectSubset(sourceProperties: $sourceProperties, subset: $options->propertySubset), $unknownRequested];
	}//end requestedProperties()

	/**
	 * Map the source properties to JSON Schema properties, and record each one's IRI.
	 *
	 * @param array<string, array<string, mixed>> $sourceProperties The source properties by name.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>} The properties and the term map.
	 */
	private function mapProperties(array $sourceProperties): array {
		$properties = [];
		$termMap = [];
		foreach ($sourceProperties as $name => $property) {
			$properties[$name] = $this->mapProperty(property: $property);
			$termMap[$name] = $property['iri'];
		}

		return [$properties, $termMap];
	}//end mapProperties()

	/**
	 * The JSON-LD context of the imported schema.
	 *
	 * @param array<string, mixed>  $class   The snapshot class.
	 * @param array<string, string> $termMap Property name to IRI.
	 *
	 * @return array<string, mixed> The context.
	 */
	private static function jsonLd(array $class, array $termMap): array {
		return [
			'@vocab' => self::VOCAB,
			'type' => $class['iri'],
			'properties' => $termMap,
		];
	}//end jsonLd()

	/**
	 * Where the imported schema came from, with the mapped properties as the merge baseline.
	 *
	 * @param array<string, mixed>                $class      The snapshot class.
	 * @param array<string, array<string, mixed>> $properties The mapped properties.
	 *
	 * @return array<string, mixed> The import source.
	 */
	private function importSource(array $class, array $properties): array {
		return [
			'dialect' => $this->dialect(),
			'reference' => $class['iri'],
			'snapshotVersion' => $this->snapshot->version(),
			'importedAt' => gmdate('c'),
			'baseline' => $properties,
		];
	}//end importSource()

	/**
	 * The source properties of a class: direct only, or with its ancestors'.
	 *
	 * @param string $className        The bare class name.
	 * @param bool   $includeAncestors Whether inherited properties are included.
	 *
	 * @return array<string, array<string, mixed>> Property records keyed by bare name.
	 */
	private function sourcePropertiesOf(string $className, bool $includeAncestors): array {
		if ($includeAncestors === true) {
			return $this->snapshot->propertiesWithAncestors($className);
		}

		return $this->snapshot->directPropertiesOf($className);
	}//end sourcePropertiesOf()

	/**
	 * Restrict the source properties to a requested subset, in subset order.
	 *
	 * @param array<string, array<string, mixed>> $sourceProperties The available properties.
	 * @param array<int, string>                  $subset           The requested property names.
	 *
	 * @return array<string, array<string, mixed>> The selected properties.
	 */
	private function selectSubset(array $sourceProperties, array $subset): array {
		$selected = [];
		foreach ($subset as $name) {
			if (isset($sourceProperties[$name]) === true) {
				$selected[$name] = $sourceProperties[$name];
			}
		}

		return $selected;
	}//end selectSubset()

	/**
	 * The requested property names the source does not carry, in subset order.
	 *
	 * @param array<string, array<string, mixed>> $sourceProperties The available properties.
	 * @param array<int, string>                  $subset           The requested property names.
	 *
	 * @return array<int, string> The unknown names.
	 */
	private function unknownNames(array $sourceProperties, array $subset): array {
		$unknown = [];
		foreach ($subset as $name) {
			if (isset($sourceProperties[$name]) === false) {
				$unknown[] = $name;
			}
		}

		return $unknown;
	}//end unknownNames()

	/**
	 * Map one Schema.org property record to a JSON Schema property definition.
	 *
	 * @param array<string, mixed> $property The snapshot property record.
	 *
	 * @return array<string, mixed> The JSON Schema property definition.
	 */
	private function mapProperty(array $property): array {
		$definition = $this->mapRanges(ranges: $property['ranges']);
		$comment = (string)($property['comment'] ?? '');
		if ($comment !== '') {
			$definition['description'] = $comment;
		}

		return $definition;
	}//end mapProperty()

	/**
	 * Map a Schema.org range list to a JSON Schema type/format.
	 *
	 * Datatype ranges map per the normative table; object-typed ranges (a
	 * class) become a `string` with `format: uri` reference (never a recursive
	 * import); a multi-type range collapses to the most permissive member.
	 *
	 * @param array<int, string> $ranges The rangeIncludes bare names.
	 *
	 * @return array<string, mixed> The JSON Schema type fragment.
	 */
	private function mapRanges(array $ranges): array {
		$mapped = [];
		foreach ($ranges as $range) {
			$mapped[] = $this->mapSingleRange(range: $range);
		}

		if ($mapped === []) {
			// No declared range: default to a free-text string.
			return ['type' => 'string'];
		}

		if (count($mapped) === 1) {
			return $mapped[0];
		}

		return $this->collapseMostPermissive(fragments: $mapped);
	}//end mapRanges()

	/**
	 * Map a single Schema.org range (datatype or class) to a JSON fragment.
	 *
	 * @param string $range The bare range name.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	private function mapSingleRange(string $range): array {
		if (isset(self::DATATYPE_FRAGMENTS[$range]) === true) {
			return self::DATATYPE_FRAGMENTS[$range];
		}

		// Object-typed range: a reference to another type, never a
		// recursive import. Carry the target IRI for traceability.
		return [
			'type' => 'string',
			'format' => 'uri',
			'description' => 'Reference to a ' . $range . ' (' . $this->snapshot->classIri($range) . ').',
		];
	}//end mapSingleRange()

	/**
	 * Collapse a list of mapped fragments to the single most permissive one.
	 *
	 * @param array<int, array<string, mixed>> $fragments The mapped fragments.
	 *
	 * @return array<string, mixed> The most permissive fragment.
	 */
	private function collapseMostPermissive(array $fragments): array {
		$best = $fragments[0];
		$bestRank = (self::PERMISSIVENESS[$best['type']] ?? 0);
		foreach ($fragments as $fragment) {
			$rank = (self::PERMISSIVENESS[$fragment['type']] ?? 0);
			if ($rank > $bestRank) {
				$best = $fragment;
				$bestRank = $rank;
			}
		}

		// When the most permissive choice is a plain string, drop any
		// format/description carried from a more specific sibling.
		if ($best['type'] === 'string' && ($best['format'] ?? null) === 'uri') {
			return ['type' => 'string'];
		}

		return ['type' => $best['type']];
	}//end collapseMostPermissive()
}//end class
