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
 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Organisation;
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
	 * @param MagicMapper     $magicMapper Reads the folders of the organisation's objects.
	 * @param FileMapper      $fileMapper  Sums file cache sizes.
	 * @param LoggerInterface $logger      Logger.
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly FileMapper $fileMapper,
		private readonly LoggerInterface $logger,
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
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return array{storage: int|null, bandwidth: null, requests: int|null, users: int, groups: int}
	 *
	 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
	 */
	public function measure(Organisation $organisation): array {
		return [
			'storage' => $this->measureStorage(organisation: $organisation),
			'bandwidth' => null,
			'requests' => $this->measureRequests(organisation: $organisation),
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
	 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
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
	 * The requests counted for the organisation this hour.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return int|null The count, or null when nothing counts them.
	 *
	 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
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
	 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
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
	 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
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
