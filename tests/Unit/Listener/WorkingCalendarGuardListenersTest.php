<?php

/**
 * The two working-calendar guards, from the outside.
 *
 * Both are tested through a MUTATION that must redden, not only through the
 * happy path: a listener that never stops propagation looks exactly like one
 * that correctly allowed the save, so every refusing case asserts the errors
 * payload as well as the stop, and every allowing case asserts that the stop
 * did NOT happen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener;
use OCA\OpenRegister\Listener\WorkingCalendarValidationListener;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarYearBoundaryTest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\WorkingCalendarValidationListener
 * @covers \OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard
 */
class WorkingCalendarGuardListenersTest extends TestCase {

	/**
	 * Resolves the schema an object belongs to.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemas;

	/**
	 * Counts the timers that name a calendar.
	 *
	 * @var FlowTimerMapper&MockObject
	 */
	private FlowTimerMapper $timers;

	/**
	 * The guard both listeners delegate to.
	 *
	 * @var WorkingCalendarWriteGuard
	 */
	private WorkingCalendarWriteGuard $guard;

	/**
	 * Wire the guard over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$this->timers = $this->createMock(originalClassName: FlowTimerMapper::class);
		$this->guard = new WorkingCalendarWriteGuard(schemas: $this->schemas, timers: $this->timers);
	}//end setUp()

	/**
	 * An object entity carrying a schema reference and a payload.
	 *
	 * @param array<string, mixed> $data The object data.
	 * @param string $schemaSlug The slug the schema mapper will answer with.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function objectOf(array $data, string $schemaSlug = 'working-calendar'): ObjectEntity {
		$schema = new Schema();
		$schema->setSlug($schemaSlug);
		$this->schemas->method('find')->willReturn($schema);

		$object = new ObjectEntity();
		$object->setSchema('7');
		$object->setUuid('00000000-0000-0000-0000-0000000000aa');
		$object->setObject($data);

		return $object;
	}//end objectOf()

	/**
	 * The validation listener under test.
	 *
	 * @return WorkingCalendarValidationListener The listener.
	 */
	private function validationListener(): WorkingCalendarValidationListener {
		return new WorkingCalendarValidationListener(
			guard: $this->guard,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end validationListener()

	/**
	 * A calendar the engine can resolve is stored, and the guard stays silent.
	 *
	 * @return void
	 */
	public function testAValidCalendarIsSaved(): void {
		$event = new ObjectCreatingEvent(object: $this->objectOf(data: WorkingCalendarYearBoundaryTest::dutchMunicipal()));
		$this->validationListener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped());
		self::assertSame(expected: [], actual: $event->getErrors());
	}//end testAValidCalendarIsSaved()

	/**
	 * A calendar of nothing but dates is refused at write time, not at arm time.
	 *
	 * @return void
	 */
	public function testAnEnumeratedOnlyCalendarIsRefusedOnCreate(): void {
		$definition = WorkingCalendarYearBoundaryTest::dutchMunicipal();
		$definition['rules'] = [];
		$definition['exceptions'] = [
			['date' => '2027-01-01', 'name' => 'Nieuwjaarsdag'],
			['date' => '2027-12-25', 'name' => 'Eerste Kerstdag'],
		];

		$event = new ObjectCreatingEvent(object: $this->objectOf(data: $definition));
		$this->validationListener()->handle($event);

		self::assertTrue(condition: $event->isPropagationStopped());
		self::assertSame(expected: WorkingCalendarValidationListener::ERROR_CODE, actual: $event->getErrors()['code']);
		self::assertStringContainsString(needle: 'declare computed rules', haystack: (string)$event->getErrors()['message']);
	}//end testAnEnumeratedOnlyCalendarIsRefusedOnCreate()

	/**
	 * Without hoursPerWorkingDay a term in hours and one in days cannot be compared.
	 *
	 * @return void
	 */
	public function testAMissingHoursPerWorkingDayIsRefusedOnUpdate(): void {
		$definition = WorkingCalendarYearBoundaryTest::dutchMunicipal();
		unset($definition['hoursPerWorkingDay']);

		$event = new ObjectUpdatingEvent(
			newObject: $this->objectOf(data: $definition),
			oldObject: null
		);
		$this->validationListener()->handle($event);

		self::assertTrue(condition: $event->isPropagationStopped());
		self::assertStringContainsString(needle: 'hoursPerWorkingDay', haystack: (string)$event->getErrors()['message']);
	}//end testAMissingHoursPerWorkingDayIsRefusedOnUpdate()

	/**
	 * The guard recognises a calendar by its SCHEMA, never by its payload.
	 *
	 * @return void
	 */
	public function testAnObjectOfAnotherSchemaIsLeftAlone(): void {
		// The payload is a calendar that WOULD be refused. It is not a
		// calendar, so nothing may happen to it: this is the case a guard
		// that sniffs the payload instead of the schema gets wrong.
		$definition = ['rules' => [], 'exceptions' => [['date' => '2027-01-01', 'name' => 'x']]];

		$event = new ObjectCreatingEvent(object: $this->objectOf(data: $definition, schemaSlug: 'zaak'));
		$this->validationListener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped());
	}//end testAnObjectOfAnotherSchemaIsLeftAlone()

	/**
	 * A partial update is not refused for a field it never touched.
	 *
	 * @return void
	 */
	public function testAnUpdateThatOmitsTheSlugKeepsTheStoredOne(): void {
		$definition = WorkingCalendarYearBoundaryTest::dutchMunicipal();
		unset($definition['slug']);

		$old = new ObjectEntity();
		$old->setObject(['slug' => 'gemeente-boundary']);

		$event = new ObjectUpdatingEvent(
			newObject: $this->objectOf(data: $definition),
			oldObject: $old
		);
		$this->validationListener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped(), message: 'a PATCH that never touched the slug must not be refused for it');
	}//end testAnUpdateThatOmitsTheSlugKeepsTheStoredOne()

	/**
	 * The delete guard under test.
	 *
	 * @return WorkingCalendarDeleteGuardListener The listener.
	 */
	private function deleteListener(): WorkingCalendarDeleteGuardListener {
		return new WorkingCalendarDeleteGuardListener(
			guard: $this->guard,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end deleteListener()

	/**
	 * Deleting a calendar an armed timer names is refused with 409 and the uuids.
	 *
	 * @return void
	 */
	public function testACalendarWithAnArmedTimerSurvivesTheDelete(): void {
		$timer = new FlowTimer();
		$timer->setUuid('11111111-1111-1111-1111-111111111111');

		$this->timers->method('countOpenByCalendarSlug')->willReturn(1);
		$this->timers->method('findOpenByCalendarSlug')->willReturn([$timer]);

		$event = new ObjectDeletingEvent(object: $this->objectOf(data: ['slug' => 'gemeente-x']));
		$this->deleteListener()->handle($event);

		self::assertTrue(condition: $event->isPropagationStopped());
		$errors = $event->getErrors();
		self::assertSame(expected: WorkingCalendarDeleteGuardListener::ERROR_CODE, actual: $errors['code']);
		self::assertSame(expected: 409, actual: $errors['status'], message: 'a referenced row is a conflict, not a malformed body');
		self::assertSame(expected: 1, actual: $errors['openTimers']);
		self::assertSame(expected: ['11111111-1111-1111-1111-111111111111'], actual: $errors['timers']);
		self::assertStringContainsString(needle: 'gemeente-x', haystack: (string)$errors['message']);
	}//end testACalendarWithAnArmedTimerSurvivesTheDelete()

	/**
	 * A calendar nothing open refers to is deletable, and costs no second query.
	 *
	 * @return void
	 */
	public function testACalendarNoOpenTimerNamesIsDeleted(): void {
		$this->timers->method('countOpenByCalendarSlug')->willReturn(0);
		$this->timers->expects(self::never())->method('findOpenByCalendarSlug');

		$event = new ObjectDeletingEvent(object: $this->objectOf(data: ['slug' => 'gemeente-x']));
		$this->deleteListener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped());
	}//end testACalendarNoOpenTimerNamesIsDeleted()

	/**
	 * The count comes from the database, so a capped uuid sample cannot understate it.
	 *
	 * @return void
	 */
	public function testTheCountIsReadFromTheDatabaseNotFromTheCappedSample(): void {
		// Eleven timers, ten uuids: a count measured on the sample would say
		// ten and understate what the administrator is about to break.
		$timers = [];
		for ($index = 0; $index < WorkingCalendarWriteGuard::BLOCKER_SAMPLE; $index++) {
			$timer = new FlowTimer();
			$timer->setUuid(sprintf('00000000-0000-0000-0000-00000000%04d', $index));
			$timers[] = $timer;
		}

		$this->timers->method('countOpenByCalendarSlug')->willReturn(11);
		$this->timers->method('findOpenByCalendarSlug')->willReturn($timers);

		$event = new ObjectDeletingEvent(object: $this->objectOf(data: ['slug' => 'gemeente-x']));
		$this->deleteListener()->handle($event);

		self::assertSame(expected: 11, actual: $event->getErrors()['openTimers']);
		self::assertCount(expectedCount: WorkingCalendarWriteGuard::BLOCKER_SAMPLE, haystack: $event->getErrors()['timers']);
	}//end testTheCountIsReadFromTheDatabaseNotFromTheCappedSample()

	/**
	 * Deleting anything that is not a calendar never touches the timer table.
	 *
	 * @return void
	 */
	public function testDeletingAnObjectOfAnotherSchemaIsNotGuarded(): void {
		$this->timers->expects(self::never())->method('countOpenByCalendarSlug');

		$event = new ObjectDeletingEvent(object: $this->objectOf(data: ['slug' => 'gemeente-x'], schemaSlug: 'zaak'));
		$this->deleteListener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped());
	}//end testDeletingAnObjectOfAnotherSchemaIsNotGuarded()
}//end class
