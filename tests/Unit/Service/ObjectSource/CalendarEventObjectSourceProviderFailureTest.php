<?php

/**
 * What the calendar event object source answers when the calendar fails.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure tests for {@see CalendarEventObjectSourceProvider}.
 */
class CalendarEventObjectSourceProviderFailureTest extends TestCase {

	/**
	 * A failing calendar reads as no events, logged, for a lookup and a list.
	 *
	 * @return void
	 */
	public function testAFailingCalendarReadsAsNoEvents(): void {
		$calendar = $this->createMock(CalendarEventService::class);
		$calendar->expects($this->exactly(2))
			->method('getAllUserEvents')
			->willThrowException(new RuntimeException('caldav down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with('[ObjectSource:calendar-event-source] could not read VEVENTs: caldav down');
		$provider = new CalendarEventObjectSourceProvider(calendarEventService: $calendar, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $logger));

		$this->assertSame([], $provider->findAll(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), query: ['limit' => 5]));
		$this->assertNull($provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: 'e-1'));
	}//end testAFailingCalendarReadsAsNoEvents()
}//end class
