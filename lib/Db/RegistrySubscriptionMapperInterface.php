<?php

/**
 * RegistrySubscriptionMapperInterface: what callers outside the Db component use of RegistrySubscriptionMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of RegistrySubscriptionMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to RegistrySubscriptionMapper. Guarded by C04InterfaceBindingsTest.
 */
interface RegistrySubscriptionMapperInterface {

	/**
	 * Every ACTIVE row subscribed to a given registry+identity value — the
	 * inbound update endpoint's lookup path. More than one row can match:
	 * two different objects (in different registers) may legitimately track
	 * the same real-world BSN or KvK number.
	 *
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $identityValue The identity value the registry pushed a change for.
	 *
	 * @return array<int, RegistrySubscription> Matching active rows.
	 */
	public function findActiveByIdentity(string $registry, string $identityValue): array;

	/**
	 * The subscription row for one object, or null when it never requested one.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return RegistrySubscription|null The row, when any.
	 */
	public function findForObject(string $objectUuid): ?RegistrySubscription;

	/**
	 * The subscription rows for one object per batch call, indexed by
	 * object uuid, for the render-mirror choke point to consult without an
	 * N+1 query per listed row.
	 *
	 * @param array<int, string> $objectUuids The object uuids to look up.
	 *
	 * @return array<string, RegistrySubscription> Rows found, keyed by object uuid.
	 */
	public function findForObjects(array $objectUuids): array;

	/**
	 * Insert or update a state row, keyed by object uuid.
	 *
	 * @param RegistrySubscription $subscription The row to persist.
	 *
	 * @return RegistrySubscription The persisted row.
	 */
	public function save(RegistrySubscription $subscription): RegistrySubscription;
}//end interface
