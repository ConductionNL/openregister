<?php

/**
 * The survey rules at write time: what is refused, and what is rewritten.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\SurveyWriteListener;
use OCA\OpenRegister\Service\Survey\SurveyRecords;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SurveyWriteListener against the real SurveyRules, with the records read stubbed.
 */
#[CoversClass(SurveyWriteListener::class)]
#[UsesClass(SurveyRules::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectCreatingEvent::class)]
#[UsesClass(ObjectUpdatingEvent::class)]
class SurveyWriteListenerTest extends TestCase {

	/**
	 * The survey records read.
	 *
	 * @var SurveyRecords&MockObject
	 */
	private SurveyRecords&MockObject $records;

	/**
	 * The listener under test.
	 *
	 * @var SurveyWriteListener
	 */
	private SurveyWriteListener $listener;

	/**
	 * Wire the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->records = $this->createMock(SurveyRecords::class);
		$this->listener = new SurveyWriteListener(
			records: $this->records,
			rules: new SurveyRules(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * An object with the given data and uuid.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(array $data, string $uuid = 's-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setObject($data);

		return $object;
	}//end object()

	/**
	 * The survey's three questions; the scale one is required.
	 *
	 * @return array<int, array<string, mixed>> The questions.
	 */
	private static function questions(): array {
		return [
			['id' => 'q-uuid-1', 'slug' => 'score', 'text' => 'How would you rate the handling?', 'kind' => 'scale', 'required' => true],
			['id' => 'q-uuid-2', 'slug' => 'channel', 'text' => 'How did you contact us?', 'kind' => 'choice'],
			['id' => 'q-uuid-3', 'slug' => 'remarks', 'text' => 'Anything else?', 'kind' => 'text'],
		];
	}//end questions()

	/**
	 * Editing a survey's question text with answers present raises version 1 to 2.
	 *
	 * @return void
	 */
	public function testEditingASurveyWithAnswersRaisesItsVersion(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::SURVEY);
		$this->records->expects($this->once())->method('hasAnswers')->with($this->anything(), 's-1')->willReturn(true);

		$stored = self::object(['title' => 'KTO', 'introduction' => 'Old', 'anonymity' => 'attributed', 'version' => 1]);
		$new = self::object(['title' => 'KTO', 'introduction' => 'New', 'anonymity' => 'attributed', 'version' => 1]);
		$event = new ObjectUpdatingEvent(newObject: $new, oldObject: $stored);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(2, $new->getObject()['version']);
		$this->assertSame('New', $new->getObject()['introduction']);
	}//end testEditingASurveyWithAnswersRaisesItsVersion()

	/**
	 * Editing a survey with no answers keeps its version.
	 *
	 * @return void
	 */
	public function testEditingASurveyWithoutAnswersKeepsItsVersion(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::SURVEY);
		$this->records->method('hasAnswers')->willReturn(false);

		$stored = self::object(['title' => 'KTO', 'anonymity' => 'attributed', 'version' => 3]);
		$new = self::object(['title' => 'KTO 2026', 'anonymity' => 'attributed', 'version' => 3]);
		$event = new ObjectUpdatingEvent(newObject: $new, oldObject: $stored);

		$this->listener->handle($event);

		$this->assertSame(3, $new->getObject()['version']);
		$this->assertFalse($event->isPropagationStopped());
	}//end testEditingASurveyWithoutAnswersKeepsItsVersion()

	/**
	 * A write that changes nothing but the version is not an edit, and is not counted.
	 *
	 * @return void
	 */
	public function testAWriteThatChangesNothingDoesNotReadAnswers(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::SURVEY);
		$this->records->expects($this->never())->method('hasAnswers');

		$stored = self::object(['title' => 'KTO', 'anonymity' => 'attributed', 'version' => 2]);
		$new = self::object(['anonymity' => 'attributed', 'title' => 'KTO', 'version' => 2]);

		$this->listener->handle(new ObjectUpdatingEvent(newObject: $new, oldObject: $stored));

		$this->assertSame(2, $new->getObject()['version']);
	}//end testAWriteThatChangesNothingDoesNotReadAnswers()

	/**
	 * An attributed survey cannot be made anonymous; the write is refused with the reason.
	 *
	 * @return void
	 */
	public function testAnonymityCannotBeSwitchedOnAfterwards(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::SURVEY);
		$this->records->expects($this->never())->method('hasAnswers');

		$stored = self::object(['title' => 'KTO', 'anonymity' => 'attributed', 'version' => 1]);
		$new = self::object(['title' => 'KTO', 'anonymity' => 'anonymous', 'version' => 1]);
		$event = new ObjectUpdatingEvent(newObject: $new, oldObject: $stored);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(SurveyWriteListener::ERROR_CODE, $event->getErrors()['code']);
		$this->assertStringContainsString('cannot be made anonymous', $event->getErrors()['message']);
	}//end testAnonymityCannotBeSwitchedOnAfterwards()

	/**
	 * A new answer set is stamped with the survey's current version, whatever the client sent.
	 *
	 * @return void
	 */
	public function testANewAnswerSetNamesTheCurrentSurveyVersion(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willReturn(['id' => 's-1', 'anonymity' => 'attributed', 'version' => 4]);
		$this->records->method('questions')->willReturn(self::questions());

		$answerSet = self::object(
			['survey' => 's-1', 'surveyVersion' => 1, 'subjectObject' => 'case-9', 'respondent' => 'a@example.org', 'answers' => [['question' => 'score', 'value' => '4']]],
			'as-1'
		);
		$event = new ObjectCreatingEvent($answerSet);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(4, $answerSet->getObject()['surveyVersion']);
		$this->assertSame('case-9', $answerSet->getObject()['subjectObject']);
		$this->assertSame('a@example.org', $answerSet->getObject()['respondent']);
	}//end testANewAnswerSetNamesTheCurrentSurveyVersion()

	/**
	 * An updated answer set keeps the version it answered, even after the survey moved on.
	 *
	 * @return void
	 */
	public function testAnUpdatedAnswerSetKeepsItsVersion(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willReturn(['id' => 's-1', 'anonymity' => 'attributed', 'version' => 2]);
		$this->records->expects($this->never())->method('questions');

		$stored = self::object(['survey' => 's-1', 'surveyVersion' => 1, 'answers' => []], 'as-1');
		$new = self::object(['survey' => 's-1', 'surveyVersion' => 2, 'answers' => []], 'as-1');

		$this->listener->handle(new ObjectUpdatingEvent(newObject: $new, oldObject: $stored));

		$this->assertSame(1, $new->getObject()['surveyVersion']);
	}//end testAnUpdatedAnswerSetKeepsItsVersion()

	/**
	 * A submission without the required question is refused and names it.
	 *
	 * @return void
	 */
	public function testAMissingRequiredAnswerIsRefusedByName(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willReturn(['id' => 's-1', 'anonymity' => 'attributed', 'version' => 1]);
		$this->records->method('questions')->willReturn(self::questions());

		$answerSet = self::object(['survey' => 's-1', 'answers' => [['question' => 'remarks', 'value' => 'Fine']]], 'as-1');
		$event = new ObjectCreatingEvent($answerSet);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('This question still needs an answer: "How would you rate the handling?".', $event->getErrors()['message']);
	}//end testAMissingRequiredAnswerIsRefusedByName()

	/**
	 * A required question answered by its uuid instead of its slug counts as answered.
	 *
	 * @return void
	 */
	public function testAQuestionAnsweredByUuidCounts(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willReturn(['id' => 's-1', 'anonymity' => 'attributed', 'version' => 1]);
		$this->records->method('questions')->willReturn(self::questions());

		$answerSet = self::object(['survey' => 's-1', 'answers' => [['question' => 'q-uuid-1', 'value' => '5']]], 'as-1');
		$event = new ObjectCreatingEvent($answerSet);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(1, $answerSet->getObject()['surveyVersion']);
	}//end testAQuestionAnsweredByUuidCounts()

	/**
	 * An anonymous survey's answer set is stored with no respondent key at all.
	 *
	 * @return void
	 */
	public function testAnAnonymousAnswerSetHoldsNoRespondent(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willReturn(['id' => 's-1', 'anonymity' => 'anonymous', 'version' => 1]);
		$this->records->method('questions')->willReturn([]);

		$answerSet = self::object(['survey' => 's-1', 'respondent' => 'a@example.org', 'answers' => []], 'as-1');
		$this->listener->handle(new ObjectCreatingEvent($answerSet));

		$this->assertArrayNotHasKey('respondent', $answerSet->getObject());
	}//end testAnAnonymousAnswerSetHoldsNoRespondent()

	/**
	 * An answer set naming no existing survey is refused.
	 *
	 * @return void
	 */
	public function testAnAnswerSetWithoutASurveyIsRefused(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willReturn(null);

		$event = new ObjectCreatingEvent(self::object(['survey' => 'gone', 'answers' => []], 'as-1'));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('This answer set names no survey that exists.', $event->getErrors()['message']);
	}//end testAnAnswerSetWithoutASurveyIsRefused()

	/**
	 * When the survey cannot be read, the answer set is refused rather than stored unchecked.
	 *
	 * @return void
	 */
	public function testAnUnreadableSurveyFailsClosed(): void {
		$this->records->method('schemaSlugOf')->willReturn(SurveyRecords::ANSWER_SET);
		$this->records->method('survey')->willThrowException(new RuntimeException('db down'));

		$answerSet = self::object(['survey' => 's-1', 'respondent' => 'a@example.org', 'answers' => []], 'as-1');
		$event = new ObjectCreatingEvent($answerSet);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('could not be read', $event->getErrors()['message']);
	}//end testAnUnreadableSurveyFailsClosed()

	/**
	 * Objects of other schemas pass untouched, and an unknown schema is not refused.
	 *
	 * @return void
	 */
	public function testOtherSchemasPassUntouched(): void {
		$this->records->expects($this->exactly(2))->method('schemaSlugOf')->willReturnOnConsecutiveCalls(
			'case',
			$this->throwException(new RuntimeException('no schema'))
		);
		$this->records->expects($this->never())->method('survey');

		$first = new ObjectCreatingEvent(self::object(['anonymity' => 'anonymous', 'respondent' => 'x']));
		$second = new ObjectCreatingEvent(self::object(['respondent' => 'y']));
		$this->listener->handle($first);
		$this->listener->handle($second);

		$this->assertFalse($first->isPropagationStopped());
		$this->assertFalse($second->isPropagationStopped());
		$this->assertSame('x', $first->getObject()->getObject()['respondent']);
	}//end testOtherSchemasPassUntouched()
}//end class
