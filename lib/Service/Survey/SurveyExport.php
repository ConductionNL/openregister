<?php

/**
 * One survey export: the survey, its questions and the answer sets to shape.
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
 * The export of one survey's answers (ADR-011 Rule 2 operation object).
 */
final class SurveyExport {

	/**
	 * Build the export request.
	 *
	 * @param array<string, mixed>             $survey     The survey.
	 * @param array<int, array<string, mixed>> $questions  Its questions.
	 * @param array<int, array<string, mixed>> $answerSets What came back.
	 */
	public function __construct(
		public readonly array $survey,
		public readonly array $questions,
		public readonly array $answerSets
	) {
	}//end __construct()
}//end class
