<?php

/**
 * Applies the survey rules on every write of a survey or an answer set.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Survey\SurveyRecords;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The survey rules, applied at write time on every door.
 *
 * - a survey's anonymity cannot change after creation (REQ-SURV-005);
 * - editing a survey that has answers raises its version (REQ-SURV-001);
 * - an answer set names the version of the survey it answered, set here and
 *   kept on update (REQ-SURV-001, REQ-SURV-003);
 * - an answer set missing a required answer is refused, naming the question
 *   (REQ-SURV-003);
 * - an anonymous survey's answer set holds no respondent (REQ-SURV-005).
 *
 * Unlike the advisory validators, this guard fails CLOSED: when the survey
 * cannot be read, the write is refused, because letting it through would
 * store a respondent on an anonymous survey or an answer set naming a version
 * whose questions have since changed.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class SurveyWriteListener implements IEventListener {

	/**
	 * The error code of every refusal.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'survey_rule';

	/**
	 * Constructor.
	 *
	 * @param SurveyRecords   $records The survey, its questions and its answers.
	 * @param SurveyRules     $rules   The survey rules.
	 * @param LoggerInterface $logger  Reports a guard that could not read.
	 */
	public function __construct(
		private readonly SurveyRecords $records,
		private readonly SurveyRules $rules,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the rules to a survey or answer set being created or updated.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->apply(event: $event, object: $event->getObject(), stored: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->apply(event: $event, object: $event->getNewObject(), stored: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Route the write to the rule set of its schema.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event.
	 * @param ObjectEntity                            $object The object as it will be stored.
	 * @param ObjectEntity|null                       $stored The object as stored, on update.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object, ?ObjectEntity $stored): void {
		try {
			$slug = $this->records->schemaSlugOf(object: $object);
		} catch (Throwable $failure) {
			// Not knowing the schema means not knowing it is a survey at all;
			// every other write in the instance would be refused by failing closed here.
			return;
		}

		try {
			$refusal = match ($slug) {
				SurveyRecords::SURVEY => $this->surveyWrite(object: $object, stored: $stored),
				SurveyRecords::ANSWER_SET => $this->answerSetWrite(object: $object, stored: $stored),
				default => null,
			};
		} catch (Throwable $failure) {
			$this->logger->warning(
				message: '[SurveyWriteListener] The survey could not be read, refusing the write: '.$failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
			$refusal = 'The survey this belongs to could not be read, so its rules could not be checked.';
		}

		if ($refusal !== null) {
			$event->setErrors(['code' => self::ERROR_CODE, 'message' => $refusal]);
			$event->stopPropagation();
		}
	}//end apply()

	/**
	 * A survey being written: anonymity is fixed, an edit with answers raises the version.
	 *
	 * @param ObjectEntity      $object The survey as it will be stored.
	 * @param ObjectEntity|null $stored The survey as stored, on update.
	 *
	 * @return string|null The refusal, or null when the write may go ahead.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	private function surveyWrite(ObjectEntity $object, ?ObjectEntity $stored): ?string {
		if ($stored === null) {
			return null;
		}

		$before = (array)$stored->getObject();
		$after = (array)$object->getObject();

		$refusal = $this->rules->refuseAnonymityChange(
			survey: $before,
			proposed: (string)($after['anonymity'] ?? ($before['anonymity'] ?? SurveyRules::ATTRIBUTED))
		);
		if ($refusal !== null) {
			return $refusal;
		}

		if (self::withoutVersion(data: $after) === self::withoutVersion(data: $before)) {
			return null;
		}

		$hasAnswers = $this->records->hasAnswers(within: $stored, surveyUuid: (string)$stored->getUuid());
		$after['version'] = $this->rules->versionAfterEdit(survey: $before, answerSetCount: (int)$hasAnswers);
		$object->setObject($after);

		return null;
	}//end surveyWrite()

	/**
	 * An answer set being written: stamp or keep the version, require the
	 * required answers, and leave off the respondent where promised.
	 *
	 * @param ObjectEntity      $object The answer set as it will be stored.
	 * @param ObjectEntity|null $stored The answer set as stored, on update.
	 *
	 * @return string|null The refusal, or null when the write may go ahead.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
	 */
	private function answerSetWrite(ObjectEntity $object, ?ObjectEntity $stored): ?string {
		$answerSet = (array)$object->getObject();
		$surveyUuid = (string)($answerSet['survey'] ?? '');
		$survey = $this->records->survey(within: $object, surveyUuid: $surveyUuid);
		if ($survey === null) {
			return 'This answer set names no survey that exists.';
		}

		if ($stored === null) {
			$questions = $this->records->questions(within: $object, surveyUuid: $surveyUuid);
			$missing = $this->rules->refuseSubmission(
				questions: $questions,
				answers: self::answersBySlug(answers: (array)($answerSet['answers'] ?? []), questions: $questions)
			);
			if ($missing !== []) {
				return implode(' ', $missing);
			}

			$answerSet['surveyVersion'] = (int)($survey['version'] ?? 1);
		} else {
			$answerSet['surveyVersion'] = ((array)$stored->getObject())['surveyVersion'] ?? (int)($survey['version'] ?? 1);
		}

		$object->setObject($this->rules->scrubRespondent(answerSet: $answerSet, survey: $survey));

		return null;
	}//end answerSetWrite()

	/**
	 * The answers with every question named by uuid renamed to that question's slug.
	 *
	 * A respondent's client may name a question by either; the rules match on the slug.
	 *
	 * @param array<int, mixed>                $answers   The submitted answers.
	 * @param array<int, array<string, mixed>> $questions The survey's questions.
	 *
	 * @return array<int, array<string, mixed>> The answers.
	 */
	private static function answersBySlug(array $answers, array $questions): array {
		$slugById = [];
		foreach ($questions as $question) {
			$slugById[(string)($question['id'] ?? '')] = (string)($question['slug'] ?? '');
		}

		$named = [];
		foreach ($answers as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$question = (string)($answer['question'] ?? '');
			$answer['question'] = ($slugById[$question] ?? $question);
			$named[] = $answer;
		}

		return $named;
	}//end answersBySlug()

	/**
	 * The survey's data without its version, to tell an edit from a no-op write.
	 *
	 * @param array<string, mixed> $data The survey's data.
	 *
	 * @return array<string, mixed> The data without `version`.
	 */
	private static function withoutVersion(array $data): array {
		unset($data['version']);
		ksort($data);

		return $data;
	}//end withoutVersion()
}//end class
