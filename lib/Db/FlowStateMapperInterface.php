<?php

/**
 * FlowStateMapperInterface: what callers outside the Db component use of FlowStateMapper.
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

use OCP\DB\Exception as DbException;

/**
 * The methods of FlowStateMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowStateMapper. Guarded by C04InterfaceBindingsTest.
 */
interface FlowStateMapperInterface {

	/**
	 * The state of one flow, or null when it has none yet.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return FlowState|null The stored state, or null.
	 */
	public function findByFlow(string $flowId): ?FlowState;

	/**
	 * Write a flow's state, creating the row on first use.
	 *
	 * The insert is attempted FIRST and its unique-constraint violation is
	 * caught, rather than checking for an existing row and then writing. That
	 * ordering is deliberate: check-then-write is a lost update waiting for two
	 * concurrent writers, which is exactly what OR#2212 documented at the object
	 * store. Here the `flow_id` unique index decides, so a loser learns it lost
	 * and retries as an update rather than silently overwriting.
	 *
	 * Caught by REASON rather than by emitting dialect-specific upsert SQL, so
	 * it behaves the same on MySQL and PostgreSQL — the pattern
	 * `NotificationDedupeStateMapper` already uses in this codebase.
	 *
	 * @param string $flowId The flow's uuid.
	 * @param array $state The state to store.
	 *
	 * @return FlowState The stored state.
	 *
	 * @throws DbException When the write fails for any reason other than a
	 *                     concurrent insert of the same flow.
	 */
	public function put(string $flowId, array $state): FlowState;
}//end interface
