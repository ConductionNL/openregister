<?php

/**
 * TaskSubjectLocator — find which register and schema a task's subject lives in.
 *
 * A task records its subject by uuid. Without the register and schema next to
 * it, every read of the subject has to search ALL magic tables with one UNION:
 * measured 2026-10-06 on an instance with 1,664 of them at 1.5 s warm and
 * 5.9 s cold, paid on every `GET /api/flow-tasks`. Locating the subject once,
 * when the task is written, moves that cost from every read to one write.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves subject uuids to their register and schema ids.
 *
 * Location only: it answers WHERE an object is, never whether the caller may
 * read it. Reading stays with the access-checked paths.
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */
class TaskSubjectLocator {

	/**
	 * Uuids per cross-table search.
	 *
	 * The search repeats every uuid placeholder once PER magic table, and
	 * Postgres refuses a statement past 65,535 parameters. Measured
	 * 2026-10-06 on 1,664 tables: 66 uuids (~110,000 parameters) came back
	 * as zero objects, silently, while 5 uuids found theirs. 25 keeps an
	 * instance of up to ~2,600 tables under the limit.
	 */
	private const CHUNK = 25;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $objects The magic-table object mapper.
	 * @param RegisterMapper $registers Loads a located subject's register.
	 * @param SchemaMapper $schemas Loads a located subject's schema.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly MagicMapper $objects,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Locate many subjects with ONE cross-table search.
	 *
	 * @param array<int, string> $uuids The subject uuids.
	 *
	 * @return array<string, array{registerId: int, schemaId: int}> Locations by uuid; uuids not found are absent.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function locateMany(array $uuids): array {
		$uuids = array_values(array_unique(array_filter(array_map('trim', $uuids), fn (string $uuid): bool => $uuid !== '')));
		if ($uuids === []) {
			return [];
		}

		$found = [];
		foreach (array_chunk($uuids, self::CHUNK) as $chunk) {
			try {
				$found = array_merge($found, $this->objects->findMultipleAcrossAllMagicTables(uuids: $chunk));
			} catch (Throwable $failure) {
				$this->logger->debug(
					'[TaskSubjectLocator] Could not locate subjects: ' . $failure->getMessage(),
					['count' => count($chunk)]
				);
			}
		}

		$locations = [];
		foreach ($found as $object) {
			$register = $object->getRegister();
			$schema = $object->getSchema();
			if (is_numeric($register) === false || is_numeric($schema) === false) {
				continue;
			}

			$locations[(string)$object->getUuid()] = [
				'registerId' => (int)$register,
				'schemaId' => (int)$schema,
			];
		}

		return $locations;
	}//end locateMany()

	/**
	 * Fill in `registerId` and `schemaId` for a task's anchor when they are missing.
	 *
	 * A caller that already knows them is trusted and left alone; one that
	 * does not gets them looked up once. A subject that cannot be found leaves
	 * the data unchanged, so the task is still written exactly as before.
	 *
	 * @param array<string, mixed> $data The task fields.
	 *
	 * @return array<string, mixed> The task fields, located where possible.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function withLocation(array $data): array {
		$uuid = trim((string)($data['objectUuid'] ?? ''));
		if ($uuid === '' || (is_numeric($data['registerId'] ?? null) === true && is_numeric($data['schemaId'] ?? null) === true)) {
			return $data;
		}

		$location = ($this->locateMany(uuids: [$uuid])[$uuid] ?? null);
		if ($location === null) {
			return $data;
		}

		return array_merge($data, $location);
	}//end withLocation()

	/**
	 * The subjects of tasks that record their location, read from their own
	 * tables: one indexed query per register and schema pair.
	 *
	 * Replaces, for located tasks, the UNION over every magic table (measured
	 * 2026-10-06: 1.2 to 3.1 s per inbox page on 1,664 tables, 9 to 22 ms this
	 * way). Like that search, it reads context for rows the caller already
	 * sees; it is not an access check. A pair that cannot be read leaves its
	 * subjects to the caller's fallback.
	 *
	 * @param array<int, Task> $tasks The tasks.
	 *
	 * @return array<string, mixed> Subject objects keyed by uuid.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function readLocated(array $tasks): array {
		$found = [];
		foreach ($this->locatedGroups(tasks: $tasks) as $pair => $uuids) {
			[$registerId, $schemaId] = array_map('intval', explode(':', (string)$pair));
			try {
				$found = array_merge(
					$found,
					$this->objects->findObjectsByUuidsInRegisterSchema(
						register: $this->registers->find(id: $registerId, _rbac: false, _multitenancy: false),
						schema: $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false),
						uuids: $uuids
					)
				);
			} catch (Throwable $failure) {
				$this->logger->debug(
					'[TaskSubjectLocator] Could not read located subjects: ' . $failure->getMessage(),
					['pair' => $pair]
				);
			}
		}

		return $found;
	}//end readLocated()

	/**
	 * Located subject uuids grouped by `registerId:schemaId`.
	 *
	 * @param array<int, Task> $tasks The tasks.
	 *
	 * @return array<string, array<int, string>> Uuids per pair.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function locatedGroups(array $tasks): array {
		$groups = [];
		foreach ($tasks as $task) {
			$uuid = trim((string)$task->getObjectUuid());
			if ($uuid === '' || $task->getRegisterId() === null || $task->getSchemaId() === null) {
				continue;
			}

			$groups[$task->getRegisterId() . ':' . $task->getSchemaId()][] = $uuid;
		}

		return $groups;
	}//end locatedGroups()
}//end class
