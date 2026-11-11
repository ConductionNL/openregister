<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\ContextChat\Listener\ContextChatSubmissionListenerInterface;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Mcp\McpRegistrar;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Service\ToolRegistryInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;

/**
 * The McpToolsService factory moved from Application into McpRegistrar (lane C11).
 *
 * The bootstrap still registers it exactly once, built by McpProviderDiscovery,
 * and every Mcp interface still resolves to the class it resolved to before.
 *
 * @coversNothing
 */
final class C11InterfaceBindingsTest extends TestCase {

	/**
	 * Interface => class, as bound before the move.
	 *
	 * @var array<class-string, class-string>
	 */
	private const EXPECTED = [
		AgentMapperInterface::class                   => AgentMapper::class,
		ChatServiceInterface::class                   => ChatService::class,
		ContextChatSubmissionListenerInterface::class => ContextChatSubmissionListener::class,
		ConversationMapperInterface::class            => ConversationMapper::class,
		ToolRegistryInterface::class                  => ToolRegistry::class,
	];

	/**
	 * Run the real bootstrap against a recording context.
	 *
	 * @return RecordingRegistrationContext
	 */
	private function bootstrap(): RecordingRegistrationContext {
		$context = new RecordingRegistrationContext();
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		return $context;
	}//end bootstrap()

	/**
	 * The binding list is unchanged.
	 *
	 * @return void
	 */
	public function testTheMcpBindingListIsUnchanged(): void {
		$this->assertSame(self::EXPECTED, McpRegistrar::BINDINGS);
	}//end testTheMcpBindingListIsUnchanged()

	/**
	 * Every Mcp interface resolves (last alias wins) to the same class through the bootstrap.
	 *
	 * @return void
	 */
	public function testTheBootstrapResolvesEveryMcpInterfaceToTheSameClass(): void {
		$resolved = [];
		foreach ($this->bootstrap()->aliases as $alias) {
			$resolved[$alias['alias']] = $alias['target'];
		}

		foreach (self::EXPECTED as $contract => $service) {
			$this->assertArrayHasKey($contract, $resolved);
			$this->assertSame($service, $resolved[$contract], $contract);
		}
	}//end testTheBootstrapResolvesEveryMcpInterfaceToTheSameClass()

	/**
	 * McpToolsService is registered exactly once, by McpProviderDiscovery's factory.
	 *
	 * @return void
	 */
	public function testMcpToolsServiceIsRegisteredOnceByTheDiscoveryFactory(): void {
		$context = $this->bootstrap();

		$count = array_count_values($context->services)[McpToolsService::class] ?? 0;
		$this->assertSame(1, $count);

		$factory = $context->factories[McpToolsService::class];
		$this->assertInstanceOf(\Closure::class, $factory);
		$this->assertInstanceOf(McpProviderDiscovery::class, (new ReflectionFunction($factory))->getClosureThis());
	}//end testMcpToolsServiceIsRegisteredOnceByTheDiscoveryFactory()

	/**
	 * McpRegistrar on its own now carries the factory.
	 *
	 * @return void
	 */
	public function testMcpRegistrarRegistersMcpToolsService(): void {
		$context = new RecordingRegistrationContext();
		McpRegistrar::register(context: $context);

		$this->assertSame([McpToolsService::class], $context->services);
	}//end testMcpRegistrarRegistersMcpToolsService()

	/**
	 * The bootstrap no longer names McpProviderDiscovery.
	 *
	 * @return void
	 */
	public function testApplicationDoesNotNameTheDiscovery(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
		$this->assertStringNotContainsString('McpProviderDiscovery', $source);
	}//end testApplicationDoesNotNameTheDiscovery()
}//end class
