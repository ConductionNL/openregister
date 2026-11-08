<?php

/**
 * The timer side of a run migration: a run's open timers and their supersession.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;

/**
 * The timer mapper and timer service FlowRunMigrationService moves a run's
 * open timers with.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */
final class FlowRunMigrationTimers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowTimerMapper  $timers       Open timers of a run.
	 * @param FlowTimerService $timerService Supersession.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
	 */
	public function __construct(
		public readonly FlowTimerMapper $timers,
		public readonly FlowTimerService $timerService,
	) {
	}//end __construct()
}//end class
