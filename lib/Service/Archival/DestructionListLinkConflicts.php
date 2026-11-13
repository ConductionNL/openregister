<?php

/**
 * OpenRegister DestructionListLinkConflicts
 *
 * The link conflicts of the records on a destruction list, with the schemas
 * that can point at them worked out from the schema definitions.
 *
 * {@see LinkedRetentionConflictFinder} needs to be told which schemas can hold
 * a reference to the entries; this class reads that from the catalogue once per
 * list: every property whose `$ref` (or `items.$ref`) names an entry's schema
 * by id, uuid or slug. A reference by key (`referenceSchema`) is left out: it
 * cites a code rather than a uuid, so the relation index the finder queries
 * does not carry it.
 *
 * Like the retention pass, it reads without RBAC and multitenancy: retention
 * is an obligation of the instance, not of whoever happens to run the check.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Works out the referencing schemas of a list's entries and asks the finder for their conflicts.
 */
class DestructionListLinkConflicts {

	/**
	 * Constructor.
	 *
	 * @param LinkedRetentionConflictFinder $finder    Finds the conflicts once the referencing schemas are known.
	 * @param SchemaMapperInterface         $schemas   The schema catalogue.
	 * @param RegisterMapperInterface       $registers Says which registers hold a schema.
	 * @param LoggerInterface               $logger    For a check that could not run.
	 */
	public function __construct(
		private readonly LinkedRetentionConflictFinder $finder,
		private readonly SchemaMapperInterface $schemas,
		private readonly RegisterMapperInterface $registers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The conflicts of every entry, by entry uuid.
	 *
	 * A check that cannot run does not read as "no conflicts": every entry is
	 * then reported with no conflicts AND `truncated: true`, the flag that
	 * already tells a reviewer the list of conflicts is partial, and the
	 * failure is logged as an error.
	 *
	 * @param array<int, ObjectEntity> $entries The records on the list.
	 *
	 * @return array<string, array{conflicts: array<int, array<string, mixed>>, truncated: bool}>
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
	 */
	public function forEntries(array $entries): array {
		if ($entries === []) {
			return [];
		}

		try {
			return $this->finder->forList(entries: $entries, referencing: $this->referencingSchemas(entries: $entries));
		} catch (Throwable $failure) {
			$this->logger->error(
				'[DestructionListLinkConflicts] the link-conflict check could not run; every entry is marked partial: ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}

		$partial = [];
		foreach ($entries as $entry) {
			$partial[(string) $entry->getUuid()] = ['conflicts' => [], 'truncated' => true];
		}

		return $partial;
	}//end forEntries()

	/**
	 * The schemas, in every register that holds them, whose records can point at an entry.
	 *
	 * @param array<int, ObjectEntity> $entries The records on the list.
	 *
	 * @return array<int, ReferencingSchema>
	 *
	 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
	 */
	public function referencingSchemas(array $entries): array {
		$all     = $this->schemas->findAll(_rbac: false, _multitenancy: false);
		$targets = $this->entrySchemas(entries: $entries, all: $all);
		if ($targets === []) {
			return [];
		}

		$referencing = [];
		foreach ($all as $schema) {
			$fields = $this->fieldsPointingAt(schema: $schema, targets: $targets);
			if ($fields === []) {
				continue;
			}

			foreach ($this->registers->getAllRegisterIdsWithSchema((int) $schema->getId()) as $registerId) {
				$referencing[] = new ReferencingSchema(
					registerId: (int) $registerId,
					schema: $schema,
					fieldName: $fields[0],
					additionalFieldNames: array_slice($fields, 1)
				);
			}
		}

		return $referencing;
	}//end referencingSchemas()

	/**
	 * The schemas of the entries, from the catalogue already loaded.
	 *
	 * @param array<int, ObjectEntity> $entries The records on the list.
	 * @param array<int, Schema>       $all     Every schema.
	 *
	 * @return array<int, Schema>
	 */
	private function entrySchemas(array $entries, array $all): array {
		$ids = [];
		foreach ($entries as $entry) {
			$ids[(string) $entry->getSchema()] = true;
		}

		return array_values(array_filter($all, static fn (Schema $schema): bool => isset($ids[(string) $schema->getId()]) === true));
	}//end entrySchemas()

	/**
	 * The properties of one schema whose reference names any of the targets.
	 *
	 * @param Schema             $schema  The candidate referencing schema.
	 * @param array<int, Schema> $targets The entries' schemas.
	 *
	 * @return array<int, string> The property names.
	 */
	private function fieldsPointingAt(Schema $schema, array $targets): array {
		$fields = [];
		foreach (($schema->getProperties() ?? []) as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$ref = ($property['$ref'] ?? $property['items']['$ref'] ?? null);
			if (is_string($ref) === false || trim($ref) === '') {
				continue;
			}

			foreach ($targets as $target) {
				if (self::refNames(ref: $ref, schema: $target) === true) {
					$fields[] = (string) $name;
					break;
				}
			}
		}

		return $fields;
	}//end fieldsPointingAt()

	/**
	 * Whether a `$ref` names this schema: by id, uuid or slug, as its last path segment.
	 *
	 * @param string $ref    The reference, a bare identifier, a JSON pointer or a URL.
	 * @param Schema $schema The schema.
	 *
	 * @return bool
	 */
	private static function refNames(string $ref, Schema $schema): bool {
		$segments = explode('/', rtrim(explode('?', trim($ref), 2)[0], '/'));
		$last     = (string) end($segments);
		if ($last === '') {
			return false;
		}

		if ($last === (string) $schema->getId()) {
			return true;
		}

		$uuid = (string) $schema->getUuid();
		if ($uuid !== '' && strcasecmp($last, $uuid) === 0) {
			return true;
		}

		$slug = (string) $schema->getSlug();
		return $slug !== '' && strcasecmp($last, $slug) === 0;
	}//end refNames()
}//end class
