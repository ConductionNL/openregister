<?php

/**
 * The timer tables and the connection that holds one transaction per operation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimerEventMapperInterface;
use OCA\OpenRegister\Db\FlowTimerFireMapperInterface;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCP\IDBConnection;

/**
 * The collaborators of FlowTimerService that store a timer, wired by DI autowiring.
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */
final class FlowTimerStorage {
	/**
	 * Bundle the services.
	 *
	 * @param FlowTimerMapperInterface      $timers The timer table.
	 * @param FlowTimerFireMapperInterface  $fires  The rung dedup ledger.
	 * @param FlowTimerEventMapperInterface $events The append-only history.
	 * @param IDBConnection                 $db     Holds the one transaction per operation.
	 */
	public function __construct(
		public readonly FlowTimerMapperInterface $timers,
		public readonly FlowTimerFireMapperInterface $fires,
		public readonly FlowTimerEventMapperInterface $events,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
