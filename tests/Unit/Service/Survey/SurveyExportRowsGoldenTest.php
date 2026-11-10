<?php

/**
 * Golden test for the rows SurveyExportShaper exports.
 *
 * Pins every row whole, key order included, for an attributed and an
 * anonymous survey (the anonymous one carries no respondent key at all, even
 * when the stored answer set still holds one), and the refusal below the
 * minimum, so the row builder can be split without changing what leaves the
 * building.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survey
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Survey;

use OCA\OpenRegister\Service\Survey\SurveyExport;
use OCA\OpenRegister\Service\Survey\SurveyExportShaper;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Allowed and refused exports, pinned whole.
 */
class SurveyExportRowsGoldenTest extends TestCase {

	/**
	 * The questions, declared out of order and one without text.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function questions(): array {
		return [
			['slug' => 'better', 'text' => 'Wat kon beter?', 'order' => 2],
			['slug' => 'nps', 'order' => 1],
			['slug' => 'score', 'text' => 'Score', 'order' => 1],
		];
	}//end questions()

	/**
	 * Two answer sets: a full one, and one missing fields and answers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function answerSets(): array {
		return [
			[
				'surveyVersion' => 3,
				'submittedAt' => '2026-11-01',
				'subjectObject' => 'obj-1',
				'respondent' => 'fatima',
				'answers' => [
					['question' => 'score', 'value' => 4],
					['question' => 'better', 'value' => 'koffie'],
					['question' => 'nps', 'value' => 9],
					['question' => 'gone', 'value' => 'x'],
				],
			],
			['answers' => [['value' => 'orphan']]],
		];
	}//end answerSets()

	public function testAnAttributedExportCarriesEveryColumnInDeclaredOrder(): void {
		$rows = (new SurveyExportShaper())->rows(
			new SurveyExport(survey: ['anonymity' => SurveyRules::ATTRIBUTED], questions: self::questions(), answerSets: self::answerSets())
		);

		$this->assertSame(
			[
				[
					'surveyVersion' => 3,
					'submittedAt' => '2026-11-01',
					'subjectObject' => 'obj-1',
					'respondent' => 'fatima',
					'nps' => 9,
					'Score' => 4,
					'Wat kon beter?' => 'koffie',
				],
				[
					'surveyVersion' => null,
					'submittedAt' => null,
					'subjectObject' => null,
					'respondent' => null,
					'nps' => null,
					'Score' => null,
					'Wat kon beter?' => null,
				],
			],
			$rows
		);
	}//end testAnAttributedExportCarriesEveryColumnInDeclaredOrder()

	public function testAnAnonymousExportCarriesNoRespondentKey(): void {
		$survey = ['anonymity' => SurveyRules::ANONYMOUS, 'minimumResponses' => 2];
		$rows = (new SurveyExportShaper())->rows(new SurveyExport(survey: $survey, questions: self::questions(), answerSets: self::answerSets()));

		$this->assertSame(
			['surveyVersion' => 3, 'submittedAt' => '2026-11-01', 'subjectObject' => 'obj-1', 'nps' => 9, 'Score' => 4, 'Wat kon beter?' => 'koffie'],
			$rows[0]
		);
		$this->assertSame(['surveyVersion', 'submittedAt', 'subjectObject', 'nps', 'Score', 'Wat kon beter?'], array_keys($rows[1]));
	}//end testAnAnonymousExportCarriesNoRespondentKey()

	public function testAnAnonymousExportBelowItsMinimumIsRefusedWithTheReason(): void {
		$survey = ['anonymity' => SurveyRules::ANONYMOUS, 'minimumResponses' => 3];
		$reason = (new SurveyRules())->disclosure(survey: $survey, responseCount: 2)['reason'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($reason);

		(new SurveyExportShaper())->rows(new SurveyExport(survey: $survey, questions: self::questions(), answerSets: self::answerSets()));
	}//end testAnAnonymousExportBelowItsMinimumIsRefusedWithTheReason()
}//end class
