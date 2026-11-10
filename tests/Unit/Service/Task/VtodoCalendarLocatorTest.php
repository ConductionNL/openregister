<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Calendar selection by uid: the first VTODO-capable calendar of the NAMED
 * user, and a named exception when there is none.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Service\Task\VtodoCalendarLocator;
use PHPUnit\Framework\TestCase;

class VtodoCalendarLocatorTest extends TestCase {
	private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	public function testItPicksTheFirstVtodoCalendarOfTheNamedUser(): void {
		$backend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
		$backend->expects($this->once())->method('getCalendarsForUser')
			->with('principals/users/approver')
			->willReturn([
				['id' => 7, 'uri' => 'birthdays', self::COMPONENTS => 'VEVENT'],
				['id' => 9, 'uri' => 'personal', self::COMPONENTS => 'VEVENT,VTODO'],
				['id' => 11, 'uri' => 'tasks', self::COMPONENTS => 'VTODO'],
			]);

		$calendar = (new VtodoCalendarLocator($backend))->forUser('approver');

		$this->assertSame(['id' => 9, 'uri' => 'personal'], $calendar);
	}

	public function testNoVtodoCalendarIsANamedException(): void {
		$backend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
		$backend->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'birthdays', self::COMPONENTS => 'VEVENT'],
		]);

		$this->expectException(NoVtodoCalendarException::class);
		$this->expectExceptionMessage('No VTODO-supporting calendar found for user nocal');

		(new VtodoCalendarLocator($backend))->forUser('nocal');
	}

	public function testItReadsEveryComponentSetShape(): void {
		$locator = new VtodoCalendarLocator($this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class));

		$this->assertTrue($locator->supportsVtodo('VEVENT,VTODO'));
		$this->assertTrue($locator->supportsVtodo(['VEVENT', 'vtodo']));
		$this->assertFalse($locator->supportsVtodo(['VEVENT']));
		$this->assertFalse($locator->supportsVtodo(null));

		$object = new class {
			public function getValue(): array {
				return ['VTODO'];
			}
		};
		$this->assertTrue($locator->supportsVtodo($object));
	}
}
