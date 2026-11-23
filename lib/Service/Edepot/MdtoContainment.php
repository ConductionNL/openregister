<?php

/**
 * OpenRegister MdtoContainment
 *
 * Resolves the records a record contains and the records it is part of, for
 * MDTO's `bevatOnderdeel` and `isOnderdeelVan`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Containment is declared on the containing record's schema
 * (`x-openregister-archival.containment`), so the two directions are found
 * differently:
 *
 * - the parts of a record come off its own annotation; a part the row named
 *   only by id is looked up for its name, and keeps its id as name when the
 *   lookup fails;
 * - the wholes a record is part of are the records that reference it (the
 *   indexed `_relations` column) and whose own declared containment lists it.
 *
 * Both run as the export, past RBAC and tenancy, and a failing lookup leaves
 * the reference out rather than failing the export.
 */
class MdtoContainment {

	/**
	 * @param MagicMapperInterface $objects      Finds a part by id and the records referencing a record.
	 * @param MdtoSourceReader     $sourceReader Reads a record's declared parts and its name.
	 * @param LoggerInterface      $logger       Notes a lookup that failed.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objects,
		private readonly MdtoSourceReader $sourceReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The records this record contains, each with its name and id.
	 *
	 * @param ObjectEntity $object The containing record.
	 *
	 * @return list<array{name: string, id: string}> The parts, in the order the row lists them.
	 *
	 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
	 */
	public function partsOf(ObjectEntity $object): array {
		$parts = [];
		foreach ($this->sourceReader->parts(object: $object) as $part) {
			$parts[] = ['name' => ($part['name'] ?? $this->nameOf(id: $part['id'])), 'id' => $part['id']];
		}

		return $parts;
	}//end partsOf()

	/**
	 * The records this record is part of, each with its name and id.
	 *
	 * @param ObjectEntity $object The contained record.
	 *
	 * @return list<array{name: string, id: string}> The wholes.
	 *
	 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
	 */
	public function wholesOf(ObjectEntity $object): array {
		$uuid = (string) $object->getUuid();
		if ($uuid === '') {
			return [];
		}

		try {
			$referencing = $this->objects->findByRelationUsingRelationsColumn(uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MdtoContainment] Could not look up the records that contain {uuid}: {error}',
				['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		$wholes = [];
		foreach ($referencing as $candidate) {
			$candidateUuid = (string) $candidate->getUuid();
			if ($candidateUuid === '' || $candidateUuid === $uuid || isset($wholes[$candidateUuid]) === true) {
				continue;
			}

			if (in_array($uuid, array_column($this->sourceReader->parts(object: $candidate), 'id'), true) === true) {
				$wholes[$candidateUuid] = ['name' => $this->sourceReader->name(object: $candidate), 'id' => $candidateUuid];
			}
		}

		return array_values($wholes);
	}//end wholesOf()

	/**
	 * The name of the record with this id, or the id when it cannot be read.
	 *
	 * @param string $id The record's id.
	 *
	 * @return string The name.
	 */
	private function nameOf(string $id): string {
		try {
			$name = $this->sourceReader->name(object: $this->objects->find(identifier: $id, _rbac: false, _multitenancy: false));
		} catch (Throwable $e) {
			$this->logger->debug('[MdtoContainment] Could not read the name of part {id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);
			return $id;
		}

		if ($name === '') {
			return $id;
		}

		return $name;
	}//end nameOf()
}//end class
