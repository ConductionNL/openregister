<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\McpController;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndex;
use OCA\OpenRegister\Service\McpDiscoveryService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class McpControllerTest extends TestCase {
	private McpController $controller;
	private IRequest&MockObject $request;
	private McpDiscoveryService&MockObject $mcpDiscoveryService;
	private GrantableRightsIndex&MockObject $grantableRightsIndex;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->mcpDiscoveryService = $this->createMock(McpDiscoveryService::class);

		$this->grantableRightsIndex = $this->createMock(GrantableRightsIndex::class);

		$this->controller = new McpController(
			'openregister',
			$this->request,
			$this->mcpDiscoveryService,
			$this->grantableRightsIndex
		);
	}

	public function testDiscoverSuccess(): void {
		$catalog = ['capabilities' => ['registers', 'schemas']];
		$this->mcpDiscoveryService->method('getCatalog')->willReturn($catalog);

		$result = $this->controller->discover();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($catalog, $result->getData());
	}

	public function testDiscoverException(): void {
		$this->mcpDiscoveryService->method('getCatalog')
			->willThrowException(new \Exception('Service unavailable'));

		$result = $this->controller->discover();

		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('Service unavailable', $result->getData()['error']);
	}

	public function testDiscoverCapabilitySuccess(): void {
		$detail = ['endpoints' => [], 'context' => []];
		$this->mcpDiscoveryService->method('getCapabilityDetail')
			->with('registers')
			->willReturn($detail);

		$result = $this->controller->discoverCapability('registers');

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($detail, $result->getData());
	}

	public function testDiscoverCapabilityNotFound(): void {
		$this->mcpDiscoveryService->method('getCapabilityDetail')->willReturn(null);
		$this->mcpDiscoveryService->method('getCapabilityIds')
			->willReturn(['registers', 'schemas']);

		$result = $this->controller->discoverCapability('unknown');

		$this->assertEquals(404, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('unknown', $data['error']);
		$this->assertEquals(['registers', 'schemas'], $data['available']);
	}

	public function testDiscoverCapabilityException(): void {
		$this->mcpDiscoveryService->method('getCapabilityDetail')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->discoverCapability('registers');

		$this->assertEquals(500, $result->getStatus());
	}

	public function testGrantableRightsReturnsTheMenuWithACount(): void {
		$rights = [
			['register' => 9, 'schema' => 'invoice', 'schemaId' => 1, 'action' => 'read', 'source' => 'authorization'],
		];
		$this->grantableRightsIndex->method('getIndex')->willReturn($rights);

		$result = $this->controller->grantableRights();

		$this->assertEquals(200, $result->getStatus());
		$this->assertSame(1, $result->getData()['total']);
		$this->assertSame($rights, $result->getData()['results']);
	}

	/**
	 * An empty menu is a legitimate answer — no schema offers anything yet —
	 * and must not be reported as a failure. The index deliberately serves
	 * empty rather than stale, so this is the shape a caller sees after a
	 * failed rebuild too.
	 */
	public function testGrantableRightsServesAnEmptyMenuAsSuccess(): void {
		$this->grantableRightsIndex->method('getIndex')->willReturn([]);

		$result = $this->controller->grantableRights();

		$this->assertEquals(200, $result->getStatus());
		$this->assertSame(0, $result->getData()['total']);
	}
}
