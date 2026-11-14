<?php

/**
 * OfficialsDirectory
 *
 * Reads the organisation's register of officials from the configured source (REQ-DKP-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The officials, as `{name, capacity}` pairs, from an OfficialsSource.
 *
 * A groups source lists the members of each mapped group under the group's
 * capacity, by display name. A schema source lists the source objects' name
 * and capacity properties; it is read as the system, since detection runs in
 * background jobs. An entry whose capacity is not a known one is left out. A
 * source that cannot be read gives no officials, so capacity falls back to role
 * words, and the failure is logged.
 *
 * @spec openspec/changes/detection-knows-the-person/specs/detection-knows-the-person/spec.md#requirement-a-detected-person-gets-a-capacity-suggestion-with-its-source-req-dkp-001
 */
class OfficialsDirectory {

	/**
	 * The most objects a schema source is read for, per detection run.
	 *
	 * @var integer
	 */
	public const LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param IGroupManager      $groupManager The Nextcloud groups, for a groups source.
	 * @param ContainerInterface $container    Resolves the object service lazily, for a schema source.
	 * @param LoggerInterface    $logger       Logs a source that cannot be read.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The officials a source lists.
	 *
	 * @param array<string, mixed>|null $source The normalised source (OfficialsSource::normalise()), or null.
	 *
	 * @return list<array{name: string, capacity: string}> The officials.
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
	 */
	public function officials(?array $source): array {
		if ($source === null) {
			return [];
		}

		try {
			return match ($source['type'] ?? null) {
				OfficialsSource::GROUPS => $this->fromGroups(groups: (array) ($source['groups'] ?? [])),
				OfficialsSource::SCHEMA => $this->fromSchema(source: $source),
				default => [],
			};
		} catch (Throwable $error) {
			$this->logger->warning(
				message: '[OfficialsDirectory] The officials source could not be read; capacity falls back to role words',
				context: ['type' => $source['type'] ?? null, 'error' => $error->getMessage()]
			);
			return [];
		}
	}//end officials()

	/**
	 * The members of each mapped group, under the group's capacity.
	 *
	 * @param array<string, string> $groups The group id to capacity map.
	 *
	 * @return list<array{name: string, capacity: string}>
	 */
	private function fromGroups(array $groups): array {
		$officials = [];
		foreach ($groups as $gid => $capacity) {
			$group = $this->groupManager->get((string) $gid);
			if ($group === null || in_array($capacity, CapacityPolicy::CAPACITIES, true) === false) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$officials[] = ['name' => $user->getDisplayName(), 'capacity' => $capacity];
			}
		}

		return $officials;
	}//end fromGroups()

	/**
	 * The name and capacity of each object of the source schema.
	 *
	 * @param array<string, mixed> $source The schema source.
	 *
	 * @return list<array{name: string, capacity: string}>
	 */
	private function fromSchema(array $source): array {
		$objectService = $this->container->get(ObjectService::class);
		$found = $objectService->findAll(
			config: [
				'filters' => ['register' => $source['register'], 'schema' => $source['schema']],
				'limit' => self::LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		$officials = [];
		foreach ($found as $entry) {
			$data = $entry;
			if ($entry instanceof ObjectEntity) {
				$data = $entry->getObject();
			}

			if (is_array($data) === false) {
				continue;
			}

			$name = trim((string) ($data[$source['nameProperty']] ?? ''));
			$capacity = (string) ($data[$source['capacityProperty']] ?? '');
			if ($name !== '' && in_array($capacity, CapacityPolicy::CAPACITIES, true) === true) {
				$officials[] = ['name' => $name, 'capacity' => $capacity];
			}
		}

		return $officials;
	}//end fromSchema()
}//end class
