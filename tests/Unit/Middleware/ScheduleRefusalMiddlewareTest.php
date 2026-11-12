<?php

/**
 * The schedule refusals of a request reach its response.
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

use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Middleware\ScheduleRefusalMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A publish that succeeded with a refused schedule says so in a header.
 *
 * @covers \OCA\OpenRegister\Middleware\ScheduleRefusalMiddleware
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals
 * @uses   \OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ScheduleRefusalMiddlewareTest extends TestCase {
	/**
	 * The response after the middleware saw these refusals.
	 *
	 * @param array<int, string> $reasons The reasons.
	 *
	 * @return array<string, string> Its headers.
	 */
	private function headersAfter(array $reasons): array {
		$refusals = new ScheduleRefusals($this->createMock(LoggerInterface::class));
		foreach ($reasons as $reason) {
			$refusals->refuse(new ScheduleRelease(new ObjectEntity(), [], null), $reason);
		}

		$response = (new ScheduleRefusalMiddleware($refusals))->afterController(
			$this->createMock(Controller::class),
			'promote',
			new Response()
		);

		return $response->getHeaders();
	}

	public function testARefusalIsNamedInTheHeader(): void {
		$this->assertSame(
			'["one","two"]',
			$this->headersAfter(['one', 'two'])[ScheduleRefusalMiddleware::HEADER]
		);
	}

	public function testNoRefusalAddsNoHeader(): void {
		$this->assertArrayNotHasKey(ScheduleRefusalMiddleware::HEADER, $this->headersAfter([]));
	}
}
