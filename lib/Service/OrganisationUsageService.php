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
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Measures an organisation's usage.
 *
 * A figure that cannot be measured is null, never 0: a 0 reads as "nothing
 * used", which is a claim this service only makes when it counted.
 *
 * @spec openspec/specs/organisation-usage-measured/spec.md
 */
class OrganisationUsageService implements OrganisationUsageServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface     $magicMapper  Reads the folders of the organisation's objects.
	 * @param FileMapperInterface      $fileMapper   Sums file cache sizes.
	 * @param LoggerInterface $logger       Logger.
	 * @param SchemaMapperInterface    $schemaMapper Resolves the schema slug an object quota counts.
	 */
	public function __construct(
		private readonly MagicMapperInterface $magicMapper,
		private readonly FileMapperInterface $fileMapper,
		private readonly LoggerInterface $logger,
		private readonly SchemaMapperInterface $schemaMapper,
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
		$uuid = $this->measurableUuid(organisation: $organisation);
		if ($uuid === null) {
			return null;
		}

		try {
			$folderIds = $this->magicMapper->findFolderIdsByOrganisation(organisationUuid: $uuid);
			return $this->fileMapper->sumSizesByFileIds(fileIds: $folderIds);
		} catch (Exception $e) {
			return $this->unmeasured(message: '[OrganisationUsageService] Could not measure storage', uuid: $uuid, error: $e);
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
		$uuid = $this->measurableUuid(organisation: $organisation);
		if ($uuid === null || $organisation->getObjectQuota() === null) {
			return null;
		}

		try {
			return $this->countQuotaObjects(organisation: $organisation, uuid: $uuid);
		} catch (Exception $e) {
			return $this->unmeasured(message: '[OrganisationUsageService] Could not count objects', uuid: $uuid, error: $e);
		}
	}//end measureObjects()

	/**
	 * Count the organisation's live objects of the schema its object quota names.
	 *
	 * @param Organisation $organisation The organisation.
	 * @param string       $uuid         Its uuid.
	 *
	 * @return int The count; 0 when the named schema is unknown.
	 *
	 * @throws Exception When the objects cannot be counted.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	private function countQuotaObjects(Organisation $organisation, string $uuid): int {
		$schemaIds = $this->quotaSchemaIds(organisation: $organisation);
		if ($schemaIds === null) {
			return 0;
		}

		return $this->magicMapper->countObjectsByOrganisation(organisationUuid: $uuid, schemaIds: $schemaIds);
	}//end countQuotaObjects()

	/**
	 * Log a figure that could not be measured; it is null, never 0.
	 *
	 * @param string    $message What could not be measured.
	 * @param string    $uuid    The organisation uuid.
	 * @param Exception $error   Why.
	 *
	 * @return null Always null.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	private function unmeasured(string $message, string $uuid, Exception $error): null {
		$this->logger->warning(message: $message, context: ['uuid' => $uuid, 'error' => $error->getMessage()]);
		return null;
	}//end unmeasured()

	/**
	 * The schema ids the object quota counts.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return list<int>|null Empty for every schema (the quota names none), null when the named schema is unknown.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	private function quotaSchemaIds(Organisation $organisation): ?array {
		$slug = (string) ($organisation->getObjectQuotaSchema() ?? '');
		if ($slug === '') {
			return [];
		}

		$ids = ($this->schemaMapper->findIdsBySlugs(slugs: [$slug])[strtolower($slug)] ?? []);
		if ($ids === []) {
			return null;
		}

		return array_map('intval', $ids);
	}//end quotaSchemaIds()

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
		$uuid = $this->measurableUuid(organisation: $organisation);
		if ($uuid === null || $organisation->getRequestQuota() === null || $this->apcuAvailable() === false) {
			return null;
		}

		$hourBucket = (new DateTime())->format('YmdH');
		return ($this->apcuFetchInt(key: "or_quota_{$uuid}_{$hourBucket}") ?? 0);
	}//end measureRequests()

	/**
	 * The organisation's uuid, or null when it has none to measure by.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return string|null The non-empty uuid, or null.
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	private function measurableUuid(Organisation $organisation): ?string {
		$uuid = $organisation->getUuid();
		if ($uuid === null || $uuid === '') {
			return null;
		}

		return $uuid;
	}//end measurableUuid()

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
