<?php

/**
 * Tests for SurveyResults: who may read, what is withheld, and the export shape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Survey;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Survey\SurveyRecords;
use OCA\OpenRegister\Service\Survey\SurveyResults;
use OCA\OpenRegister\Service\Survey\SurveyResultsWithheldException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The survey's own rules, applied to what its records hold.
 *
 * @covers \OCA\OpenRegister\Service\Survey\SurveyResults
 */
class SurveyResultsTest extends TestCase {

	/**
	 * The records double.
	 *
	 * @var SurveyRecords&MockObject
	 */
	private SurveyRecords&MockObject $records;

	/**
	 * The service under test.
	 *
	 * @var SurveyResults
	 */
	private SurveyResults $results;

	/**
	 * Build the service over a records double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->records = $this->createMock(SurveyRecords::class);
		$this->results = new SurveyResults(records: $this->records);
	}//end setUp()

	/**
	 * Answer sets for a survey, each naming its respondent.
	 *
	 * @param int $count How many.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function answerSets(int $count): array {
		$sets = [];
		for ($i = 1; $i <= $count; $i++) {
			$sets[] = [
				'id' => 'set-' . $i,
				'survey' => 's-1',
				'surveyVersion' => 1,
				'subjectObject' => 'case-' . $i,
				'respondent' => 'person' . $i . '@example.org',
				'submittedAt' => '2026-11-0' . $i,
				'answers' => [['question' => 'q-score', 'value' => (string)$i], ['question' => 'q-why', 'value' => 'because ' . $i]],
			];
		}

		return $sets;
	}//end answerSets()

	/**
	 * The two questions, deliberately out of order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function questions(): array {
		return [
			['id' => 'u2', 'slug' => 'q-why', 'text' => 'Why?', 'order' => 2],
			['id' => 'u1', 'slug' => 'q-score', 'text' => 'How satisfied are you?', 'order' => 1],
		];
	}//end questions()

	/**
	 * The survey is read from the register named, through a stand-in object placed there.
	 *
	 * @return void
	 */
	public function testTheSurveyIsReadFromTheNamedRegister(): void {
		$this->records->expects($this->once())->method('survey')
			->with($this->callback(fn (ObjectEntity $within): bool => $within->getRegister() === 'surveys'), 's-1')
			->willReturn(['id' => 's-1']);

		$this->assertSame(['id' => 's-1'], $this->results->survey(register: 'surveys', surveyUuid: 's-1'));
	}//end testTheSurveyIsReadFromTheNamedRegister()

	/**
	 * Readers: an administrator always, otherwise one of the survey's reader roles; none named is admin-only.
	 *
	 * @return void
	 */
	public function testReadersFollowTheSurveysReaderRoles(): void {
		$survey = ['readerRoles' => ['quality', 'board']];

		$this->assertTrue($this->results->mayRead(survey: $survey, groupIds: ['staff', 'board'], isAdmin: false));
		$this->assertFalse($this->results->mayRead(survey: $survey, groupIds: ['staff'], isAdmin: false), 'A reader outside the roles must be refused.');
		$this->assertTrue($this->results->mayRead(survey: $survey, groupIds: [], isAdmin: true));
		$this->assertFalse($this->results->mayRead(survey: ['readerRoles' => []], groupIds: ['staff'], isAdmin: false));
		$this->assertFalse($this->results->mayRead(survey: [], groupIds: ['admin-like'], isAdmin: false));
	}//end testReadersFollowTheSurveysReaderRoles()

	/**
	 * Two answers on an anonymous survey with a minimum of five: the count and the reason, not the answers.
	 *
	 * @return void
	 */
	public function testAnAnonymousSurveyBelowItsMinimumWithholdsItsAnswers(): void {
		$this->records->method('answerSets')->willReturn(self::answerSets(count: 2));
		$survey = ['id' => 's-1', 'anonymity' => 'anonymous', 'minimumResponses' => 5, 'version' => 3];

		$results = $this->results->results(register: 'surveys', survey: $survey);

		$this->assertTrue($results['withheld']);
		$this->assertSame(2, $results['count']);
		$this->assertSame([], $results['answerSets']);
		$this->assertStringContainsString('2 of the 5', $results['reason']);
		$this->assertSame(3, $results['version']);
	}//end testAnAnonymousSurveyBelowItsMinimumWithholdsItsAnswers()

	/**
	 * Above its minimum an anonymous survey shows its answers, with no respondent on any of them.
	 *
	 * @return void
	 */
	public function testAnAnonymousSurveyAboveItsMinimumShowsAnswersWithoutRespondents(): void {
		$this->records->method('answerSets')->willReturn(self::answerSets(count: 3));
		$survey = ['id' => 's-1', 'anonymity' => 'anonymous', 'minimumResponses' => 3];

		$results = $this->results->results(register: 'surveys', survey: $survey);

		$this->assertFalse($results['withheld']);
		$this->assertCount(3, $results['answerSets']);
		foreach ($results['answerSets'] as $answerSet) {
			$this->assertArrayNotHasKey('respondent', $answerSet);
		}
	}//end testAnAnonymousSurveyAboveItsMinimumShowsAnswersWithoutRespondents()

	/**
	 * An attributed survey is never withheld and keeps its respondents.
	 *
	 * @return void
	 */
	public function testAnAttributedSurveyShowsEveryAnswerWithItsRespondent(): void {
		$this->records->method('answerSets')->willReturn(self::answerSets(count: 1));

		$results = $this->results->results(register: 'surveys', survey: ['id' => 's-1', 'anonymity' => 'attributed', 'minimumResponses' => 5]);

		$this->assertFalse($results['withheld']);
		$this->assertSame('person1@example.org', $results['answerSets'][0]['respondent']);
	}//end testAnAttributedSurveyShowsEveryAnswerWithItsRespondent()

	/**
	 * An attributed export: one row per answer set with the respondent, the version and one column per question in order.
	 *
	 * @return void
	 */
	public function testAnAttributedExportCarriesTheRespondentAndOneColumnPerQuestion(): void {
		$this->records->method('answerSets')->willReturn(self::answerSets(count: 2));
		$this->records->method('questions')->willReturn(self::questions());

		$table = $this->results->exportTable(register: 'surveys', survey: ['id' => 's-1', 'title' => 'After closing', 'anonymity' => 'attributed']);

		$this->assertSame('After closing', $table->title);
		$this->assertSame(
			['surveyVersion', 'submittedAt', 'subjectObject', 'respondent', 'How satisfied are you?', 'Why?'],
			$table->columns
		);
		$this->assertCount(2, $table->rows);
		$this->assertSame('person2@example.org', $table->rows[1]['respondent']);
		$this->assertSame('2', $table->rows[1]['How satisfied are you?']);
		$this->assertSame(1, $table->rows[0]['surveyVersion']);
	}//end testAnAttributedExportCarriesTheRespondentAndOneColumnPerQuestion()

	/**
	 * An anonymous export above its minimum has no respondent column at all.
	 *
	 * @return void
	 */
	public function testAnAnonymousExportHasNoRespondentColumn(): void {
		$this->records->method('answerSets')->willReturn(self::answerSets(count: 5));
		$this->records->method('questions')->willReturn(self::questions());

		$table = $this->results->exportTable(register: 'surveys', survey: ['id' => 's-1', 'anonymity' => 'anonymous', 'minimumResponses' => 5]);

		$this->assertNotContains('respondent', $table->columns);
		foreach ($table->rows as $row) {
			$this->assertArrayNotHasKey('respondent', $row);
		}
	}//end testAnAnonymousExportHasNoRespondentColumn()

	/**
	 * Below its minimum an anonymous survey refuses the export, with the count, and reads no questions.
	 *
	 * @return void
	 */
	public function testAnAnonymousExportBelowItsMinimumIsRefused(): void {
		$this->records->method('answerSets')->willReturn(self::answerSets(count: 4));
		$this->records->expects($this->never())->method('questions');

		try {
			$this->results->exportTable(register: 'surveys', survey: ['id' => 's-1', 'anonymity' => 'anonymous', 'minimumResponses' => 5]);
			$this->fail('A withheld export must be refused.');
		} catch (SurveyResultsWithheldException $withheld) {
			$this->assertSame(4, $withheld->count);
			$this->assertStringContainsString('4 of the 5', $withheld->getMessage());
		}
	}//end testAnAnonymousExportBelowItsMinimumIsRefused()
}//end class
