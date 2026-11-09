<?php

/**
 * RegistryUpdatesControllerTest
 *
 * Golden test pinning the inbound registry-update endpoint: input refusals,
 * the all-rejected 422, the no-subscription 404, the success body and the
 * generic 500.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\RegistryUpdatesController;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\RegistryUpdatesController
 */
class RegistryUpdatesControllerTest extends TestCase {

	private RegistrySubscriptionService&MockObject $subscriptions;

	private IRequest&MockObject $request;

	private LoggerInterface&MockObject $logger;

	private RegistryUpdatesController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->subscriptions = $this->createMock(RegistrySubscriptionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new RegistryUpdatesController(
			'openregister',
			$this->request,
			$this->subscriptions,
			$this->logger
		);
	}

	private function givenParams(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	public function testMissingIdentityIsA400(): void {
		$this->givenParams(['properties' => ['a' => 1]]);
		$this->subscriptions->expects($this->never())->method('applyInboundUpdate');

		$response = $this->controller->update('brp');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['message' => 'identity is required.', 'error' => 'invalid-argument'],
			$response->getData()
		);
	}

	public function testNonArrayPropertiesIsA400(): void {
		$this->givenParams(['identity' => '123', 'properties' => 'nope']);
		$this->subscriptions->expects($this->never())->method('applyInboundUpdate');

		$response = $this->controller->update('brp');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['message' => 'properties must be an object.', 'error' => 'invalid-argument'],
			$response->getData()
		);
	}

	public function testAllRejectedIsA422ListingUniqueProperties(): void {
		$this->givenParams(['identity' => 123, 'properties' => ['naam' => 'x'], 'eventReference' => 'ev-1']);
		$this->subscriptions->expects($this->once())
			->method('applyInboundUpdate')
			->with('brp', '123', ['naam' => 'x'], 'ev-1')
			->willReturn([
				'matched' => 2,
				'applied' => [],
				'rejected' => [
					['properties' => ['naam', 'adres']],
					['properties' => ['adres', 'geboortedatum']],
				],
			]);

		$response = $this->controller->update('brp');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			[
				'message' => 'Property not owned by the registry: naam, adres, geboortedatum',
				'error' => 'validation',
			],
			$response->getData()
		);
	}

	public function testNoMatchIsA404AndLogsAWarning(): void {
		$this->givenParams(['identity' => '123']);
		$this->subscriptions->expects($this->once())
			->method('applyInboundUpdate')
			->with('kvk', '123', [], '')
			->willReturn(['matched' => 0, 'applied' => [], 'rejected' => []]);
		$this->logger->expects($this->once())
			->method('warning')
			->with('[OpenRegister.RegistryUpdatesController] No subscription found for registry kvk');

		$response = $this->controller->update('kvk');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'No object is subscribed to this registry/identity.'], $response->getData());
	}

	public function testPartialApplyReturnsTheResultAndLogsInfo(): void {
		$result = [
			'matched' => 2,
			'applied' => ['uuid-1'],
			'rejected' => [['properties' => ['naam']]],
		];
		$this->givenParams(['identity' => '123', 'properties' => ['adres' => 'y']]);
		$this->subscriptions->method('applyInboundUpdate')->willReturn($result);
		$this->logger->expects($this->once())
			->method('info')
			->with('[OpenRegister.RegistryUpdatesController] Applied inbound update for registry brp to 1 object(s).');

		$response = $this->controller->update('brp');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testUnexpectedErrorIsAGeneric500(): void {
		$this->givenParams(['identity' => '123']);
		$this->subscriptions->method('applyInboundUpdate')->willThrowException(new RuntimeException('db gone'));

		$response = $this->controller->update('brp');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Internal server error'], $response->getData());
	}
}
