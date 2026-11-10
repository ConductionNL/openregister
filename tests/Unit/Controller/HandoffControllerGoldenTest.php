<?php

/**
 * HandoffControllerGoldenTest
 *
 * Golden test pinning every typed outcome of the handoff endpoints — status,
 * body and log line — alongside HandoffControllerTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\HandoffController;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Handoff\HandoffSourceRef;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\HandoffController
 */
class HandoffControllerGoldenTest extends TestCase {

	private HandoffService&MockObject $service;

	private LoggerInterface&MockObject $logger;

	private HandoffController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(HandoffService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new HandoffController(
			$this->createMock(IRequest::class),
			new \OCA\OpenRegister\Controller\Support\HandoffControllerServices(
				$this->service,
				$this->logger,
			)
		);
	}

	public static function availabilityFailures(): array {
		return [
			'not authorised' => [new NotAuthorizedException('no read'), 403, ['error' => 'forbidden', 'message' => 'no read']],
			'handoff exception' => [
				new HandoffException(HandoffException::PROVIDER_UNAVAILABLE, 'provider gone'),
				404,
				['error' => 'not-found', 'message' => 'Object not found.'],
			],
			'does not exist' => [new DoesNotExistException('nope'), 404, ['error' => 'not-found', 'message' => 'Object not found.']],
			'validation is unexpected here' => [
				new ValidationException('bad'),
				500,
				['error' => 'internal', 'message' => 'Handoff availability failed.'],
			],
			'anything else' => [new RuntimeException('db'), 500, ['error' => 'internal', 'message' => 'Handoff availability failed.']],
		];
	}

	/**
	 * @dataProvider availabilityFailures
	 */
	public function testAvailabilityFailureMapping(Throwable $thrown, int $status, array $body): void {
		$this->service->method('listAvailability')->willThrowException($thrown);
		$this->logger->expects($status === 500 ? $this->once() : $this->never())
			->method('error')
			->with('[HandoffController] availability failed: ' . $thrown->getMessage());

		$response = $this->controller->availability('reg', 'sch', 'obj');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testAvailabilityWrapsTheListAndPassesTheRoute(): void {
		$this->service->expects($this->once())
			->method('listAvailability')
			->with(new HandoffSourceRef(register: 'reg', schema: 'sch', id: 'obj'))
			->willReturn([['id' => 'h1']]);

		$response = $this->controller->availability('reg', 'sch', 'obj');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['handoffs' => [['id' => 'h1']]], $response->getData());
	}

	public static function executeResults(): array {
		return [
			'executed' => [['status' => 'executed', 'target' => 'x'], 200],
			'parked' => [['status' => 'parked'], 202],
			'no status' => [['target' => 'x'], 200],
			'other status' => [['status' => 'Parked'], 200],
		];
	}

	/**
	 * @dataProvider executeResults
	 */
	public function testExecuteSuccessStatus(array $result, int $status): void {
		$this->service->expects($this->once())
			->method('execute')
			->with('reg', 'sch', 'obj', 'to-case')
			->willReturn($result);

		$response = $this->controller->execute('reg', 'sch', 'obj', 'to-case');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public static function executeFailures(): array {
		return [
			'provider unavailable' => [
				new HandoffException(HandoffException::PROVIDER_UNAVAILABLE, 'provider gone'),
				409,
				['error' => 'handoff-provider-unavailable', 'message' => 'provider gone'],
			],
			'not declared' => [
				new HandoffException(HandoffException::NOT_DECLARED, 'no such handoff'),
				404,
				['error' => 'handoff-not-declared', 'message' => 'no such handoff'],
			],
			'other handoff code' => [
				new HandoffException('something-else', 'odd'),
				404,
				['error' => 'handoff-not-declared', 'message' => 'odd'],
			],
			'not authorised' => [new NotAuthorizedException('no write'), 403, ['error' => 'forbidden', 'message' => 'no write']],
			'validation' => [new ValidationException('bad field'), 400, ['error' => 'validation', 'message' => 'bad field']],
			'does not exist' => [new DoesNotExistException('nope'), 404, ['error' => 'not-found', 'message' => 'Object not found.']],
			'anything else' => [new RuntimeException('db'), 500, ['error' => 'internal', 'message' => 'Handoff execution failed.']],
		];
	}

	/**
	 * @dataProvider executeFailures
	 */
	public function testExecuteFailureMapping(Throwable $thrown, int $status, array $body): void {
		$this->service->method('execute')->willThrowException($thrown);
		$this->logger->expects($status === 500 ? $this->once() : $this->never())
			->method('error')
			->with('[HandoffController] execute failed: ' . $thrown->getMessage());

		$response = $this->controller->execute('reg', 'sch', 'obj', 'to-case');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}
}
