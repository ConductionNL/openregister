<?php

/**
 * OpenRegister Case Plan Bring-Over
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

/**
 * The convergent bring-over of a plan and the timers it arms; either is built or left out when not injected.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
final readonly class CasePlanBringOver {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CasePlanEnsurer|null $ensurer Convergent bring-over; built from the plan's own collaborators when not injected.
	 * @param CasePlanItemTimers|null $timers Arms the deadlines of items the ensurer creates as active, when it is built by the plan service.
	 */
	public function __construct(
		public ?CasePlanEnsurer $ensurer = null,
		public ?CasePlanItemTimers $timers = null,
	) {
	}//end __construct()
}//end class
