<?php

/**
 * Organisation Usage Service
 *
 * Measures what an organisation uses: its members, the storage of the files on
 * its objects, and the requests the quota middleware counted this hour.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/organisation-usage-measured/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * Measures an organisation's usage.
 *
 * A figure that cannot be measured is null, never 0: a 0 reads as "nothing
 * used", which is a claim this service only makes when it counted.
 */
class OrganisationUsageService {
	/**
	 * Constructor.
	 *
	 * @param MagicMapper     $magicMapper  Reads the folders of the organisation's objects.
	 * @param FileMapper      $fileMapper   Sums file cache sizes.
	 * @param LoggerInterface $logger       Logger.
	 * @param SchemaMapper    $schemaMapper Resolves the schema slug an object quota counts.
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly FileMapper $fileMapper,
		private readonly LoggerInterface $logger,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Measure the organisation's usage.
	 *
	 * - `users`, `groups`: the organisation's members and groups.
	 * - `storage`: bytes of the files in the folders of the organisation's live
	 *   objects; null when it could not be read.
	 * - `requests`: the requests counted for it this hour by the quota
	 *   middleware, which counts only for an organisation with a request quota
	 *   and only with APCu; null otherwise.
	 * - `bandwidth`: null. The middleware counts JSON response bodies only,
	 *   which is not the organisation's bandwidth.
	 * - `objects`: the organisation's live objects of the schema its object
	 *   quota names (every schema when it names none), counted only for an
	 *   organisation with an object quota; null otherwise or when they could
	 *   not be counted.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return array{storage: int|null, bandwidth: null, requests: int|null, objects: int|null, users: int, groups: int}
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function measure(Organisation $organisation): array {
		return [
			'storage' => $this->measureStorage(organisation: $organisation),
			'bandwidth' => null,
			'requests' => $this->measureRequests(organisation: $organisation),
			'objects' => $this->measureObjects(organisation: $organisation),
			'users' => count($organisation->getUserIds()),
			'groups' => count($organisation->getGroups()),
		];
	}//end measure()

	/**
	 * The bytes stored in the folders of the organisation's live objects.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return int|null The bytes, or null when they could not be read.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	private function measureStorage(Organisation $organisation): ?int {
		$uuid = $organisation->getUuid();
		if ($uuid === null || $uuid === '') {
			return null;
		}

		try {
			$folderIds = $this->magicMapper->findFolderIdsByOrganisation(organisationUuid: $uuid);
			return $this->fileMapper->sumSizesByFileIds(fileIds: $folderIds);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[OrganisationUsageService] Could not measure storage',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end measureStorage()

	/**
	 * The organisation's live objects of the schema its object quota names.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return int|null The count, or null without an object quota or when it could not be counted.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	private function measureObjects(Organisation $organisation): ?int {
		$uuid = $organisation->getUuid();
		if ($uuid === null || $uuid === '' || $organisation->getObjectQuota() === null) {
			return null;
		}

		try {
			$schemaIds = [];
			$slug      = (string) ($organisation->getObjectQuotaSchema() ?? '');
			if ($slug !== '') {
				$ids = ($this->schemaMapper->findIdsBySlugs(slugs: [$slug])[strtolower($slug)] ?? []);
				if ($ids === []) {
					return 0;
				}

				$schemaIds = array_map('intval', $ids);
			}

			return $this->magicMapper->countObjectsByOrganisation(organisationUuid: $uuid, schemaIds: $schemaIds);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[OrganisationUsageService] Could not count objects',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end measureObjects()

	/**
	 * The requests counted for the organisation this hour.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return int|null The count, or null when nothing counts them.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	private function measureRequests(Organisation $organisation): ?int {
		$uuid = $organisation->getUuid();
		if ($uuid === null || $uuid === '' || $organisation->getRequestQuota() === null
			|| $this->apcuAvailable() === false
		) {
			return null;
		}

		$hourBucket = (new DateTime())->format('YmdH');
		return ($this->apcuFetchInt(key: "or_quota_{$uuid}_{$hourBucket}") ?? 0);
	}//end measureRequests()

	/**
	 * Whether APCu is enabled.
	 *
	 * @return bool True when APCu can be read.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	protected function apcuAvailable(): bool {
		return function_exists('apcu_enabled') === true && apcu_enabled() === true;
	}//end apcuAvailable()

	/**
	 * Read an integer counter from APCu.
	 *
	 * @param string $key The key.
	 *
	 * @return int|null The value, or null when the key is not set.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	protected function apcuFetchInt(string $key): ?int {
		$success = false;
		$value = apcu_fetch($key, $success);
		if ($success !== true) {
			return null;
		}

		return (int)$value;
	}//end apcuFetchInt()
}//end class
