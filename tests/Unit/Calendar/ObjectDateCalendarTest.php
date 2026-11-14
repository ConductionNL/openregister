<?php

/**
 * Unit tests for ObjectDateCalendar: read-only, and its search asks the shared generator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarSearchQuery;
use OCA\OpenRegister\Calendar\DeclaredDateCalendars;
use OCA\OpenRegister\Calendar\ObjectDateCalendar;
use OCA\OpenRegister\Calendar\ObjectDateCalendarScope;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCP\Calendar\Exceptions\CalendarException;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Constants;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectDateCalendarTest extends TestCase {

	/**
	 * @var DeclaredDateCalendars&MockObject
	 */
	private DeclaredDateCalendars $source;

	private ObjectDateCalendarScope $scope;

	private ObjectDateCalendar $calendar;

	protected function setUp(): void {
		parent::setUp();

		$this->source = $this->createMock(DeclaredDateCalendars::class);
		$this->scope  = new ObjectDateCalendarScope(
			key: 'openregister-schema-9',
			displayName: 'Bezwaren',
			color: '#123456',
			scopeType: CalendarFeedToken::SCOPE_SCHEMA,
			scopeId: '9',
			principalUri: 'principals/users/alice'
		);
		$this->calendar = new ObjectDateCalendar($this->scope, $this->source);
	}

	public function testItDescribesItselfFromItsScope(): void {
		$this->assertSame('openregister-schema-9', $this->calendar->getKey());
		$this->assertSame('openregister-schema-9', $this->calendar->getUri());
		$this->assertSame('Bezwaren', $this->calendar->getDisplayName());
		$this->assertSame('#123456', $this->calendar->getDisplayColor());
		$this->assertFalse($this->calendar->isDeleted());
	}

	public function testItIsReadOnly(): void {
		$this->assertSame(Constants::PERMISSION_READ, $this->calendar->getPermissions());
		$this->assertInstanceOf(ICalendarIsWritable::class, $this->calendar);
		$this->assertFalse($this->calendar->isWritable());
	}

	public function testAClientWriteIsRefusedNotAccepted(): void {
		$this->assertInstanceOf(ICreateFromString::class, $this->calendar);
		$this->source->expects($this->never())->method($this->anything());

		$this->expectException(CalendarException::class);
		$this->expectExceptionMessage(ObjectDateCalendar::READ_ONLY_MESSAGE);

		$this->calendar->createFromString('moved.ics', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
	}

	public function testAMinimalClientWriteIsRefusedToo(): void {
		$this->expectException(CalendarException::class);
		$this->expectExceptionMessage(ObjectDateCalendar::READ_ONLY_MESSAGE);

		$this->calendar->createFromStringMinimal('moved.ics', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
	}

	public function testSearchAsksTheSourceForThisScopeWithTheQuery(): void {
		$result = [['id' => 'uid-1', 'type' => 'VEVENT']];
		$this->source->expects($this->once())->method('searchResults')
			->with(
				$this->identicalTo($this->scope),
				$this->callback(
					static function (CalendarSearchQuery $query): bool {
						return $query->pattern === 'bezwaar'
							&& $query->options === ['timerange' => ['start' => '2026-10-01']]
							&& $query->limit === 10
							&& $query->offset === 5;
					}
				)
			)
			->willReturn($result);

		$this->assertSame(
			$result,
			$this->calendar->search('bezwaar', ['SUMMARY'], ['timerange' => ['start' => '2026-10-01']], 10, 5)
		);
	}
}
