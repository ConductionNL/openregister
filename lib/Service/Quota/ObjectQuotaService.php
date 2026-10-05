<?php

/**
 * OpenRegister ObjectQuotaService
 *
 * How many objects of a schema one organisation may hold, and how many it holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quota
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quota;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;

/**
 * Reads a schema's per-organisation object quota and counts an organisation's objects.
 *
 * A schema declares the cap as `x-openregister-quota: {"perOrganisation": N}`,
 * a positive integer. Anything else (absent, zero, negative, a string, a
 * float) is no quota, so a typo never turns into a cap of zero that refuses
 * every create.
 *
 * THE COUNT IS THE ORGANISATION'S REAL TOTAL. It runs with RBAC and the
 * organisation filter off and names the organisation explicitly. Counting
 * with the creating user's rights would let a user who can read only their
 * own rows create past the cap, because the rows they cannot see would not
 * be counted.
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */
class ObjectQuotaService {

	/**
	 * The schema configuration key carrying the quota.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-quota';

	/**
	 * Constructor.
	 *
	 * @param MagicMapper    $objects   The object store, for the unrestricted count.
	 * @param RegisterMapper $registers Resolves the register the count runs in.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $objects,
		private readonly RegisterMapper $registers,
	) {
	}//end __construct()

	/**
	 * The per-organisation cap a schema declares, or null when it declares none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return int|null The cap, a positive integer, or null for no quota.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function limitFor(Schema $schema): ?int {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration[self::ANNOTATION] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$limit = ($annotation['perOrganisation'] ?? null);
		if (is_int($limit) === false || $limit < 1) {
			return null;
		}

		return $limit;
	}//end limitFor()

	/**
	 * How many objects of this register and schema the organisation holds.
	 *
	 * Soft-deleted rows are not counted: the search excludes them, as it does
	 * for every list.
	 *
	 * @param int    $registerId       The register id.
	 * @param Schema $schema           The schema.
	 * @param string $organisationUuid The organisation's uuid.
	 *
	 * @return int The count.
	 *
	 * @throws \Throwable When the count cannot be made (it never answers 0 for a failure).
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function count(int $registerId, Schema $schema, string $organisationUuid): int {
		// Through the COUNT path, not searchObjects(): with one register and
		// one schema searchObjects() turns the integer count into [] (live
		// pass O10), and the quota could never fire. OrFail: a count that
		// cannot be made throws instead of answering 0.
		$register = $this->registers->find($registerId, _multitenancy: false, _rbac: false);

		return $this->objects->countObjectsOrFail(
			query: [
				'@self' => ['organisation' => $organisationUuid],
				'_rbac' => false,
				'_multitenancy' => false,
			],
			register: $register,
			schema: $schema
		);
	}//end count()

	/**
	 * The quota status an app shows its administrators.
	 *
	 * @param int    $registerId       The register id.
	 * @param Schema $schema           The schema.
	 * @param string $organisationUuid The organisation's uuid.
	 *
	 * @return array{count: int, limit: int|null, atLimit: bool} The count, the cap (null for none),
	 *                                                           and whether the next create is refused.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function status(int $registerId, Schema $schema, string $organisationUuid): array {
		$count = $this->count(registerId: $registerId, schema: $schema, organisationUuid: $organisationUuid);
		$limit = $this->limitFor(schema: $schema);

		return [
			'count' => $count,
			'limit' => $limit,
			'atLimit' => ($limit !== null && $count >= $limit),
		];
	}//end status()
}//end class
