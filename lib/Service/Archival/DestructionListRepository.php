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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the destruction lists an instance holds.
 *
 * @psalm-suppress UnusedClass
 */
class DestructionListRepository implements DestructionListRepositoryInterface {

	/**
	 * The status DestructionExecutionJob gives a list it has carried out.
	 */
	public const EXECUTED_STATUS = 'executed';

	/**
	 * Constructor.
	 *
	 * @param ArchivalRegisterMappers         $mappers         Resolve the configured register and schema; lists are register objects.
	 * @param ArchivalSettingsReaderInterface $settingsHandler Says which register and schema they live in.
	 * @param LoggerInterface                 $logger          Logger for a lookup that fails.
	 */
	public function __construct(
		private readonly ArchivalRegisterMappers $mappers,
		private readonly ArchivalSettingsReaderInterface $settingsHandler,
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
			$scope = $this->configuredScope();
			if ($scope === null) {
				return [];
			}

			return $this->mappers->objectMapper->findAll(
				filters: $this->statusFilter(statuses: $statuses),
				register: $scope['register'],
				schema: $scope['schema']
			);
		} catch (Throwable $e) {
			$this->logger->warning('[DestructionListRepository] Could not read destruction lists: ' . $e->getMessage());
			return [];
		}
	}//end findLists()

	/**
	 * The register and schema destruction lists live in, or null when none are configured.
	 *
	 * @return array{register: \OCA\OpenRegister\Db\Register, schema: \OCA\OpenRegister\Db\Schema}|null The configured register and schema.
	 */
	private function configuredScope(): ?array {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();
		$registerId = ($settings['destructionListRegister'] ?? null);
		$schemaId = ($settings['destructionListSchema'] ?? null);
		if ($registerId === null || $schemaId === null) {
			return null;
		}

		return ['register' => $this->mappers->registerMapper->find((int)$registerId), 'schema' => $this->mappers->schemaMapper->find((int)$schemaId)];
	}//end configuredScope()

	/**
	 * The search filter that keeps these statuses.
	 *
	 * `status`, NOT `object->status`. MagicSearchHandler compares a filter key
	 * against the schema's OWN property names and turns anything it does not
	 * recognise into `1 = 0` rather than an error, so the wrong spelling here
	 * reads as "no lists" on every install.
	 *
	 * @param string[]|null $statuses Statuses to keep, or null for every list.
	 *
	 * @return array<string, string[]> The filter.
	 */
	private function statusFilter(?array $statuses): array {
		if ($statuses === null || $statuses === []) {
			return [];
		}

		return ['status' => $statuses];
	}//end statusFilter()

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
			return $this->mappers->objectMapper->find($uuid, null, null, false, false, false);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[DestructionListRepository] Destruction list ' . $uuid . ' not found: ' . $e->getMessage()
			);
			return null;
		}
	}//end find()

	/**
	 * The destruction certificates stored for executed lists, newest first.
	 *
	 * DestructionExecutionJob stores one `verklaring_van_vernietiging` per
	 * executed list and writes its uuid to the list as `certificateUuid`. An
	 * executed list without a stored certificate (the save failed, or no
	 * archival register was set) is named under `missing`: a statutory record
	 * that is absent must not read as a list that never ran.
	 *
	 * @param string|null $listUuid Only this list's certificate, or null for all.
	 *
	 * @return array{results: array<int, array<string, mixed>>, missing: array<int, string>} Certificates and lists without one.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function findCertificates(?string $listUuid = null): array {
		$results = [];
		$missing = [];
		foreach ($this->executedLists(listUuid: $listUuid) as $list) {
			$certificate = $this->certificateOf(list: $list);
			if ($certificate === null) {
				$missing[] = (string)$list->getUuid();
				continue;
			}

			// The list's own uuid, not the certificate's copy: the job builds the
			// certificate from list data that does not always carry it.
			$results[] = array_merge(($certificate->getObject() ?? []), ['uuid' => $certificate->getUuid(), 'destructionListUuid' => $list->getUuid()]);
		}

		return ['results' => $this->newestFirst(certificates: $results), 'missing' => $missing];
	}//end findCertificates()

	/**
	 * The executed lists, or only the one asked for.
	 *
	 * @param string|null $listUuid Only this list, or null for all.
	 *
	 * @return ObjectEntity[] The executed lists.
	 */
	private function executedLists(?string $listUuid): array {
		$lists = $this->findLists(statuses: [self::EXECUTED_STATUS]);
		if ($listUuid === null) {
			return $lists;
		}

		return array_filter($lists, static fn (ObjectEntity $list): bool => $list->getUuid() === $listUuid);
	}//end executedLists()

	/**
	 * The certificate stored for an executed list, or null when there is none.
	 *
	 * @param ObjectEntity $list The executed list.
	 *
	 * @return ObjectEntity|null The certificate.
	 */
	private function certificateOf(ObjectEntity $list): ?ObjectEntity {
		$certUuid = (($list->getObject() ?? [])['certificateUuid'] ?? null);
		if (is_string($certUuid) === false || $certUuid === '') {
			return null;
		}

		return $this->find(uuid: $certUuid);
	}//end certificateOf()

	/**
	 * Certificates sorted by destruction date, newest first.
	 *
	 * @param array<int, array<string, mixed>> $certificates The certificates.
	 *
	 * @return array<int, array<string, mixed>> The sorted certificates.
	 */
	private function newestFirst(array $certificates): array {
		usort(
			$certificates,
			static fn (array $left, array $right): int => strcmp(
				(string)($right['destructionDate'] ?? ''),
				(string)($left['destructionDate'] ?? '')
			)
		);

		return $certificates;
	}//end newestFirst()

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

		$this->mappers->objectMapper->update($list);
	}//end save()
}//end class
