<?php

/**
 * A survey's results and export, for the readers the survey names.
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
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

/**
 * Reads a survey's answer sets and applies the survey's own rules to them.
 *
 * Who may read follows the survey's `readerRoles` (an administrator always
 * may); an anonymous survey below its minimum shows its count and the reason,
 * never its answers, and refuses its export; an anonymous survey's export has
 * no respondent column at all. The rules themselves live in SurveyRules and
 * SurveyExportShaper; this class only reads the records and applies them.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */
class SurveyResults {

	/**
	 * Constructor.
	 *
	 * @param SurveyRecords      $records The survey, its questions and its answer sets.
	 * @param SurveyRules        $rules   The disclosure and respondent rules.
	 * @param SurveyExportShaper $shaper  Answer sets as rows, one column per question.
	 */
	public function __construct(
		private readonly SurveyRecords $records,
		private readonly SurveyRules $rules = new SurveyRules(),
		private readonly SurveyExportShaper $shaper = new SurveyExportShaper(),
	) {
	}//end __construct()

	/**
	 * The survey, or null when the register holds no such survey.
	 *
	 * @param string $register   The register id, uuid or slug.
	 * @param string $surveyUuid The survey.
	 *
	 * @return array<string, mixed>|null The survey, its uuid under `id`.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-005-who-may-read-the-answers-and-whether-they-are-named
	 */
	public function survey(string $register, string $surveyUuid): ?array {
		return $this->records->survey(within: SurveyRecords::placeIn(register: $register), surveyUuid: $surveyUuid);
	}//end survey()

	/**
	 * Whether a caller may read this survey's answer sets.
	 *
	 * An administrator always may. Anyone else needs one of the survey's
	 * `readerRoles`; a survey that names none is readable by administrators
	 * only, because answers nobody was granted are answers nobody gets.
	 *
	 * @param array<string, mixed> $survey   The survey.
	 * @param array<int, string>   $groupIds The caller's groups.
	 * @param bool                 $isAdmin  Whether the caller is an administrator.
	 *
	 * @return bool True when the caller may read the answers.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-005-who-may-read-the-answers-and-whether-they-are-named
	 */
	public function mayRead(array $survey, array $groupIds, bool $isAdmin): bool {
		if ($isAdmin === true) {
			return true;
		}

		$readerRoles = array_map('strval', array_filter((array)($survey['readerRoles'] ?? []), 'is_scalar'));

		return array_intersect($readerRoles, array_map('strval', $groupIds)) !== [];
	}//end mayRead()

	/**
	 * The survey's results: the count always, the answer sets only when they may be shown.
	 *
	 * @param string               $register The register id, uuid or slug.
	 * @param array<string, mixed> $survey   The survey, as survey() returned it.
	 *
	 * @return array{
	 *     survey: string, version: int, anonymity: string, withheld: bool, count: int, reason: string,
	 *     answerSets: array<int, array<string, mixed>>
	 * }
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-005-who-may-read-the-answers-and-whether-they-are-named
	 */
	public function results(string $register, array $survey): array {
		$answerSets = $this->answerSetsOf(register: $register, survey: $survey);
		$disclosure = $this->rules->disclosure(survey: $survey, responseCount: count($answerSets));

		$shown = [];
		if ($disclosure['withheld'] === false) {
			$shown = $answerSets;
		}

		return [
			'survey' => (string)($survey['id'] ?? ''),
			'version' => (int)($survey['version'] ?? 1),
			'anonymity' => (string)($survey['anonymity'] ?? SurveyRules::ATTRIBUTED),
			'withheld' => $disclosure['withheld'],
			'count' => $disclosure['count'],
			'reason' => $disclosure['reason'],
			'answerSets' => $shown,
		];
	}//end results()

	/**
	 * The survey's answers as an export table: one row per answer set, one column per question.
	 *
	 * @param string               $register The register id, uuid or slug.
	 * @param array<string, mixed> $survey   The survey, as survey() returned it.
	 *
	 * @return SurveyAnswerTable The table; an anonymous survey's has no respondent column.
	 *
	 * @throws SurveyResultsWithheldException When an anonymous survey is below its minimum.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-006-answers-export-as-rows
	 */
	public function exportTable(string $register, array $survey): SurveyAnswerTable {
		$answerSets = $this->answerSetsOf(register: $register, survey: $survey);
		$disclosure = $this->rules->disclosure(survey: $survey, responseCount: count($answerSets));
		if ($disclosure['withheld'] === true) {
			throw new SurveyResultsWithheldException(count: $disclosure['count'], reason: $disclosure['reason']);
		}

		$questions = $this->records->questions(
			within: SurveyRecords::placeIn(register: $register),
			surveyUuid: (string)($survey['id'] ?? '')
		);

		return new SurveyAnswerTable(
			title: (string)($survey['title'] ?? ''),
			columns: array_values($this->shaper->headers(survey: $survey, questions: $questions)),
			rows: $this->shaper->rows(export: new SurveyExport(survey: $survey, questions: $questions, answerSets: $answerSets))
		);
	}//end exportTable()

	/**
	 * The survey's answer sets, with the respondent removed where the survey is anonymous.
	 *
	 * The write path already stores none on an anonymous survey; scrubbing again
	 * on the way out keeps a row written before that rule from leaking a name.
	 *
	 * @param string               $register The register id, uuid or slug.
	 * @param array<string, mixed> $survey   The survey.
	 *
	 * @return array<int, array<string, mixed>> The answer sets.
	 */
	private function answerSetsOf(string $register, array $survey): array {
		$answerSets = $this->records->answerSets(
			within: SurveyRecords::placeIn(register: $register),
			surveyUuid: (string)($survey['id'] ?? '')
		);

		return array_map(
			fn (array $answerSet): array => $this->rules->scrubRespondent(answerSet: $answerSet, survey: $survey),
			$answerSets
		);
	}//end answerSetsOf()
}//end class
