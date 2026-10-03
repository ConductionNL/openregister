<?php

declare(strict_types=1);

/**
 * The promises a survey makes, and the refusals that keep them.
 *
 * Every test below is a promise somebody made to a person who cannot read the
 * code: that they would not be named, that their link would stop working, that
 * their answer would not be shown alongside two others from the same team. A
 * promise kept only when nothing goes wrong is not a promise, so the
 * assertions are on the refusals and on the words they carry.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survey
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

namespace Unit\Service\Survey;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Survey\SurveyExportShaper;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the survey rules and the export shape.
 */
class SurveyRulesTest extends TestCase {

	private SurveyRules $rules;
	private SurveyExportShaper $shaper;

	/**
	 * Wire the two pure collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rules = new SurveyRules();
		$this->shaper = new SurveyExportShaper($this->rules);
	}//end setUp()

	/**
	 * An anonymous survey with a minimum of five.
	 *
	 * @return array<string, mixed> The survey.
	 */
	private function anonymousSurvey(): array {
		return ['anonymity' => SurveyRules::ANONYMOUS, 'minimumResponses' => 5, 'version' => 1];
	}//end anonymousSurvey()

	/**
	 * The questions both export tests use.
	 *
	 * @return array<int, array<string, mixed>> The questions.
	 */
	private function questions(): array {
		return [
			['slug' => 'q2', 'text' => 'Wat kon beter?', 'kind' => 'text', 'order' => 2],
			['slug' => 'q1', 'text' => 'Hoe tevreden bent u?', 'kind' => 'scale', 'order' => 1, 'required' => true],
		];
	}//end questions()

	/**
	 * One answer set.
	 *
	 * @return array<string, mixed> The answer set.
	 */
	private function answerSet(): array {
		return [
			'surveyVersion' => 1,
			'submittedAt' => '2026-09-01T10:00:00+00:00',
			'subjectObject' => 'zaak-1',
			'respondent' => 'fatima@example.nl',
			'answers' => [['question' => 'q1', 'value' => '4'], ['question' => 'q2', 'value' => 'sneller']],
		];
	}//end answerSet()

	/**
	 * 🔴 ANONYMITY CANNOT BE SWITCHED ON. Hiding the names now does not unread
	 * the answers somebody already read by name.
	 *
	 * @return void
	 */
	public function testAnAttributedSurveyCannotBeMadeAnonymous(): void {
		$refusal = $this->rules->refuseAnonymityChange(
			survey: ['anonymity' => SurveyRules::ATTRIBUTED],
			proposed: SurveyRules::ANONYMOUS
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('cannot be made anonymous', $refusal);
	}//end testAnAttributedSurveyCannotBeMadeAnonymous()

	/**
	 * 🔴 AND IT CANNOT BE SWITCHED OFF. The respondents answered on a promise.
	 *
	 * @return void
	 */
	public function testAnAnonymousSurveyCannotBeMadeAttributed(): void {
		$refusal = $this->rules->refuseAnonymityChange(
			survey: $this->anonymousSurvey(),
			proposed: SurveyRules::ATTRIBUTED
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('promise', $refusal);
	}//end testAnAnonymousSurveyCannotBeMadeAttributed()

	/**
	 * Saving a survey without changing its anonymity is not a change, so the
	 * refusal above is not a blanket on every edit.
	 *
	 * @return void
	 */
	public function testSavingTheSameAnonymityIsNotARefusal(): void {
		$this->assertNull(
			$this->rules->refuseAnonymityChange(survey: $this->anonymousSurvey(), proposed: SurveyRules::ANONYMOUS)
		);
	}//end testSavingTheSameAnonymityIsNotARefusal()

	/**
	 * An edit with answers raises the version; without answers it does not.
	 *
	 * @return void
	 */
	public function testEditingASurveyWithAnswersRaisesItsVersion(): void {
		$this->assertSame(2, $this->rules->versionAfterEdit(survey: ['version' => 1], answerSetCount: 12));
		$this->assertSame(1, $this->rules->versionAfterEdit(survey: ['version' => 1], answerSetCount: 0));
	}//end testEditingASurveyWithAnswersRaisesItsVersion()

	/**
	 * An expired link is refused, against a frozen clock.
	 *
	 * @return void
	 */
	public function testAnExpiredInvitationIsRefused(): void {
		$refusal = $this->rules->refuseInvitation(
			invitation: ['state' => SurveyRules::SENT, 'expiresAt' => '2026-09-01T00:00:00+00:00'],
			survey: ['allowReopening' => false],
			now: new DateTimeImmutable('2026-09-02T00:00:00+00:00')
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('expired', $refusal);
	}//end testAnExpiredInvitationIsRefused()

	/**
	 * 🔴 AN EXPIRY THAT WILL NOT PARSE IS NOT AN OPEN INVITATION. Reading it as
	 * "no expiry" turns one malformed timestamp into a link that works forever,
	 * which is the failure nobody would ever notice.
	 *
	 * @return void
	 */
	public function testAnUnparseableExpiryIsTreatedAsExpired(): void {
		$refusal = $this->rules->refuseInvitation(
			invitation: ['state' => SurveyRules::SENT, 'expiresAt' => 'volgende maand'],
			survey: ['allowReopening' => false],
			now: new DateTimeImmutable('2026-09-02T00:00:00+00:00')
		);

		$this->assertNotNull($refusal);
	}//end testAnUnparseableExpiryIsTreatedAsExpired()

	/**
	 * A used link is refused, because a survey answerable twice from one link
	 * cannot be reported on.
	 *
	 * @return void
	 */
	public function testAnAnsweredInvitationIsRefusedUnlessReopeningIsAllowed(): void {
		$invitation = ['state' => SurveyRules::ANSWERED, 'expiresAt' => '2026-12-01T00:00:00+00:00'];
		$now = new DateTimeImmutable('2026-09-02T00:00:00+00:00');

		$this->assertNotNull($this->rules->refuseInvitation(invitation: $invitation, survey: ['allowReopening' => false], now: $now));
		$this->assertNull($this->rules->refuseInvitation(invitation: $invitation, survey: ['allowReopening' => true], now: $now));
	}//end testAnAnsweredInvitationIsRefusedUnlessReopeningIsAllowed()

	/**
	 * A live invitation is not refused, so the three refusals above are not a
	 * blanket that closes every link.
	 *
	 * @return void
	 */
	public function testALiveInvitationIsAccepted(): void {
		$this->assertNull(
			$this->rules->refuseInvitation(
				invitation: ['state' => SurveyRules::SENT, 'expiresAt' => '2026-12-01T00:00:00+00:00'],
				survey: ['allowReopening' => false],
				now: new DateTimeImmutable('2026-09-02T00:00:00+00:00')
			)
		);
	}//end testALiveInvitationIsAccepted()

	/**
	 * A blocked invitation says why when it is followed, rather than looking
	 * like a broken link.
	 *
	 * @return void
	 */
	public function testABlockedInvitationCarriesItsReason(): void {
		$refusal = $this->rules->refuseInvitation(
			invitation: ['state' => SurveyRules::BLOCKED, 'blockedReason' => 'already sent 3 of a maximum 3 surveys in the last 90 days'],
			survey: [],
			now: new DateTimeImmutable('2026-09-02T00:00:00+00:00')
		);

		$this->assertStringContainsString('90 days', (string)$refusal);
	}//end testABlockedInvitationCarriesItsReason()

	/**
	 * 🔴 THE MISSING QUESTION IS NAMED. "Submission failed" sends somebody back
	 * to a form of twenty questions to find the one, and most close the tab.
	 *
	 * @return void
	 */
	public function testAMissingRequiredAnswerIsRefusedByName(): void {
		$refusals = $this->rules->refuseSubmission(
			questions: $this->questions(),
			answers: [['question' => 'q2', 'value' => 'sneller']]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('Hoe tevreden bent u?', $refusals[0]);
	}//end testAMissingRequiredAnswerIsRefusedByName()

	/**
	 * An answer of whitespace is not an answer.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerDoesNotSatisfyARequiredQuestion(): void {
		$refusals = $this->rules->refuseSubmission(
			questions: $this->questions(),
			answers: [['question' => 'q1', 'value' => '   ']]
		);

		$this->assertCount(1, $refusals);
	}//end testAnEmptyAnswerDoesNotSatisfyARequiredQuestion()

	/**
	 * A complete submission is accepted, and an omitted OPTIONAL question is
	 * not refused.
	 *
	 * @return void
	 */
	public function testACompleteSubmissionIsAccepted(): void {
		$this->assertSame(
			[],
			$this->rules->refuseSubmission(questions: $this->questions(), answers: [['question' => 'q1', 'value' => '4']])
		);
	}//end testACompleteSubmissionIsAccepted()

	/**
	 * 🔴 TWO ANSWERS ON AN ANONYMOUS SURVEY ARE WITHHELD, AND THE COUNT IS
	 * SHOWN. An empty result reads as "nobody answered", which is a different
	 * and false statement, and one somebody would report on.
	 *
	 * @return void
	 */
	public function testAnAnonymousSurveyBelowItsMinimumWithholdsAnswersAndSaysWhy(): void {
		$disclosure = $this->rules->disclosure(survey: $this->anonymousSurvey(), responseCount: 2);

		$this->assertTrue($disclosure['withheld']);
		$this->assertSame(2, $disclosure['count']);
		$this->assertStringContainsString('2 of the 5', $disclosure['reason']);
	}//end testAnAnonymousSurveyBelowItsMinimumWithholdsAnswersAndSaysWhy()

	/**
	 * At the minimum the answers are shown, so the threshold is a threshold and
	 * not a permanent block.
	 *
	 * @return void
	 */
	public function testAtTheMinimumTheAnswersAreShown(): void {
		$this->assertFalse($this->rules->disclosure(survey: $this->anonymousSurvey(), responseCount: 5)['withheld']);
	}//end testAtTheMinimumTheAnswersAreShown()

	/**
	 * An attributed survey withholds nothing, however few answers it has.
	 *
	 * @return void
	 */
	public function testAnAttributedSurveyIsNeverWithheld(): void {
		$this->assertFalse(
			$this->rules->disclosure(survey: ['anonymity' => SurveyRules::ATTRIBUTED], responseCount: 1)['withheld']
		);
	}//end testAnAttributedSurveyIsNeverWithheld()

	/**
	 * Fatigue is counted per respondent and blocks at the maximum, with words.
	 *
	 * @return void
	 */
	public function testAnOverSurveyedPersonIsBlockedWithAReason(): void {
		$reason = $this->rules->refuseForFatigue(recentInvitations: 3, maximum: 3, periodDays: 90);

		$this->assertNotNull($reason);
		$this->assertStringContainsString('90 days', $reason);
		$this->assertNull($this->rules->refuseForFatigue(recentInvitations: 2, maximum: 3, periodDays: 90));
	}//end testAnOverSurveyedPersonIsBlockedWithAReason()

	/**
	 * 🔴 ABSENT, NOT EMPTY. An empty respondent property is still a key, still a
	 * place a later write can put a value back without anybody deciding to.
	 *
	 * @return void
	 */
	public function testAnAnonymousAnswerSetHoldsNoRespondentKeyAtAll(): void {
		$stored = $this->rules->scrubRespondent(answerSet: $this->answerSet(), survey: $this->anonymousSurvey());

		$this->assertArrayNotHasKey('respondent', $stored);
		$this->assertStringNotContainsString('fatima', json_encode($stored));
	}//end testAnAnonymousAnswerSetHoldsNoRespondentKeyAtAll()

	/**
	 * An attributed survey keeps its respondent, so the scrub is not blanket.
	 *
	 * @return void
	 */
	public function testAnAttributedAnswerSetKeepsItsRespondent(): void {
		$stored = $this->rules->scrubRespondent(answerSet: $this->answerSet(), survey: ['anonymity' => SurveyRules::ATTRIBUTED]);

		$this->assertSame('fatima@example.nl', $stored['respondent']);
	}//end testAnAttributedAnswerSetKeepsItsRespondent()

	/**
	 * 🔴 THE ANONYMOUS EXPORT HAS NO RESPONDENT HEADING AT ALL. An empty column
	 * is an invitation to a join: the shape of the file says that is what goes
	 * there, and somebody fills it from the invitation list in the next sheet.
	 *
	 * @return void
	 */
	public function testAnAnonymousExportHasNoRespondentColumn(): void {
		$survey = $this->anonymousSurvey();
		$sets = array_fill(0, 5, $this->rules->scrubRespondent(answerSet: $this->answerSet(), survey: $survey));

		$headers = $this->shaper->headers(survey: $survey, questions: $this->questions());
		$rows = $this->shaper->rows(survey: $survey, questions: $this->questions(), answerSets: $sets);

		$this->assertNotContains('respondent', $headers);
		$this->assertArrayNotHasKey('respondent', $rows[0]);
	}//end testAnAnonymousExportHasNoRespondentColumn()

	/**
	 * An attributed export carries the respondent, the version and one column
	 * per question, in the questions' declared order.
	 *
	 * @return void
	 */
	public function testAnAttributedExportCarriesTheRespondentAndTheVersion(): void {
		$survey = ['anonymity' => SurveyRules::ATTRIBUTED];

		$headers = $this->shaper->headers(survey: $survey, questions: $this->questions());
		$rows = $this->shaper->rows(survey: $survey, questions: $this->questions(), answerSets: [$this->answerSet()]);

		$this->assertContains('respondent', $headers);
		$this->assertSame(['surveyVersion', 'submittedAt', 'subjectObject', 'respondent', 'Hoe tevreden bent u?', 'Wat kon beter?'], $headers);
		$this->assertSame(1, $rows[0]['surveyVersion']);
		$this->assertSame('4', $rows[0]['Hoe tevreden bent u?']);
	}//end testAnAttributedExportCarriesTheRespondentAndTheVersion()

	/**
	 * 🔴 THE EXPORT REFUSES BELOW THE MINIMUM RATHER THAN WRITING AN EMPTY
	 * FILE. A file is the form in which a disclosure leaves the building.
	 *
	 * @return void
	 */
	public function testAnAnonymousExportBelowTheMinimumIsRefused(): void {
		$this->expectException(RuntimeException::class);

		$this->shaper->rows(
			survey: $this->anonymousSurvey(),
			questions: $this->questions(),
			answerSets: [$this->answerSet(), $this->answerSet()]
		);
	}//end testAnAnonymousExportBelowTheMinimumIsRefused()
}//end class
