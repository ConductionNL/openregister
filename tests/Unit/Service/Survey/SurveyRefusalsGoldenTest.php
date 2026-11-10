<?php

/**
 * Golden test for the invitation and submission refusals of SurveyRules.
 *
 * Pins every refusal sentence and every accepted case, with a frozen moment,
 * so the two checks can be split without changing who may answer or what a
 * respondent is told.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\Survey\InvitationVisit;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use PHPUnit\Framework\TestCase;

/**
 * Refused and accepted invitations and submissions, pinned.
 */
class SurveyRefusalsGoldenTest extends TestCase {

	/**
	 * The refusal for one invitation at a frozen moment.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param array<string, mixed> $survey     The survey.
	 *
	 * @return string|null The refusal.
	 */
	private static function visit(array $invitation, array $survey = []): ?string {
		return (new SurveyRules())->refuseInvitation(
			new InvitationVisit(invitation: $invitation, survey: $survey, now: new DateTimeImmutable('2026-11-10T12:00:00+00:00'))
		);
	}//end visit()

	public function testEveryInvitationOutcome(): void {
		$expired = 'This invitation has expired, so the survey can no longer be answered from this link.';

		$this->assertSame('This invitation was never sent.', self::visit(['state' => SurveyRules::BLOCKED, 'blockedReason' => '  ']));
		$this->assertSame('This invitation was never sent: fatigue', self::visit(['state' => SurveyRules::BLOCKED, 'blockedReason' => ' fatigue ', 'expiresAt' => 'bad']));
		$this->assertSame($expired, self::visit(['expiresAt' => '2026-11-09T00:00:00+00:00']));
		$this->assertSame($expired, self::visit(['expiresAt' => 'not a date']));
		$this->assertSame($expired, self::visit(['state' => SurveyRules::ANSWERED, 'expiresAt' => '2020-01-01'], ['allowReopening' => true]));
		$this->assertSame('This survey has already been answered from this link.', self::visit(['state' => SurveyRules::ANSWERED, 'expiresAt' => '2027-01-01']));
		$this->assertNull(self::visit(['state' => SurveyRules::ANSWERED], ['allowReopening' => true]));
		$this->assertNull(self::visit(['expiresAt' => ' 2026-11-10T12:00:00+00:00 ']));
		$this->assertNull(self::visit([]));
	}//end testEveryInvitationOutcome()

	public function testEveryMissingRequiredQuestionIsNamedInOrder(): void {
		$this->assertSame(
			['This question still needs an answer: "Hoe tevreden bent u?".', 'This question still needs an answer: "q4".'],
			(new SurveyRules())->refuseSubmission(
				questions: [
					['slug' => 'q1', 'text' => 'Hoe tevreden bent u?', 'required' => true],
					['slug' => 'q2', 'text' => 'Optioneel', 'required' => false],
					['slug' => 'q3', 'text' => 'Beantwoord', 'required' => true],
					['id' => 'q4', 'required' => true],
					['slug' => 'q5', 'required' => 'yes'],
				],
				answers: [
					['question' => 'q1', 'value' => '   '],
					['question' => 'q3', 'value' => 0],
					['question' => 'q4'],
					['value' => 'orphan'],
				]
			)
		);
	}//end testEveryMissingRequiredQuestionIsNamedInOrder()
}//end class
