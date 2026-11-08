<?php

/**
 * OpenRegister Case Rearm Sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\FlowTimerMapper;

/**
 * What the re-arm pass reads: the markers of items without their statutory timer, the plan items and their timers.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
final readonly class CaseRearmSources {

	/**
	 * Bundle the collaborators.
	 *
	 * @param UnarmedTimerMarkers $markers The items that lack their statutory timer.
	 * @param CaseItemMapper $items The plan-item table.
	 * @param FlowTimerMapper $timerRows Reads the timers an item already has.
	 */
	public function __construct(
		public UnarmedTimerMarkers $markers,
		public CaseItemMapper $items,
		public FlowTimerMapper $timerRows,
	) {
	}//end __construct()
}//end class
