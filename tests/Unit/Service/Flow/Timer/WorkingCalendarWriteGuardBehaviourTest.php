<?php

/**
 * The guard on writing and deleting working-calendar objects: which objects
 * are calendars, a calendar validated on save (falling back to the object's
 * slug), and the open timers that block a calendar's deletion.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * WorkingCalendarWriteGuard.
 */
class WorkingCalendarWriteGuardBehaviourTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private FlowTimerMapperInterface&MockObject $timers;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->timers = $this->createMock(FlowTimerMapperInterface::class);
	}//end setUp()

	private function guard(): WorkingCalendarWriteGuard {
		return new WorkingCalendarWriteGuard($this->schemas, $this->timers);
	}//end guard()

	private static function object(?string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		return $object;
	}//end object()

	private static function schema(string $slug): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	public function testAnObjectOfTheCalendarSchemaIsACalendarLookedUpWithoutRbac(): void {
		$this->schemas->expects($this->once())->method('find')->with('12', [], false, false)->willReturn(self::schema('working-calendar'));

		$this->assertTrue($this->guard()->isWorkingCalendar(self::object('12')));
	}//end testAnObjectOfTheCalendarSchemaIsACalendarLookedUpWithoutRbac()

	public function testOtherSchemasMissingSchemasAndUnresolvableSchemasAreNotCalendars(): void {
		$this->schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if ($id === 'gone') {
					throw new DoesNotExistException('gone');
				}
				return self::schema('zaak');
			}
		);
		$guard = $this->guard();

		$this->assertFalse($guard->isWorkingCalendar(self::object('7')));
		$this->assertFalse($guard->isWorkingCalendar(self::object('gone')));
		$this->assertFalse($guard->isWorkingCalendar(self::object('')));
		$this->assertFalse($guard->isWorkingCalendar(self::object(null)));
	}//end testOtherSchemasMissingSchemasAndUnresolvableSchemasAreNotCalendars()

	public function testAValidDefinitionPassesAndABlankSlugTakesTheFallback(): void {
		$definition = ['slug' => ' ', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8];

		$this->guard()->assertValid($definition, 'from-object');

		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('A working calendar requires a non-empty slug.');
		$this->guard()->assertValid($definition);
	}//end testAValidDefinitionPassesAndABlankSlugTakesTheFallback()

	public function testAnInvalidDefinitionIsRefusedEvenWithAFallbackSlug(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Working calendar 'from-object' must declare its workingWeekdays");

		$this->guard()->assertValid(['hoursPerWorkingDay' => 8], 'from-object');
	}//end testAnInvalidDefinitionIsRefusedEvenWithAFallbackSlug()

	public function testACalendarWithoutOpenTimersHasNoBlockersAndNoSampleIsRead(): void {
		$this->timers->method('countOpenByCalendarSlug')->with('office')->willReturn(0);
		$this->timers->expects($this->never())->method('findOpenByCalendarSlug');

		$this->assertSame(['count' => 0, 'timers' => []], $this->guard()->deletionBlockers('office'));
	}//end testACalendarWithoutOpenTimersHasNoBlockersAndNoSampleIsRead()

	public function testOpenTimersBlockDeletionWithACountAndASampleOfTen(): void {
		$sample = [];
		foreach (['t-1', 't-2'] as $uuid) {
			$timer = new FlowTimer();
			$timer->setUuid($uuid);
			$sample[] = $timer;
		}
		$this->timers->method('countOpenByCalendarSlug')->willReturn(14);
		$this->timers->expects($this->once())->method('findOpenByCalendarSlug')->with('office', 10)->willReturn($sample);
		$guard = $this->guard();

		$blockers = $guard->deletionBlockers('office');

		$this->assertSame(['count' => 14, 'timers' => ['t-1', 't-2']], $blockers);
		$this->assertSame(
			"Working calendar 'office' is measured against by 14 open timer(s) and cannot be deleted: t-1, t-2.",
			$guard->blockedMessage('office', $blockers)
		);
	}//end testOpenTimersBlockDeletionWithACountAndASampleOfTen()
}//end class
