<?php

/**
 * A journey in the forms register is refused at save when it is not well-formed; other objects pass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\JourneyValidationListener;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use OCA\OpenRegister\Service\Form\JourneyAuthoringValidator;
use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The listener over the real journey validator.
 *
 * @covers \OCA\OpenRegister\Listener\JourneyValidationListener
 * @uses \OCA\OpenRegister\Service\Form\JourneyAuthoringValidator
 * @uses \OCA\OpenRegister\Service\Form\JourneyDefinition
 * @uses \OCA\OpenRegister\Service\Form\LocalConditionEvaluator
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Event\WriteVetoState
 */
class JourneyValidationListenerTest extends TestCase {

	/**
	 * A listener whose written object resolves to these slugs (null: unreadable).
	 *
	 * @param string|null $schemaSlug   The schema slug.
	 * @param string|null $registerSlug The register slug.
	 *
	 * @return JourneyValidationListener The listener.
	 */
	private function listener(?string $schemaSlug, ?string $registerSlug='forms'): JourneyValidationListener {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		if ($schemaSlug === null) {
			$schemas->method('find')->willThrowException(new RuntimeException('database down'));
		} else {
			$schema = new Schema();
			$schema->setSlug($schemaSlug);
			$schemas->method('find')->willReturn($schema);
		}

		$registers = $this->createMock(RegisterMapperInterface::class);
		if ($registerSlug === null) {
			$registers->method('find')->willThrowException(new RuntimeException('database down'));
		} else {
			$register = new Register();
			$register->setSlug($registerSlug);
			$registers->method('find')->willReturn($register);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));
		$validator = new JourneyAuthoringValidator(
			schemas: $this->createMock(SchemaMapperInterface::class),
			destinations: $this->createMock(FormDestinationValidatorInterface::class),
			conditions: new LocalConditionEvaluator(),
			l10n: $l10n
		);

		return new JourneyValidationListener(schemas: $schemas, registers: $registers, validator: $validator);
	}//end listener()

	/**
	 * An object in register 3, schema 7.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function row(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('j-1');
		$object->setSchema('7');
		$object->setRegister('3');
		$object->setObject($data);

		return $object;
	}//end row()

	/**
	 * A journey with access and one confirmation step.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function journey(): array {
		return ['title' => 'Register', 'access' => 'anonymous', 'steps' => [['id' => 'done', 'type' => 'confirmation']]];
	}//end journey()

	/**
	 * A well-formed journey is saved.
	 *
	 * @return void
	 */
	public function testAWellFormedJourneyIsSaved(): void {
		$event = new ObjectCreatingEvent($this->row(data: $this->journey()));
		$this->listener(schemaSlug: 'journey')->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAWellFormedJourneyIsSaved()

	/**
	 * A journey losing its access on update is refused with the findings attached.
	 *
	 * @return void
	 */
	public function testAJourneyWithoutAccessIsRefusedOnUpdate(): void {
		$body = $this->journey();
		unset($body['access']);
		$event = new ObjectUpdatingEvent($this->row(data: $body), $this->row(data: $this->journey()));
		$this->listener(schemaSlug: 'journey')->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(JourneyValidationListener::ERROR_CODE, $event->getErrors()['code']);
		$this->assertSame(JourneyAuthoringValidator::ACCESS_MISSING, $event->getErrors()['findings'][0]['code']);
		$this->assertStringStartsWith('The journey cannot be saved: ', $event->getErrors()['message']);
	}//end testAJourneyWithoutAccessIsRefusedOnUpdate()

	/**
	 * Another schema, or a `journey` schema in another app's register, is left alone.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreLeftAlone(): void {
		foreach ([['case', 'forms'], ['journey', 'crm'], ['', 'forms']] as [$schema, $register]) {
			$event = new ObjectCreatingEvent($this->row(data: ['steps' => 'nonsense']));
			$this->listener(schemaSlug: $schema, registerSlug: $register)->handle($event);

			$this->assertFalse($event->isPropagationStopped(), $schema . '/' . $register);
		}
	}//end testOtherObjectsAreLeftAlone()

	/**
	 * A schema that cannot be read refuses the save instead of writing it unchecked.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaRefusesTheSave(): void {
		$event = new ObjectCreatingEvent($this->row(data: $this->journey()));
		$this->listener(schemaSlug: null)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('could not be checked', $event->getErrors()['message']);
	}//end testAnUnreadableSchemaRefusesTheSave()

	/**
	 * A journey schema in a register that cannot be read refuses the save too.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterRefusesTheSave(): void {
		$event = new ObjectCreatingEvent($this->row(data: $this->journey()));
		$this->listener(schemaSlug: 'journey', registerSlug: null)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAnUnreadableRegisterRefusesTheSave()
}//end class
