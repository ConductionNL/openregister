<?php

/**
 * OpenRegister DependantRetentionIndex
 *
 * Knows which schemas date their records from a property on another schema's
 * records, through a relation-based derivation method (`sourceRelation` and
 * `sourceRelationProperty` in the archive block).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * The schemas whose destruction date reads a date off another record.
 */
class DependantRetentionIndex {

	/**
	 * Every schema with a relation-based derivation, memoised per request.
	 *
	 * @var list<array{schema: Schema, relation: string, property: string, target: string|null}>|null
	 */
	private ?array $entries = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper                $schemaMapper Reads the schemas
	 * @param ArchiveActionDateCalculator $calculator   Knows which methods follow a relation
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly ArchiveActionDateCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * The dependants of a source schema.
	 *
	 * A dependant whose relation property declares no `$ref` is included: the
	 * job that follows it only touches records that actually point at the
	 * changed one.
	 *
	 * @param Schema $source The schema of the record that changed
	 *
	 * @return list<array{schema: Schema, relation: string, property: string}> Each dependant schema,
	 *         the property on it holding the reference, and the date property it reads on the source
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function dependantsOf(Schema $source): array {
		$names = array_filter(
			[(string)$source->getId(), (string)$source->getUuid(), (string)$source->getSlug()],
			static fn (string $name): bool => $name !== ''
		);

		$dependants = [];
		foreach ($this->entries() as $entry) {
			if ($entry['target'] !== null && in_array($entry['target'], $names, true) === false) {
				continue;
			}

			$dependants[] = [
				'schema' => $entry['schema'],
				'relation' => $entry['relation'],
				'property' => $entry['property'],
			];
		}

		return $dependants;
	}//end dependantsOf()

	/**
	 * The source date properties that, when changed on a record of this
	 * schema, move another record's destruction date.
	 *
	 * @param Schema $source The schema of the record that changed
	 *
	 * @return list<string> The property names, without duplicates
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function watchedPropertiesOf(Schema $source): array {
		return array_values(array_unique(array_column($this->dependantsOf(source: $source), 'property')));
	}//end watchedPropertiesOf()

	/**
	 * Read every schema once and keep those with a relation-based derivation.
	 *
	 * @return list<array{schema: Schema, relation: string, property: string, target: string|null}>
	 */
	private function entries(): array {
		if ($this->entries !== null) {
			return $this->entries;
		}

		$this->entries = [];
		foreach ($this->schemaMapper->findAll(_rbac: false, _multitenancy: false) as $schema) {
			$archive = ($schema->getArchive() ?? []);
			if (($archive['enabled'] ?? false) !== true
				|| $this->calculator->isRelationMethod(afleidingswijze: (string)($archive['afleidingswijze'] ?? '')) === false
			) {
				continue;
			}

			$relation = ($archive['sourceRelation'] ?? null);
			$property = ($archive['sourceRelationProperty'] ?? null);
			if (is_string($relation) === false || $relation === '' || is_string($property) === false || $property === '') {
				continue;
			}

			$this->entries[] = [
				'schema' => $schema,
				'relation' => $relation,
				'property' => $property,
				'target' => $this->targetOf(schema: $schema, relation: $relation),
			];
		}

		return $this->entries;
	}//end entries()

	/**
	 * The last segment of the `$ref` a relation property declares, or null.
	 *
	 * @param Schema $schema   The dependant schema
	 * @param string $relation The property holding the reference
	 *
	 * @return string|null The referenced schema's id, uuid or slug, as written
	 */
	private function targetOf(Schema $schema, string $relation): ?string {
		$definition = (($schema->getProperties() ?? [])[$relation] ?? []);
		if (is_array($definition) === false) {
			return null;
		}

		$ref = ($definition['$ref'] ?? ($definition['items']['$ref'] ?? null));
		if (is_string($ref) === false || trim($ref) === '') {
			return null;
		}

		$segments = explode('/', rtrim(trim($ref), '/'));

		return end($segments);
	}//end targetOf()
}//end class
