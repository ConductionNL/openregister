<?php

/**
 * OpenRegister FlowTimerDiagnosticController services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of FlowTimerDiagnosticController, wired by DI autowiring.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
final class FlowTimerDiagnosticControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession $userSession Who is asking.
	 * @param IGroupManager $groupManager Whether they are an administrator.
	 * @param TermDiagnosticInterface $diagnostic The engine, narrating.
	 * @param WorkingCalendarDefinitionsInterface $calendars Builds and validates the calendar definition.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly TermDiagnosticInterface $diagnostic,
		public readonly WorkingCalendarDefinitionsInterface $calendars,
	) {
	}//end __construct()
}//end class
