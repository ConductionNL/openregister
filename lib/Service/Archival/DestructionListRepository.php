<?php

/**
 * Where destruction lists are kept, for everything that has to read them.
 *
 * 🔴 THIS EXISTS BECAUSE THE ENDPOINT THAT LISTS DESTRUCTION LISTS RETURNED AN
 * EMPTY ARRAY AND A COMMENT. `ArchivalController::listDestructionLists()` said
 * "in a full implementation, this would query the archival register" and handed
 * back `results: []` with `total: 0`, which is the same answer a correctly
 * configured instance with nothing to destroy gives. A worklist built on that
 * would be empty for the same invisible reason.
 *
 * A destruction list is a register object, in the register and schema the
 * archival settings name. Three callers now need to find them: the list
 * endpoint, a reviewer asking what is waiting on them, and the reminder pass.
 * Each writing its own copy of the lookup is how
 * {@see \OCA\OpenRegister\Service\RetentionService::getObjectsOnPendingDestructionLists()}
 * came to filter on a key the search handler silently turned into `1 = 0`.
 *
 * AN UNCONFIGURED INSTANCE ANSWERS "NOT CONFIGURED", NOT "NONE". The two are
 * different facts and a records officer has to be able to tell them apart.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the destruction lists an instance holds.
 *
 * @psalm-suppress UnusedClass
 */
class DestructionListRepository {

	/**
	 * The statuses of a list that still has review work on it.
	 *
	 * @var string[]
	 */
	public const OPEN_STATUSES = ['in_review', 'awaiting_second_approval'];

	/**
	 * Constructor.
	 *
	 * @param MagicMapper            $objectMapper    Destruction lists are register objects.
	 * @param RegisterMapper         $registerMapper  Resolves the configured register.
	 * @param SchemaMapper           $schemaMapper    Resolves the configured schema.
	 * @param ObjectRetentionHandler $settingsHandler Says which register and schema they live in.
	 * @param LoggerInterface        $logger          Logger for a lookup that fails.
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectRetentionHandler $settingsHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Is this instance configured to keep destruction lists at all?
	 *
	 * @return bool True when a register and a schema are named in the archival settings.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function isConfigured(): bool {
		try {
			$settings = $this->settingsHandler->getArchivalSettingsOnly();
		} catch (Throwable $e) {
			$this->logger->warning(
				'[DestructionListRepository] Could not read the archival settings: ' . $e->getMessage()
			);
			return false;
		}

		return (($settings['destructionListRegister'] ?? null) !== null
			&& ($settings['destructionListSchema'] ?? null) !== null);
	}//end isConfigured()

	/**
	 * Every destruction list, optionally narrowed to a set of statuses.
	 *
	 * @param string[]|null $statuses Statuses to keep, or null for every list.
	 *
	 * @return ObjectEntity[] The destruction lists, empty when there are none or none are configured.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function findLists(?array $statuses = null): array {
		try {
			$settings = $this->settingsHandler->getArchivalSettingsOnly();
			$registerId = ($settings['destructionListRegister'] ?? null);
			$schemaId = ($settings['destructionListSchema'] ?? null);

			if ($registerId === null || $schemaId === null) {
				return [];
			}

			$register = $this->registerMapper->find((int)$registerId);
			$schema = $this->schemaMapper->find((int)$schemaId);

			// `status`, NOT `object->status`. MagicSearchHandler compares a
			// filter key against the schema's OWN property names and turns
			// anything it does not recognise into `1 = 0` rather than an error,
			// so the wrong spelling here reads as "no lists" on every install.
			$filters = [];
			if ($statuses !== null && $statuses !== []) {
				$filters['status'] = $statuses;
			}

			return $this->objectMapper->findAll(
				filters: $filters,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[DestructionListRepository] Could not read destruction lists: ' . $e->getMessage()
			);
			return [];
		}//end try
	}//end findLists()

	/**
	 * One destruction list, by uuid.
	 *
	 * @param string $uuid The list's uuid.
	 *
	 * @return ObjectEntity|null The list, or null when there is no such object.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function find(string $uuid): ?ObjectEntity {
		try {
			return $this->objectMapper->find($uuid, null, null, false, false, false);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[DestructionListRepository] Destruction list ' . $uuid . ' not found: ' . $e->getMessage()
			);
			return null;
		}
	}//end find()

	/**
	 * Write a destruction list's own data back.
	 *
	 * @param ObjectEntity         $list     The list object.
	 * @param array<string, mixed> $listData The data to store on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function save(ObjectEntity $list, array $listData): void {
		$list->setObject($listData);

		$this->objectMapper->update($list);
	}//end save()
}//end class
