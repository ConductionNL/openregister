<?php

/**
 * Answers a survey from an invitation link, with no account.
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
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use Throwable;

/**
 * The respondent's side of an invitation: open it, read the survey, submit the answers.
 *
 * Admission is the access link's (a revoked, disabled or expired link opens
 * nothing); whether the invitation may still be answered is SurveyRules'
 * (expired, already answered, never sent). The submission is written through the
 * ordinary save path, so SurveyWriteListener's checks apply to it exactly as to
 * an answer set written through the API: a missing required answer is refused
 * naming the question, the survey version is stamped, and an anonymous survey's
 * answer set loses its respondent.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The link, its reader, the survey records and the
 *                                                write path are what answering by link is.
 */
class SurveyAnswering {

	/**
	 * The audit action of a submission made through an invitation link.
	 */
	public const ACT_ANSWERED = 'survey.answered';

	/**
	 * The survey fields a respondent sees.
	 */
	private const SURVEY_FIELDS = ['title', 'introduction', 'version', 'anonymity'];

	/**
	 * The question fields a respondent sees.
	 */
	private const QUESTION_FIELDS = ['slug', 'text', 'kind', 'options', 'order', 'required'];

	/**
	 * Constructor.
	 *
	 * @param SurveyRecords              $records The survey, its questions and its tables.
	 * @param SurveyRules                $rules   Whether the invitation may still be answered.
	 * @param ObjectOperationsInterface  $objects Writes the answer set and the answered invitation.
	 * @param ObjectAccessLinksInterface $links   Resolves the link and records its use.
	 * @param AccessLinkReaderInterface  $reader  Loads the object a link opens.
	 * @param ITimeFactory               $clock   The moment of answering.
	 */
	public function __construct(
		private readonly SurveyRecords $records,
		private readonly SurveyRules $rules,
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectAccessLinksInterface $links,
		private readonly AccessLinkReaderInterface $reader,
		private readonly ITimeFactory $clock,
	) {
	}//end __construct()

	/**
	 * The invitation a link opens, or null when the link opens no invitation.
	 *
	 * A link to anything other than a survey invitation is not an invitation, even
	 * when it is live: the answering door must not write answer sets next to a
	 * case someone shared for reading.
	 *
	 * @param string $anchor The link's anchor.
	 *
	 * @return SurveyAnswerVisit|null The visit, or null.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function open(string $anchor): ?SurveyAnswerVisit {
		$link = $this->links->liveObjectLink(anchor: $anchor);
		if ($link === null) {
			return null;
		}

		$invitation = $this->reader->subjectObject(link: $link);
		if ($invitation instanceof ObjectEntity === false) {
			return null;
		}

		try {
			$slug = $this->records->schemaSlugOf(object: $invitation);
		} catch (Throwable $unreadable) {
			unset($unreadable);
			return null;
		}

		if ($slug !== SurveyRecords::INVITATION) {
			return null;
		}

		return new SurveyAnswerVisit(link: $link, invitation: $invitation);
	}//end open()

	/**
	 * The survey and its questions, in order, as the respondent sees them.
	 *
	 * @param SurveyAnswerVisit $visit The followed invitation.
	 *
	 * @return array{survey: array<string, mixed>, questions: array<int, array<string, mixed>>} The form.
	 *
	 * @throws SurveyAnswerRefusedException When the invitation can no longer be answered.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function form(SurveyAnswerVisit $visit): array {
		$survey = $this->answerableSurvey(visit: $visit);
		$questions = $this->records->questions(within: $visit->invitation, surveyUuid: (string)$survey['id']);
		usort(
			$questions,
			static fn (array $left, array $right): int => ((int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0))
		);

		$shown = [];
		foreach ($questions as $question) {
			$shown[] = array_merge(['id' => $question['id']], self::only(data: $question, fields: self::QUESTION_FIELDS));
		}

		return [
			'survey' => array_merge(['id' => $survey['id']], self::only(data: $survey, fields: self::SURVEY_FIELDS)),
			'questions' => $shown,
		];
	}//end form()

	/**
	 * Accept the answers: write the answer set, then mark the invitation answered.
	 *
	 * @param SurveyAnswerVisit                $visit   The followed invitation.
	 * @param array<int, array<string, mixed>> $answers The answers, each `{question, value}`.
	 *
	 * @return array<string, mixed> The written answer set, with its id.
	 *
	 * @throws SurveyAnswerRefusedException When the invitation can no longer be answered, or an answer is missing.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-003-the-answers-are-an-object-linked-to-the-subject
	 */
	public function answer(SurveyAnswerVisit $visit, array $answers): array {
		$survey = $this->answerableSurvey(visit: $visit);
		$invitation = (array)$visit->invitation->getObject();
		$now = $this->clock->now()->format(DateTimeInterface::ATOM);

		try {
			$answerSet = $this->write(
				table: $this->tableOf(within: $visit->invitation, slug: SurveyRecords::ANSWER_SET),
				data: [
					'survey' => (string)$survey['id'],
					'surveyVersion' => (int)($survey['version'] ?? 1),
					'subjectObject' => ($invitation['subjectObject'] ?? null),
					'respondent' => ($invitation['respondent'] ?? null),
					'answers' => self::answersOf(answers: $answers),
					'submittedAt' => $now,
				],
				uuid: null
			);
		} catch (HookStoppedException $refused) {
			throw new SurveyAnswerRefusedException(message: $refused->getMessage(), status: SurveyAnswerRefusedException::INCOMPLETE);
		}

		$invitation['state'] = SurveyRules::ANSWERED;
		$invitation['answeredAt'] = $now;
		$this->write(
			table: $this->tableOf(within: $visit->invitation, slug: SurveyRecords::INVITATION),
			data: $invitation,
			uuid: (string)$visit->invitation->getUuid()
		);

		return $answerSet;
	}//end answer()

	/**
	 * Record one use of the link on the invitation's audit trail.
	 *
	 * @param SurveyAnswerVisit $visit     The followed invitation.
	 * @param string            $act       The act: ObjectAccessLinksInterface::ACT_READ or ACT_ANSWERED.
	 * @param string|null       $ipAddress The address it came from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function recordUse(SurveyAnswerVisit $visit, string $act, ?string $ipAddress): void {
		$this->links->recordObjectUse(
			link: $visit->link,
			object: $visit->invitation,
			act: $act,
			ipAddress: $ipAddress,
			context: ['survey' => (string)(((array)$visit->invitation->getObject())['survey'] ?? '')]
		);
	}//end recordUse()

	/**
	 * The survey of the invitation, once SurveyRules says it may still be answered.
	 *
	 * @param SurveyAnswerVisit $visit The followed invitation.
	 *
	 * @return array<string, mixed> The survey.
	 *
	 * @throws SurveyAnswerRefusedException When it may not.
	 */
	private function answerableSurvey(SurveyAnswerVisit $visit): array {
		$invitation = (array)$visit->invitation->getObject();
		try {
			$survey = $this->records->survey(within: $visit->invitation, surveyUuid: (string)($invitation['survey'] ?? ''));
		} catch (Throwable $missing) {
			unset($missing);
			$survey = null;
		}

		if ($survey === null) {
			throw new SurveyAnswerRefusedException(
				message: 'The survey this invitation asks about no longer exists.',
				status: SurveyAnswerRefusedException::GONE
			);
		}

		$refusal = $this->rules->refuseInvitation(
			visit: new InvitationVisit(invitation: $invitation, survey: $survey, now: $this->clock->now())
		);
		if ($refusal !== null) {
			throw new SurveyAnswerRefusedException(message: $refusal, status: SurveyAnswerRefusedException::GONE);
		}

		return $survey;
	}//end answerableSurvey()

	/**
	 * The table of a sibling schema in the invitation's register.
	 *
	 * @param ObjectEntity $within The invitation.
	 * @param string       $slug   The schema slug.
	 *
	 * @return RegisterSchema The table.
	 *
	 * @throws RuntimeException When the register holds no such schema.
	 */
	private function tableOf(ObjectEntity $within, string $slug): RegisterSchema {
		$table = $this->records->tableOf(within: $within, slug: $slug);
		if ($table === null) {
			throw new RuntimeException(sprintf("The survey register holds no '%s' schema.", $slug));
		}

		return $table;
	}//end tableOf()

	/**
	 * Write a survey record; the survey register's rules decide, not the respondent's (absent) rights.
	 *
	 * @param RegisterSchema       $table The table.
	 * @param array<string, mixed> $data  The record.
	 * @param string|null          $uuid  The uuid to update, or null for a new record.
	 *
	 * @return array<string, mixed> The written record, with its id.
	 */
	private function write(RegisterSchema $table, array $data, ?string $uuid): array {
		$saved = $this->objects->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(
					register: (string)$table->register->getId(),
					schema: (string)$table->schema->getId()
				),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);

		return array_merge((array)$saved->getObject(), ['id' => (string)$saved->getUuid()]);
	}//end write()

	/**
	 * The answers as `{question, value}` pairs; anything else in the submission is dropped.
	 *
	 * @param array<int|string, mixed> $answers The submitted answers.
	 *
	 * @return array<int, array{question: string, value: mixed}> The answers.
	 */
	private static function answersOf(array $answers): array {
		$kept = [];
		foreach ($answers as $answer) {
			if (is_array($answer) === false || is_scalar($answer['question'] ?? null) === false) {
				continue;
			}

			$kept[] = ['question' => (string)$answer['question'], 'value' => ($answer['value'] ?? null)];
		}

		return $kept;
	}//end answersOf()

	/**
	 * The named fields of a record that it has.
	 *
	 * @param array<string, mixed> $data   The record.
	 * @param array<int, string>   $fields The fields to keep.
	 *
	 * @return array<string, mixed> The kept fields.
	 */
	private static function only(array $data, array $fields): array {
		return array_intersect_key($data, array_flip($fields));
	}//end only()
}//end class
