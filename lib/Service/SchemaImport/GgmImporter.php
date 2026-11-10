<?php

/**
 * OpenRegister Schema Import — GgmImporter.
 *
 * Maps a GGM (Gemeentelijk Gegevensmodel) objecttype — resolved from a bundled
 * normalised snapshot or an uploaded GGM export normalised to the same shape —
 * into an OpenRegister register schema. Dutch names/definitions are preserved
 * verbatim as titles/descriptions, attribuutsoorten map to JSON Schema
 * types/formats per the normative table, referentielijst values become `enum`,
 * and relations become single reference properties (never recursive imports).
 *
 * Pure mapping over the injected {@see GgmSnapshot} — no DB dependency.
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
 * Imports GGM objecttypes as register schemas.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
class GgmImporter implements SchemaDialectImporter {
	/**
	 * JSON Schema fragments for the scalar GGM attribute types.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const TYPE_FRAGMENTS = [
		'tekst' => ['type' => 'string'],
		'geheel getal' => ['type' => 'integer'],
		'decimaal' => ['type' => 'number'],
		'boolean' => ['type' => 'boolean'],
		'datum' => ['type' => 'string', 'format' => 'date'],
		'datumtijd' => ['type' => 'string', 'format' => 'date-time'],
	];

	/**
	 * Constructor.
	 *
	 * @param GgmSnapshot $snapshot The indexed GGM snapshot.
	 * @param string|null $sourceLabel Provenance source label (e.g. 'snapshot' or an upload name).
	 */
	public function __construct(
		private readonly GgmSnapshot $snapshot,
		private readonly ?string $sourceLabel = null,
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
		return DialectDetector::DIALECT_GGM;
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
	 * @return array<int, array<string, mixed>> Candidate objecttype records.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function discover(string $query): array {
		$version = $this->snapshot->version();
		$results = [];
		foreach ($this->snapshot->searchObjecttypes($query) as $objecttype) {
			$results[] = [
				'id' => $objecttype['id'],
				'label' => $objecttype['naam'],
				'description' => $objecttype['definitie'],
				'parent' => null,
				'snapshotVersion' => $version,
			];
		}

		return $results;
	}//end discover()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reference The objecttype id or Dutch name.
	 * @param ImportOptions $options Import options.
	 *
	 * @return ImportedSchema The mapped schema.
	 *
	 * @throws SchemaImportException When the objecttype is unknown to the snapshot.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function import(string $reference, ImportOptions $options): ImportedSchema {
		$objecttype = $this->snapshot->findObjecttype($reference);
		if ($objecttype === null) {
			throw SchemaImportException::unknownReference($reference, $this->dialect());
		}

		$attributes = $this->attributesOf(objecttype: $objecttype);

		$unknownRequested = [];
		if ($options->hasSubset() === true) {
			[$attributes, $unknownRequested] = $this->applySubset(attributes: $attributes, subset: $options->propertySubset);
		}

		$properties = $this->mapAttributes(attributes: $attributes);

		$importSource = [
			'dialect' => $this->dialect(),
			'reference' => (string)$objecttype['id'],
			'snapshotVersion' => $this->snapshot->version(),
			'source' => ($this->sourceLabel ?? 'snapshot'),
			'importedAt' => gmdate('c'),
			'baseline' => $properties,
		];

		return new ImportedSchema(
			heading: new ImportedSchemaHeading(title: (string)$objecttype['naam'], description: (string)($objecttype['definitie'] ?? '')),
			definition: new ImportedSchemaDefinition(properties: $properties, jsonld: []),
			importSource: $importSource,
			unknownRequested: $unknownRequested
		);
	}//end import()

	/**
	 * The objecttype's attribuutsoorten, or an empty list when malformed.
	 *
	 * @param array<string, mixed> $objecttype The objecttype record.
	 *
	 * @return array<int|string, mixed> The attribute records.
	 */
	private function attributesOf(array $objecttype): array {
		$attributes = ($objecttype['attribuutsoorten'] ?? []);
		if (is_array($attributes) === false) {
			return [];
		}

		return $attributes;
	}//end attributesOf()

	/**
	 * Map the named attributes to JSON Schema property definitions.
	 *
	 * Entries that are not records or carry no `naam` are skipped.
	 *
	 * @param array<int|string, mixed> $attributes The attribute records.
	 *
	 * @return array<string, array<string, mixed>> Property definitions keyed by name.
	 */
	private function mapAttributes(array $attributes): array {
		$properties = [];
		foreach ($attributes as $attribute) {
			if (is_array($attribute) === false || isset($attribute['naam']) === false) {
				continue;
			}

			$properties[(string)$attribute['naam']] = $this->mapAttribute(attribute: $attribute);
		}

		return $properties;
	}//end mapAttributes()

	/**
	 * Index the named attributes by name; later duplicates win.
	 *
	 * @param array<int|string, mixed> $attributes The attribute records.
	 *
	 * @return array<string, array<string, mixed>> Attributes keyed by name.
	 */
	private function indexByName(array $attributes): array {
		$byName = [];
		foreach ($attributes as $attribute) {
			if (is_array($attribute) === true && isset($attribute['naam']) === true) {
				$byName[(string)$attribute['naam']] = $attribute;
			}
		}

		return $byName;
	}//end indexByName()

	/**
	 * Restrict the attributes to a requested subset, tracking unknown requests.
	 *
	 * @param array<int, array<string, mixed>> $attributes The objecttype attributes.
	 * @param array<int, string> $subset The requested attribute names.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} Selected attributes + unknown names.
	 */
	private function applySubset(array $attributes, array $subset): array {
		$byName = $this->indexByName(attributes: $attributes);

		$selected = [];
		$unknown = [];
		foreach ($subset as $name) {
			if (isset($byName[$name]) === true) {
				$selected[] = $byName[$name];
			} else {
				$unknown[] = $name;
			}
		}

		return [$selected, $unknown];
	}//end applySubset()

	/**
	 * Map one GGM attribuutsoort to a JSON Schema property definition.
	 *
	 * @param array<string, mixed> $attribute The attribuutsoort record.
	 *
	 * @return array<string, mixed> The JSON Schema property definition.
	 */
	private function mapAttribute(array $attribute): array {
		$type = strtolower(trim((string)($attribute['type'] ?? 'tekst')));
		$definition = $this->mapType(type: $type, attribute: $attribute);

		$omschrijving = (string)($attribute['definitie'] ?? '');
		if ($omschrijving !== '') {
			$definition['description'] = $omschrijving;
		}

		// Referentielijst with values present → enum.
		$values = ($attribute['referentielijst']['waarden'] ?? null);
		if (is_array($values) === true && $values !== []) {
			$definition['enum'] = array_values($values);
		}

		return $definition;
	}//end mapAttribute()

	/**
	 * Map a GGM attribute type to a JSON Schema type/format fragment.
	 *
	 * @param string $type The lowercased GGM type label.
	 * @param array<string, mixed> $attribute The full attribute record (for relations).
	 *
	 * @return array<string, mixed> The JSON Schema type fragment.
	 */
	private function mapType(string $type, array $attribute): array {
		if ($type === 'relatie') {
			return $this->relationFragment(attribute: $attribute);
		}

		// Unknown GGM type → safest mapping is free-text string.
		return (self::TYPE_FRAGMENTS[$type] ?? ['type' => 'string']);
	}//end mapType()

	/**
	 * Map a GGM relation to a single reference property (string id), never
	 * a recursive import of the target objecttype.
	 *
	 * @param array<string, mixed> $attribute The relation attribute record.
	 *
	 * @return array<string, mixed> The JSON Schema reference fragment.
	 */
	private function relationFragment(array $attribute): array {
		$target = (string)($attribute['doelObjecttype'] ?? '');
		$fragment = ['type' => 'string', 'format' => 'uri'];
		if ($target !== '') {
			$fragment['description'] = 'Reference to GGM objecttype ' . $target . '.';
		}

		return $fragment;
	}//end relationFragment()
}//end class
