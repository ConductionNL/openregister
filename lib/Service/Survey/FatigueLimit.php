<?php

/**
 * How often one person may be surveyed: at most so many invitations in so many days.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

/**
 * The survey-fatigue limit (ADR-011 Rule 2 value object).
 */
final class FatigueLimit {

	/**
	 * Build the limit.
	 *
	 * @param int $maximum    The most invitations a person may have inside the period.
	 * @param int $periodDays The period, in days.
	 */
	public function __construct(
		public readonly int $maximum,
		public readonly int $periodDays
	) {
	}//end __construct()
}//end class
