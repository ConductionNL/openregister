<?php

/**
 * The rules a survey obeys, as pure functions over the objects.
 *
 * Every method here answers a question that, answered wrongly and quietly,
 * breaks a promise made to somebody who cannot see the code:
 *
 *  - anonymity cannot be switched, because switching it either names people who
 *    answered on a promise that they would not be, or hides answers somebody
 *    has already acted on by name;
 *  - an anonymous survey's answers are withheld below a minimum, because three
 *    responses from one team identify the people in it, and a chart drawn from
 *    them is a chart of named individuals;
 *  - a token is single use and expires, because a link that can be answered
 *    twice cannot be reported on;
 *  - a missing required answer is refused BY NAME, because "submission failed"
 *    sends somebody back to a form of twenty questions to find the one;
 *  - a blocked invitation is recorded with its reason, because a gap in the
 *    response data with nothing beside it reads as nobody having been asked.
 *
 * Pure on purpose: no database, no clock of its own, no HTTP. The expiry takes
 * the moment as a parameter so a test can freeze it, and every refusal returns
 * a sentence rather than a boolean, because the sentence is the feature.
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

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The survey vocabulary and the refusals that go with it.
 */
class SurveyRules {

	/**
	 * Answers name their respondent.
	 *
	 * @var string
	 */
	public const ATTRIBUTED = 'attributed';

	/**
	 * Answers name nobody, and the answer set holds no respondent at all.
	 *
	 * @var string
	 */
	public const ANONYMOUS = 'anonymous';

	/**
	 * The invitation was sent and is waiting.
	 *
	 * @var string
	 */
	public const SENT = 'sent';

	/**
	 * The invitation was answered.
	 *
	 * @var string
	 */
	public const ANSWERED = 'answered';

	/**
	 * The invitation's link stopped working before it was used.
	 *
	 * @var string
	 */
	public const EXPIRED = 'expired';

	/**
	 * The invitation was never sent, and says why.
	 *
	 * @var string
	 */
	public const BLOCKED = 'blocked';

	/**
	 * The default minimum responses before an anonymous survey shows anything.
	 *
	 * @var int
	 */
	public const DEFAULT_MINIMUM_RESPONSES = 5;

	/**
	 * Whether a survey's anonymity may be changed to the proposed value.
	 *
	 * @param array<string, mixed> $survey   The survey as stored.
	 * @param string               $proposed The anonymity being asked for.
	 *
	 * @return string|null The refusal, or null when nothing changes.
	 */
	public function refuseAnonymityChange(array $survey, string $proposed): ?string {
		$current = (string)($survey['anonymity'] ?? self::ATTRIBUTED);
		if ($current === $proposed) {
			return null;
		}

		if ($proposed === self::ANONYMOUS) {
			return 'This survey was created as attributed and cannot be made anonymous. Its answers have '
				.'already been read by name, and hiding the names now does not unread them.';
		}

		return 'This survey was created as anonymous and cannot be made attributed. Its respondents answered on a promise that they would not be named.';
	}//end refuseAnonymityChange()

	/**
	 * Whether a survey may be edited in place, or must take a new version.
	 *
	 * @param int $answerSetCount How many answer sets already exist.
	 *
	 * @return bool True when the edit must raise the version.
	 */
	public function editRaisesVersion(int $answerSetCount): bool {
		return $answerSetCount > 0;
	}//end editRaisesVersion()

	/**
	 * The version a survey carries after an edit.
	 *
	 * @param array<string, mixed> $survey         The survey as stored.
	 * @param int                  $answerSetCount How many answer sets exist.
	 *
	 * @return int The version to store.
	 */
	public function versionAfterEdit(array $survey, int $answerSetCount): int {
		$version = (int)($survey['version'] ?? 1);
		if ($this->editRaisesVersion(answerSetCount: $answerSetCount) === false) {
			return $version;
		}

		return ($version + 1);
	}//end versionAfterEdit()

	/**
	 * Why this invitation may not be followed, if it may not.
	 *
	 * @param array<string, mixed>   $invitation The invitation as stored.
	 * @param array<string, mixed>   $survey     Its survey.
	 * @param DateTimeInterface|null $now        The moment, for a frozen clock.
	 *
	 * @return string|null The refusal, or null when the link works.
	 */
	public function refuseInvitation(array $invitation, array $survey, ?DateTimeInterface $now = null): ?string {
		$moment = ($now ?? new DateTimeImmutable());
		$state = (string)($invitation['state'] ?? self::SENT);

		if ($state === self::BLOCKED) {
			$reason = trim((string)($invitation['blockedReason'] ?? ''));
			if ($reason === '') {
				return 'This invitation was never sent.';
			}

			return 'This invitation was never sent: '.$reason;
		}

		$expiresAt = trim((string)($invitation['expiresAt'] ?? ''));
		if ($expiresAt !== '') {
			$expiry = strtotime($expiresAt);
			// 🔴 AN EXPIRY THAT WILL NOT PARSE IS NOT AN OPEN INVITATION. Reading
			// it as "no expiry" turns one malformed timestamp into a link that
			// works forever, which is the failure nobody would notice.
			if ($expiry === false || $expiry < $moment->getTimestamp()) {
				return 'This invitation has expired, so the survey can no longer be answered from this link.';
			}
		}

		if ($state === self::ANSWERED && ($survey['allowReopening'] ?? false) !== true) {
			return 'This survey has already been answered from this link.';
		}

		return null;
	}//end refuseInvitation()

	/**
	 * The required questions a submission left out, by name.
	 *
	 * @param array<int, array<string, mixed>> $questions The survey's questions.
	 * @param array<int, array<string, mixed>> $answers   What was submitted.
	 *
	 * @return string[] The refusals, one per missing required question.
	 */
	public function refuseSubmission(array $questions, array $answers): array {
		$answered = [];
		foreach ($answers as $answer) {
			$question = (string)($answer['question'] ?? '');
			$value = $answer['value'] ?? null;
			if ($question === '' || $value === null || trim((string)$value) === '') {
				continue;
			}

			$answered[$question] = true;
		}

		$refusals = [];
		foreach ($questions as $question) {
			if (($question['required'] ?? false) !== true) {
				continue;
			}

			$slug = (string)($question['slug'] ?? $question['id'] ?? '');
			if (isset($answered[$slug]) === true) {
				continue;
			}

			// 🔴 THE QUESTION IS NAMED. "Submission failed" sends somebody back
			// to a form of twenty questions to find the one, and most of them
			// close the tab instead.
			$refusals[] = sprintf(
				'This question still needs an answer: "%s".',
				(string)($question['text'] ?? $slug)
			);
		}

		return $refusals;
	}//end refuseSubmission()

	/**
	 * What a reader may see of an anonymous survey's answers.
	 *
	 * Below the minimum the count is shown and the answers are not, with the
	 * reason. Showing an empty result instead reads as "nobody answered", which
	 * is a different and false statement, and one somebody would report on.
	 *
	 * @param array<string, mixed> $survey       The survey.
	 * @param int                  $responseCount How many answer sets exist.
	 *
	 * @return array{withheld: bool, count: int, reason: string} What to show.
	 */
	public function disclosure(array $survey, int $responseCount): array {
		$anonymity = (string)($survey['anonymity'] ?? self::ATTRIBUTED);
		if ($anonymity !== self::ANONYMOUS) {
			return ['withheld' => false, 'count' => $responseCount, 'reason' => ''];
		}

		$minimum = (int)($survey['minimumResponses'] ?? self::DEFAULT_MINIMUM_RESPONSES);
		if ($responseCount >= $minimum) {
			return ['withheld' => false, 'count' => $responseCount, 'reason' => ''];
		}

		return [
			'withheld' => true,
			'count' => $responseCount,
			'reason' => sprintf(
				'This survey is anonymous and has %d of the %d answers it needs before any of them are '
				.'shown. Fewer than that, and the answers point back at the people who gave them.',
				$responseCount,
				$minimum
			),
		];
	}//end disclosure()

	/**
	 * Why this person may not be invited again, if they may not.
	 *
	 * Evaluated per RESPONDENT across every survey in the instance, not per
	 * survey. Somebody asked four times in a month does not care that it was
	 * four different surveys.
	 *
	 * @param int $recentInvitations How many they have had inside the period.
	 * @param int $maximum           The most they may have.
	 * @param int $periodDays        The period, in days, for the sentence.
	 *
	 * @return string|null The block reason, or null when they may be asked.
	 */
	public function refuseForFatigue(int $recentInvitations, int $maximum, int $periodDays): ?string {
		if ($recentInvitations < $maximum) {
			return null;
		}

		return sprintf(
			'This person has already been sent %d of a maximum %d surveys in the last %d days.',
			$recentInvitations,
			$maximum,
			$periodDays
		);
	}//end refuseForFatigue()

	/**
	 * The answer set to store, with the respondent left off where promised.
	 *
	 * @param array<string, mixed> $answerSet The answer set as submitted.
	 * @param array<string, mixed> $survey    Its survey.
	 *
	 * @return array<string, mixed> The answer set to store.
	 */
	public function scrubRespondent(array $answerSet, array $survey): array {
		if ((string)($survey['anonymity'] ?? self::ATTRIBUTED) !== self::ANONYMOUS) {
			return $answerSet;
		}

		// 🔴 UNSET, NOT EMPTIED. An empty respondent property is still a column,
		// still a key in the JSON, and still a place a later write can put a
		// value back without anybody deciding to. Absent says the promise was
		// kept; empty says somebody has not filled it in yet.
		unset($answerSet['respondent']);

		return $answerSet;
	}//end scrubRespondent()
}//end class
