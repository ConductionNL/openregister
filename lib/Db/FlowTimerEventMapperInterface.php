<?php

/**
 * FlowTimerEventMapperInterface: what callers outside the Db component use of FlowTimerEventMapper.
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

use OCP\AppFramework\Db\Entity;

/**
 * The methods of FlowTimerEventMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowTimerEventMapper. Guarded by F05InterfaceBindingsTest.
 */
interface FlowTimerEventMapperInterface {

	/**
	 * Insert, stamping `created`.
	 *
	 * @param Entity $entity The event row.
	 *
	 * @return FlowTimerEvent The inserted row.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function insert(Entity $entity): FlowTimerEvent;

	/**
	 * The history of a timer, oldest first.
	 *
	 * @param string $timerUuid The timer uuid.
	 *
	 * @return array<int, FlowTimerEvent> The events.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function findByTimer(string $timerUuid): array;
}//end interface
