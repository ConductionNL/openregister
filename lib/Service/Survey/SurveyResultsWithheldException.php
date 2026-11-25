<?php

/**
 * An anonymous survey's answers asked for while it is below its minimum.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-006-answers-export-as-rows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

use RuntimeException;

/**
 * Carries the response count and the reason, so the refusal can say both.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-006-answers-export-as-rows
 */
class SurveyResultsWithheldException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int    $count  How many answer sets the survey has.
	 * @param string $reason Why the answers are withheld.
	 */
	public function __construct(public readonly int $count, string $reason) {
		parent::__construct(message: $reason);
	}//end __construct()
}//end class
