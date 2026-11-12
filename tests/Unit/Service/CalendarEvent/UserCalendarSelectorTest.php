<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use Exception;
use OCA\OpenRegister\Service\CalendarEvent\UserCalendarSelector;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\CalendarEvent\UserCalendarSelector
 */
class UserCalendarSelectorTest extends TestCase {

	private const SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	/**
	 * Build a selector whose config holds the given pin.
	 *
	 * @param string $pin The pinned URI.
	 *
	 * @return array{UserCalendarSelector, IConfig}
	 */
	private function selector(string $pin): array {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($pin);
		return [new UserCalendarSelector($config, $this->createMock(LoggerInterface::class)), $config];
	}

	public function testHonoursAPinThatStillSupportsVevent(): void {
		[$selector, $config] = $this->selector('work');
		$config->expects($this->never())->method('setUserValue');
		$calendars = [
			['id' => 1, 'uri' => 'personal', self::SET => 'VEVENT'],
			['id' => 2, 'uri' => 'work', self::SET => ['VEVENT']],
		];

		$this->assertSame(['id' => 2, 'uri' => 'work'], $selector->select('alice', $calendars));
	}

	public function testPrefersPersonalAndPinsItWhenThereIsNoPin(): void {
		[$selector, $config] = $this->selector('');
		$config->expects($this->once())->method('setUserValue')->with('alice', 'openregister', 'events_calendar_uri', 'personal');
		$calendars = [
			['id' => 1, 'uri' => 'contact_birthdays', self::SET => 'VEVENT'],
			['id' => 3, 'uri' => 'personal', self::SET => 'VEVENT,VTODO'],
		];

		$this->assertSame(['id' => 3, 'uri' => 'personal'], $selector->select('alice', $calendars));
	}

	public function testRefusesWhenNoCalendarSupportsVevent(): void {
		[$selector] = $this->selector('');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No VEVENT-supporting calendar found for user alice');
		$selector->select('alice', [['id' => 1, 'uri' => 'tasks', self::SET => 'VTODO']]);
	}

	public function testReadsEveryShapeOfTheComponentSet(): void {
		[$selector] = $this->selector('');
		$property = new class {
			/**
			 * @return array
			 */
			public function getValue(): array {
				return ['vevent'];
			}
		};

		$this->assertTrue($selector->calendarSupportsVevent([self::SET => $property]));
		$this->assertTrue($selector->calendarSupportsVevent([self::SET => new \ArrayIterator(['VEVENT'])]));
		$this->assertFalse($selector->calendarSupportsVevent([self::SET => 42]));
		$this->assertFalse($selector->calendarSupportsVevent([]));
	}
}
