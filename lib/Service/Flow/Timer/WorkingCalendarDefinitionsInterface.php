<?php

/**
 * Builds a validated working calendar from its stored definition.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The calendar and timer endpoints build a calendar through this, injected,
 * so they do not name the class that builds it.
 */
interface WorkingCalendarDefinitionsInterface {

	/**
	 * Build and validate a calendar from its stored definition.
	 *
	 * @param array<string, mixed> $definition The `working-calendar` object data.
	 *
	 * @return WorkingCalendar The calendar.
	 *
	 * @throws FlowTimerValidationException On any refused definition, naming the fault.
	 */
	public function parse(array $definition): WorkingCalendar;
}//end interface
