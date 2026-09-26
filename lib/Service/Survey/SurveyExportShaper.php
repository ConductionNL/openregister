<?php

/**
 * Survey answers as rows, one column per question.
 *
 * 🔴 AN ANONYMOUS EXPORT HAS NO RESPONDENT COLUMN AT ALL, NOT AN EMPTY ONE.
 * An empty column is an invitation to a join: somebody opens the file, sees a
 * header the tool promised would be blank, and fills it from the invitation
 * list in the next sheet, because the shape of the file says that is what goes
 * there. A column that is absent cannot be filled in by accident, and a
 * spreadsheet with one fewer heading is the whole difference between a promise
 * kept and a promise that was only ever visual.
 *
 * 🔴 AND THE EXPORT REFUSES BELOW THE MINIMUM, rather than exporting an empty
 * file. An empty file reads as "nobody answered". Four answers from one team
 * exported under an anonymity promise is the disclosure the minimum exists to
 * prevent, and a file is the form in which it leaves the building.
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

use RuntimeException;

/**
 * Shapes answer sets into export rows, or refuses to.
 */
class SurveyExportShaper {

	/**
	 * Wire the shaper.
	 *
	 * @param SurveyRules $rules The rules that decide what may be disclosed.
	 */
	public function __construct(private readonly SurveyRules $rules = new SurveyRules()) {
	}//end __construct()

	/**
	 * The header row.
	 *
	 * @param array<string, mixed>             $survey    The survey.
	 * @param array<int, array<string, mixed>> $questions Its questions, in order.
	 *
	 * @return string[] The column headings.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function headers(array $survey, array $questions): array {
		$headers = ['surveyVersion', 'submittedAt', 'subjectObject'];

		if ((string)($survey['anonymity'] ?? SurveyRules::ATTRIBUTED) !== SurveyRules::ANONYMOUS) {
			$headers[] = 'respondent';
		}

		foreach ($this->ordered(questions: $questions) as $question) {
			$headers[] = (string)($question['text'] ?? $question['slug'] ?? '');
		}

		return $headers;
	}//end headers()

	/**
	 * The rows, one per answer set.
	 *
	 * @param array<string, mixed>             $survey     The survey.
	 * @param array<int, array<string, mixed>> $questions  Its questions.
	 * @param array<int, array<string, mixed>> $answerSets What came back.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RuntimeException When an anonymous survey is below its minimum.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function rows(array $survey, array $questions, array $answerSets): array {
		$disclosure = $this->rules->disclosure(survey: $survey, responseCount: count($answerSets));
		if ($disclosure['withheld'] === true) {
			throw new RuntimeException($disclosure['reason']);
		}

		$anonymous = ((string)($survey['anonymity'] ?? SurveyRules::ATTRIBUTED) === SurveyRules::ANONYMOUS);
		$ordered = $this->ordered(questions: $questions);
		$rows = [];

		foreach ($answerSets as $answerSet) {
			$row = [
				'surveyVersion' => ($answerSet['surveyVersion'] ?? null),
				'submittedAt' => ($answerSet['submittedAt'] ?? null),
				'subjectObject' => ($answerSet['subjectObject'] ?? null),
			];

			if ($anonymous === false) {
				$row['respondent'] = ($answerSet['respondent'] ?? null);
			}

			$byQuestion = [];
			foreach (($answerSet['answers'] ?? []) as $answer) {
				$byQuestion[(string)($answer['question'] ?? '')] = ($answer['value'] ?? null);
			}

			foreach ($ordered as $question) {
				$heading = (string)($question['text'] ?? $question['slug'] ?? '');
				$row[$heading] = ($byQuestion[(string)($question['slug'] ?? '')] ?? null);
			}

			$rows[] = $row;
		}//end foreach

		return $rows;
	}//end rows()

	/**
	 * The questions in their declared order.
	 *
	 * @param array<int, array<string, mixed>> $questions The questions.
	 *
	 * @return array<int, array<string, mixed>> The same questions, ordered.
	 */
	private function ordered(array $questions): array {
		$ordered = $questions;
		usort(
			$ordered,
			static function (array $left, array $right): int {
				return ((int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0));
			}
		);

		return $ordered;
	}//end ordered()
}//end class
