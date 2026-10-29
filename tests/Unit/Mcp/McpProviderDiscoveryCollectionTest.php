<?php

/**
 * McpProviderDiscovery collects schema-derived, announced and attributed providers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Mcp\RegisterMcpToolProvidersEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\McpProviderDiscovery
 * @uses   \OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider
 * @uses   \OCA\OpenRegister\Mcp\RegisterMcpToolProvidersEvent
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class McpProviderDiscoveryCollectionTest extends TestCase {

	/**
	 * Invoke a private McpProviderDiscovery method with the given arguments.
	 *
	 * @param string $method The method name.
	 * @param array<int, mixed> $arguments Positional arguments, references kept.
	 *
	 * @return mixed The method's return value.
	 */
	private function invoke(string $method, array $arguments): mixed {
		$reflection = new ReflectionMethod(McpProviderDiscovery::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs(new McpProviderDiscovery(), $arguments);
	}//end invoke()

	/**
	 * A container that answers from a binding map and throws for anything else.
	 *
	 * @param array<string, mixed> $bindings Service id => instance.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $bindings): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => array_key_exists($id, $bindings)
		);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($bindings) {
				if (array_key_exists($id, $bindings) === true) {
					return $bindings[$id];
				}

				throw new RuntimeException('No binding for ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * A provider stub with a fixed app id and tool ids.
	 *
	 * @param string $appId The app id.
	 * @param list<string> $toolIds The tool ids it exposes.
	 *
	 * @return IMcpToolProvider
	 */
	private function provider(string $appId, array $toolIds): IMcpToolProvider {
		$provider = $this->createMock(IMcpToolProvider::class);
		$provider->method('getAppId')->willReturn($appId);
		$provider->method('getTools')->willReturn(
			array_map(static fn (string $id): array => ['id' => $id], $toolIds)
		);

		return $provider;
	}//end provider()

	/**
	 * A schema with the given id, owning app and MCP opt-in.
	 *
	 * @param int $id The schema id.
	 * @param string|null $application The owning app, or null.
	 * @param bool $optedIn Whether the schema enables MCP tools.
	 *
	 * @return Schema
	 */
	private function schema(int $id, ?string $application, bool $optedIn): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('schema' . $id);
		$schema->setApplication($application);
		$schema->setConfiguration(['x-openregister-mcp' => ['enabled' => $optedIn]]);

		return $schema;
	}//end schema()

	/**
	 * One derived provider is appended per owning app, resolved from the
	 * schema or else from its owning register; orphaned schemas are skipped.
	 *
	 * @return void
	 */
	public function testSchemaDerivedProvidersAreGroupedByOwningApp(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn(
			[
				$this->schema(id: 1, application: 'pipelinq', optedIn: true),
				$this->schema(id: 2, application: null, optedIn: true),
				$this->schema(id: 3, application: null, optedIn: true),
				$this->schema(id: 4, application: null, optedIn: true),
				$this->schema(id: 5, application: 'pipelinq', optedIn: false),
				$this->schema(id: 6, application: 'pipelinq', optedIn: true),
			]
		);

		$register = new Register();
		$register->setApplication('decidiq');
		$appless = new Register();

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturnCallback(
			static function (int $schemaId): ?int {
				if ($schemaId === 4) {
					throw new RuntimeException('register table missing');
				}

				return [2 => 20, 3 => 30, 6 => null][$schemaId] ?? null;
			}
		);
		$registerMapper->method('find')->willReturnCallback(
			static fn (string|int $id): Register => ((int)$id === 20) ? $register : $appless
		);

		$container = $this->container(
			[
				SchemaMapperInterface::class => $schemaMapper,
				RegisterMapperInterface::class => $registerMapper,
				ObjectService::class => $this->createMock(ObjectService::class),
				AuditTrailMapperInterface::class => $this->createMock(AuditTrailMapper::class),
			]
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$handWritten = $this->provider(appId: 'pipelinq', toolIds: ['pipelinq.schema1.search']);
		$providers = [$handWritten, $this->provider(appId: 'other', toolIds: ['other.x'])];
		$this->invoke('collectSchemaDerivedMcpProviders', [$container, $logger, &$providers]);

		$this->assertCount(4, $providers);
		$this->assertInstanceOf(SchemaDerivedToolProvider::class, $providers[2]);
		$this->assertSame('pipelinq', $providers[2]->getAppId());
		$this->assertInstanceOf(SchemaDerivedToolProvider::class, $providers[3]);
		$this->assertSame('decidiq', $providers[3]->getAppId());
	}//end testSchemaDerivedProvidersAreGroupedByOwningApp()

	/**
	 * A failing schema enumeration is logged and leaves the providers untouched.
	 *
	 * @return void
	 */
	public function testAFailingSchemaEnumerationLeavesProvidersUntouched(): void {
		$container = $this->container([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Schema-derived provider enumeration failed'));

		$existing = $this->provider(appId: 'pipelinq', toolIds: []);
		$providers = [$existing];
		$this->invoke('collectSchemaDerivedMcpProviders', [$container, $logger, &$providers]);

		$this->assertSame([$existing], $providers);
	}//end testAFailingSchemaEnumerationLeavesProvidersUntouched()

	/**
	 * Announced providers are appended once per app, after the ones already present.
	 *
	 * @return void
	 */
	public function testAnnouncedProvidersAreAddedOncePerApp(): void {
		$builtIn = $this->provider(appId: 'openregister', toolIds: []);
		$duplicate = $this->provider(appId: 'openregister', toolIds: []);
		$first = $this->provider(appId: 'pipelinq', toolIds: []);
		$second = $this->provider(appId: 'pipelinq', toolIds: []);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (RegisterMcpToolProvidersEvent $event) use ($duplicate, $first, $second): void {
				$event->registerProvider($duplicate);
				$event->registerProvider($first);
				$event->registerProvider($second);
			}
		);

		$container = $this->container([IEventDispatcher::class => $dispatcher]);
		$providers = [$builtIn];
		$this->invoke('collectAnnouncedMcpProviders', [$container, $this->createMock(LoggerInterface::class), &$providers]);

		$this->assertSame([$builtIn, $first], $providers);
	}//end testAnnouncedProvidersAreAddedOncePerApp()

	/**
	 * A failing dispatch is logged and leaves the providers untouched.
	 *
	 * @return void
	 */
	public function testAFailingAnnouncementIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$providers = [];
		$this->invoke('collectAnnouncedMcpProviders', [$this->container([]), $logger, &$providers]);

		$this->assertSame([], $providers);
	}//end testAFailingAnnouncementIsLogged()

	/**
	 * An app whose opt-in alias throws, or resolves to the wrong type, declares no classes.
	 *
	 * @return void
	 */
	public function testABrokenOrWrongScannableDeclarationYieldsNoClasses(): void {
		$key = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq';

		$throwing = $this->createMock(ContainerInterface::class);
		$throwing->method('has')->willThrowException(new RuntimeException('container broken'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$this->assertSame([], $this->invoke('resolveScannableServiceClasses', [$throwing, $logger, 'pipelinq']));

		$wrongType = $this->container([$key => new \stdClass()]);
		$this->assertSame(
			[],
			$this->invoke('resolveScannableServiceClasses', [$wrongType, $this->createMock(LoggerInterface::class), 'pipelinq'])
		);
	}//end testABrokenOrWrongScannableDeclarationYieldsNoClasses()

	/**
	 * An attributed tool whose service cannot be resolved is logged and skipped.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAttributedServiceIsSkipped(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Could not resolve attributed tool service instance'));

		$descriptors = [['id' => 'pipelinq.ping', 'class' => 'OCA\\Pipelinq\\Service\\Missing']];
		$providers = [$this->provider(appId: 'other', toolIds: ['pipelinq.ping'])];

		$entries = $this->invoke(
			'resolveAttributeEntries',
			[$this->container([]), $logger, 'pipelinq', $descriptors, $providers]
		);

		$this->assertSame([], $entries);
	}//end testAnUnresolvableAttributedServiceIsSkipped()
}//end class
