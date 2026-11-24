<?php

/**
 * RouteCallers: the recorded callers of API routes, answered from the recorder and its records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

use DateTime;
use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RouteCallersInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RouteCallWindow;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCA\OpenRegister\Service\ApiCaller\RouteCallers;
use PHPUnit\Framework\TestCase;

/**
 * The window's prefixes and moment reach the mapper unchanged, and the recorder says whether anything is recorded.
 *
 * @covers \OCA\OpenRegister\Service\ApiCaller\RouteCallers
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Core\RouteCallWindow
 */
final class RouteCallersTest extends TestCase {

	/**
	 * Whether it records is the recorder's answer.
	 *
	 * @return void
	 */
	public function testRecordingFollowsTheRecorder(): void {
		$recorder = $this->createMock(ApiCallRecorder::class);
		$recorder->method('isEnabled')->willReturnOnConsecutiveCalls(true, false);
		$callers = new RouteCallers(recorder: $recorder, records: $this->createMock(ApiCallRecordMapperInterface::class));

		$this->assertInstanceOf(RouteCallersInterface::class, $callers);
		$this->assertTrue($callers->isRecording());
		$this->assertFalse($callers->isRecording());
	}//end testRecordingFollowsTheRecorder()

	/**
	 * The count and the list read the window's routes and moment, the list with its limit.
	 *
	 * @return void
	 */
	public function testTheWindowReachesTheRecords(): void {
		$from    = new DateTime('2026-08-01T00:00:00+00:00');
		$window  = new RouteCallWindow(prefixes: ['/apps/openregister/api/objects/3/melding'], from: $from);
		$top     = [['principal' => 'leverancier-a', 'calls' => 40, 'lastSeen' => null]];
		$records = $this->createMock(ApiCallRecordMapperInterface::class);
		$records->expects($this->once())->method('countCallersOfRoutes')->with($window->prefixes, $from)->willReturn(2);
		$records->expects($this->once())->method('findCallersOfRoutes')->with($window->prefixes, $from, 10)->willReturn($top);

		$callers = new RouteCallers(recorder: $this->createMock(ApiCallRecorder::class), records: $records);

		$this->assertSame(2, $callers->countCallersOf(window: $window));
		$this->assertSame($top, $callers->callersOf(window: $window, limit: 10));
	}//end testTheWindowReachesTheRecords()
}//end class
