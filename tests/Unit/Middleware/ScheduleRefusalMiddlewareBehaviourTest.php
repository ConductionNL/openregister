<?php

/**
 * ScheduleRefusalMiddlewareBehaviourTest — header content is exactly the JSON of the report.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusalReportInterface;
use OCA\OpenRegister\Middleware\ScheduleRefusalMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\ScheduleRefusalMiddleware
 */
class ScheduleRefusalMiddlewareBehaviourTest extends TestCase {

	private function respond(array $report): Response {
		$refusals = $this->createMock(ScheduleRefusalReportInterface::class);
		$refusals->expects($this->once())->method('all')->willReturn($report);
		$response = new Response();

		$out = (new ScheduleRefusalMiddleware($refusals))->afterController($this->createMock(Controller::class), 'publish', $response);
		$this->assertSame($response, $out);

		return $out;
	}

	public function testReportIsEncodedIntoTheHeader(): void {
		$headers = $this->respond([['uuid' => 'a', 'reason' => 'past date']])->getHeaders();

		$this->assertSame('[{"uuid":"a","reason":"past date"}]', $headers[ScheduleRefusalMiddleware::HEADER]);
	}

	public function testEmptyReportLeavesTheHeaderOff(): void {
		$headers = $this->respond([])->getHeaders();

		$this->assertArrayNotHasKey(ScheduleRefusalMiddleware::HEADER, $headers);
	}
}
