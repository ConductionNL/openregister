<?php

/**
 * Characterisation of the window CalendarEventsController::listCalendarEvents() asks for.
 *
 * Pins the `after` and `limit` the listing hands the link service: an explicit
 * date and limit as given, and the default window (one week back, 100 events)
 * when `after` is missing, empty or unparseable. The default is checked against
 * the clock read around the call, so no fixed date is pinned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\CalendarPickerWindow;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CalendarEventsControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarEventsControllerListEventsWindowTest extends TestCase {

	/** @var CalendarPickerWindow|null The window the link service was asked for. */
	private ?CalendarPickerWindow $window = null;

	/** @var string|null The calendar the link service was asked for. */
	private ?string $calendarUri = null;

	private function list(array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$links = $this->createMock(CalendarLinkService::class);
		$links->method('getEventsForCalendar')->willReturnCallback(
			function (string $calendarUri, CalendarPickerWindow $window): array {
				$this->calendarUri = $calendarUri;
				$this->window = $window;
				return [['uid' => 'a'], ['uid' => 'b']];
			}
		);

		$controller = CalendarEventsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(CalendarEventService::class),
			$links,
			$this->createMock(ObjectService::class),
			$this->createMock(PermissionHandler::class),
		);

		$response = $controller->listCalendarEvents('personal');
		$this->assertSame(200, $response->getStatus());
		return $response->getData();
	}//end list()

	public function testAnExplicitDateAndLimitAreUsedAsGiven(): void {
		$data = $this->list(['after' => '2026-03-01T10:00:00+00:00', 'limit' => '7']);

		$this->assertSame(['results' => [['uid' => 'a'], ['uid' => 'b']], 'total' => 2], $data);
		$this->assertSame('personal', $this->calendarUri);
		$this->assertSame('2026-03-01T10:00:00+00:00', $this->window->after->format(DATE_ATOM));
		$this->assertSame(7, $this->window->limit);
	}//end testAnExplicitDateAndLimitAreUsedAsGiven()

	public static function defaultedAfters(): array {
		return [
			'missing' => [[]],
			'empty' => [['after' => '']],
			'zero' => [['after' => '0']],
			'unparseable' => [['after' => 'not a date']],
		];
	}//end defaultedAfters()

	#[DataProvider('defaultedAfters')]
	public function testTheDefaultWindowIsOneWeekBackAndAHundredEvents(array $params): void {
		$earliest = (new DateTime())->modify('-1 week')->getTimestamp();
		$this->list($params);
		$latest = (new DateTime())->modify('-1 week')->getTimestamp();

		$after = $this->window->after->getTimestamp();
		$this->assertGreaterThanOrEqual($earliest, $after);
		$this->assertLessThanOrEqual($latest, $after);
		$this->assertSame(100, $this->window->limit);
	}//end testTheDefaultWindowIsOneWeekBackAndAHundredEvents()

	public function testANonNumericLimitIsZero(): void {
		$this->list(['after' => '2026-03-01', 'limit' => 'many']);

		$this->assertSame(0, $this->window->limit);
		$this->assertSame('2026-03-01', $this->window->after->format('Y-m-d'));
	}//end testANonNumericLimitIsZero()
}//end class
