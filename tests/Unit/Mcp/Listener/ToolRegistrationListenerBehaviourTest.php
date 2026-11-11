<?php

/**
 * ToolRegistrationListener behaviour tests.
 *
 * Covers the provider-bridging decisions the existing listener tests leave
 * open: OpenRegister's own providers are not re-bridged, malformed ids are
 * dropped, metadata falls back to the id, and a failing provider is logged
 * without stopping the others.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\Listener\BuiltinDataTools;
use OCA\OpenRegister\Mcp\Listener\BuiltinPlatformTools;
use OCA\OpenRegister\Mcp\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Mcp\Tool\AgentTool;
use OCA\OpenRegister\Mcp\Tool\ApplicationTool;
use OCA\OpenRegister\Mcp\Tool\McpProviderBridge;
use OCA\OpenRegister\Mcp\Tool\ObjectsTool;
use OCA\OpenRegister\Mcp\Tool\RegisterTool;
use OCA\OpenRegister\Mcp\Tool\SchemaTool;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\Listener\ToolRegistrationListener
 * @uses   \OCA\OpenRegister\Mcp\Tool\McpProviderBridge
 * @uses   \OCA\OpenRegister\Mcp\Listener\BuiltinDataTools
 * @uses   \OCA\OpenRegister\Mcp\Listener\BuiltinPlatformTools
 */
class ToolRegistrationListenerBehaviourTest extends TestCase {

	private McpToolsService&MockObject $mcpToolsService;

	private LoggerInterface&MockObject $logger;

	private ToolRegistrationListener $listener;

	/**
	 * Registered tools, keyed by id.
	 *
	 * @var array<string, array{tool: ToolInterface, metadata: array}>
	 */
	private array $registered = [];

	/**
	 * Build the listener with mocked tools and catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mcpToolsService = $this->createMock(McpToolsService::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
		$this->listener        = new ToolRegistrationListener(
			new BuiltinDataTools(
				$this->createMock(RegisterTool::class),
				$this->createMock(SchemaTool::class),
				$this->createMock(ObjectsTool::class),
			),
			new BuiltinPlatformTools(
				$this->createMock(ApplicationTool::class),
				$this->createMock(AgentTool::class),
			),
			$this->mcpToolsService,
			$this->logger,
		);
	}//end setUp()

	/**
	 * A provider mock.
	 *
	 * @param string $appId App id.
	 * @param array<int, array<string, mixed>>|RuntimeException $tools Descriptors, or an exception to throw.
	 *
	 * @return IMcpToolProvider&MockObject The provider.
	 */
	private function provider(string $appId, array|RuntimeException $tools): IMcpToolProvider&MockObject {
		$provider = $this->createMock(IMcpToolProvider::class);
		$provider->method('getAppId')->willReturn($appId);
		if ($tools instanceof RuntimeException) {
			$provider->method('getTools')->willThrowException($tools);
		} else {
			$provider->method('getTools')->willReturn($tools);
		}

		return $provider;
	}//end provider()

	/**
	 * Dispatch a recording event through the listener and return the bridged (non-builtin) ids.
	 *
	 * @return array<string, array{tool: ToolInterface, metadata: array}>
	 */
	private function dispatch(): array {
		$event = $this->createMock(ToolRegistrationEvent::class);
		$event->method('registerTool')->willReturnCallback(
			function (string $id, ToolInterface $tool, array $metadata): void {
				$this->registered[$id] = ['tool' => $tool, 'metadata' => $metadata];
			}
		);
		$this->listener->handle($event);

		return array_filter(
			$this->registered,
			static fn (array $entry): bool => $entry['tool'] instanceof McpProviderBridge
		);
	}//end dispatch()

	/**
	 * OpenRegister's own MCP providers are never re-bridged.
	 *
	 * @return void
	 */
	public function testOwnProvidersAreNotBridged(): void {
		$this->mcpToolsService->method('getProviders')->willReturn([
			$this->provider('registers', [['id' => 'registers.list']]),
			$this->provider('schemas', [['id' => 'schemas.list']]),
			$this->provider('objects', [['id' => 'objects.list']]),
			$this->provider('openregister', [['id' => 'openregister.x']]),
			$this->provider('decidesk', [['id' => 'decidesk.list']]),
		]);

		$this->assertSame(['decidesk.list'], array_keys($this->dispatch()));
	}//end testOwnProvidersAreNotBridged()

	/**
	 * Ids that are empty or not dotted lower-case app ids are dropped.
	 *
	 * @return void
	 */
	public function testMalformedIdsAreDropped(): void {
		$this->mcpToolsService->method('getProviders')->willReturn([
			$this->provider('decidesk', [
				['name' => 'no id'],
				['id' => 'nodot'],
				['id' => 'Decidesk.upper'],
				['id' => 'decidesk.ok_1'],
			]),
		]);

		$this->assertSame(['decidesk.ok_1'], array_keys($this->dispatch()));
	}//end testMalformedIdsAreDropped()

	/**
	 * Bridged metadata carries name/description, falling back to the id and empty text.
	 *
	 * @return void
	 */
	public function testBridgedMetadataFallsBack(): void {
		$this->mcpToolsService->method('getProviders')->willReturn([
			$this->provider('decidesk', [
				['id' => 'decidesk.full', 'name' => 'Full', 'description' => 'Full tool'],
				['id' => 'decidesk.bare'],
			]),
		]);

		$bridged = $this->dispatch();

		$this->assertSame(
			['name' => 'Full', 'description' => 'Full tool', 'icon' => 'icon-category-integration', 'app' => 'decidesk'],
			$bridged['decidesk.full']['metadata']
		);
		$this->assertSame('decidesk.bare', $bridged['decidesk.bare']['metadata']['name']);
		$this->assertSame('', $bridged['decidesk.bare']['metadata']['description']);
		$this->assertSame(
			['decidesk_bare'],
			array_column($bridged['decidesk.bare']['tool']->getFunctions(), 'name')
		);
	}//end testBridgedMetadataFallsBack()

	/**
	 * A provider that throws is logged and the next provider is still bridged.
	 *
	 * @return void
	 */
	public function testFailingProviderIsLoggedAndOthersContinue(): void {
		$this->mcpToolsService->method('getProviders')->willReturn([
			$this->provider('broken', new RuntimeException('kaput')),
			$this->provider('decidesk', [['id' => 'decidesk.list']]),
		]);
		$this->logger->expects($this->once())->method('warning')
			->with('[ToolRegistrationListener] Failed to bridge MCP provider', ['appId' => 'broken', 'error' => 'kaput']);

		$this->assertSame(['decidesk.list'], array_keys($this->dispatch()));
	}//end testFailingProviderIsLoggedAndOthersContinue()
}//end class
