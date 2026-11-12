<?php

/**
 * McpProviderDiscovery behaviour tests.
 *
 * Pins the schema-derived grouping decisions one at a time (opt-in, owning
 * app from schema vs register, orphan skip, register lookup null/throw),
 * suppression of hand-written ids, and the enumeration failure paths.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\McpDiscoveryRun;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\McpProviderDiscovery
 * @uses   \OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider
 * @uses   \OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolServices
 * @uses   \OCA\OpenRegister\Mcp\AttributeToolScanner
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
class McpProviderDiscoveryBehaviourTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemaMapper;

	private RegisterMapperInterface&MockObject $registerMapper;

	private LoggerInterface&MockObject $logger;

	/**
	 * Set up mapper and logger doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemaMapper   = $this->createMock(SchemaMapperInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->logger         = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Invoke a private method of a fresh discovery.
	 *
	 * @param string $method Method name.
	 * @param array<int, mixed> $arguments Arguments, references preserved.
	 *
	 * @return mixed Return value.
	 */
	private function invoke(string $method, array $arguments): mixed {
		$reflection = new ReflectionMethod(McpProviderDiscovery::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs(new McpProviderDiscovery(), $arguments);
	}//end invoke()

	/**
	 * A container serving the mappers and schema-derived services.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$bindings  = [
			SchemaMapperInterface::class => $this->schemaMapper,
			RegisterMapperInterface::class => $this->registerMapper,
			ObjectOperationsInterface::class => $this->createMock(ObjectOperationsInterface::class),
			AuditTrailMapperInterface::class => $this->createMock(AuditTrailMapperInterface::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($bindings) {
				if (isset($bindings[$id]) === true) {
					return $bindings[$id];
				}

				throw new RuntimeException('No binding for ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * A schema with id, application and configuration.
	 *
	 * @param int $id Id.
	 * @param string|null $application Owning app.
	 * @param array|null $configuration Configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, ?string $application, ?array $configuration): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setApplication($application);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * A provider stub.
	 *
	 * @param string $appId App id.
	 * @param array<int, array<string, mixed>> $tools Descriptors.
	 *
	 * @return IMcpToolProvider The provider.
	 */
	private function provider(string $appId, array $tools): IMcpToolProvider {
		$provider = $this->createMock(IMcpToolProvider::class);
		$provider->method('getAppId')->willReturn($appId);
		$provider->method('getTools')->willReturn($tools);

		return $provider;
	}//end provider()

	/**
	 * Run the schema-derived collection over the given providers.
	 *
	 * @param list<IMcpToolProvider> $providers Providers, appended in place.
	 *
	 * @return list<IMcpToolProvider> Providers after collection.
	 */
	private function collect(array $providers): array {
		$run = new McpDiscoveryRun($this->container(), $this->logger);
		$run->addAll($providers);
		$this->invoke('collectSchemaDerivedMcpProviders', [$run]);

		return $run->providers();
	}//end collect()

	/**
	 * Read a SchemaDerivedToolProvider's private state.
	 *
	 * @param SchemaDerivedToolProvider $provider Provider.
	 * @param string $property Property.
	 *
	 * @return mixed Value.
	 */
	private function state(SchemaDerivedToolProvider $provider, string $property): mixed {
		$reflection = new ReflectionProperty(SchemaDerivedToolProvider::class, $property);
		$reflection->setAccessible(true);

		return $reflection->getValue($provider);
	}//end state()

	/**
	 * Only schemas whose x-openregister-mcp annotation is an array with enabled === true are used.
	 *
	 * @return void
	 */
	public function testOnlyStrictlyOptedInSchemasAreGrouped(): void {
		$this->schemaMapper->method('findAll')->willReturn([
			$this->schema(1, 'app', null),
			$this->schema(2, 'app', ['x-openregister-mcp' => 'yes']),
			$this->schema(3, 'app', ['x-openregister-mcp' => ['enabled' => 'true']]),
			$this->schema(4, 'app', ['x-openregister-mcp' => ['enabled' => true]]),
		]);
		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(null);

		$providers = $this->collect([]);

		$this->assertCount(1, $providers);
		$entries = $this->state($providers[0], 'schemaEntries');
		$this->assertSame([4], array_map(static fn (array $e): int => $e['schema']->getId(), $entries));
		$this->assertNull($entries[0]['register']);
	}//end testOnlyStrictlyOptedInSchemasAreGrouped()

	/**
	 * The schema's own app wins; otherwise the owning register's app; otherwise skipped with a debug log.
	 *
	 * @return void
	 */
	public function testOwningAppResolution(): void {
		$on = ['x-openregister-mcp' => ['enabled' => true]];
		$this->schemaMapper->method('findAll')->willReturn([
			$this->schema(1, 'schemaapp', $on),
			$this->schema(2, '', $on),
			$this->schema(3, null, $on),
			$this->schema(4, null, $on),
		]);
		$owned = new Register();
		$owned->setApplication('regapp');
		$blank = new Register();
		$blank->setApplication('');
		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturnCallback(
			static function (int $schemaId): ?int {
				if ($schemaId === 4) {
					throw new RuntimeException('lookup broke');
				}

				return [1 => 10, 2 => 20, 3 => 30][$schemaId];
			}
		);
		$this->registerMapper->method('find')->willReturnCallback(
			static fn (string|int $id): Register => ((int)$id === 30 ? $blank : $owned)
		);
		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			static function (string $message) use (&$debug): void {
				$debug[] = $message;
			}
		);

		$providers = $this->collect([]);

		$this->assertSame(['schemaapp', 'regapp'], array_map(static fn ($p): string => $p->getAppId(), $providers));
		$this->assertSame(
			[
				'[Application] Opted-in schema has no resolvable owning app — skipped',
				'[Application] Owning-register resolution failed for schema 4: lookup broke',
				'[Application] Opted-in schema has no resolvable owning app — skipped',
			],
			$debug
		);
	}//end testOwningAppResolution()

	/**
	 * Ids of existing same-app providers are suppressed; other apps' and id-less descriptors are not.
	 *
	 * @return void
	 */
	public function testExistingSameAppToolIdsAreSuppressed(): void {
		$this->schemaMapper->method('findAll')->willReturn([
			$this->schema(1, 'pipelinq', ['x-openregister-mcp' => ['enabled' => true]]),
		]);
		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(null);

		$providers = $this->collect([
			$this->provider('pipelinq', [['id' => 'pipelinq.lead.search'], ['name' => 'no id'], ['id' => 7]]),
			$this->provider('other', [['id' => 'other.x']]),
		]);

		$this->assertCount(3, $providers);
		$this->assertSame(['pipelinq.lead.search', '7'], $this->state($providers[2], 'suppressedIds'));
	}//end testExistingSameAppToolIdsAreSuppressed()

	/**
	 * A failing schema enumeration is logged as a warning and adds nothing.
	 *
	 * @return void
	 */
	public function testSchemaEnumerationFailureIsLogged(): void {
		$this->schemaMapper->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[Application] Schema-derived provider enumeration failed: db down');

		$this->assertSame([], $this->collect([]));
	}//end testSchemaEnumerationFailureIsLogged()

	/**
	 * A failing attribute enumeration is logged as a warning and keeps existing providers.
	 *
	 * @return void
	 */
	public function testAttributeEnumerationFailureIsLogged(): void {
		$existing  = $this->provider('a', []);
		$providers = [$existing];
		$this->logger->expects($this->once())->method('warning')
			->with('[Application] Attributed-tool provider enumeration failed: No binding for OCP\App\IAppManager');

		$run = new McpDiscoveryRun($this->container(), $this->logger);
		$run->addAll($providers);
		$this->invoke('collectAttributeMcpProviders', [$run]);

		$this->assertSame([$existing], $run->providers());
	}//end testAttributeEnumerationFailureIsLogged()

	/**
	 * Collision checks only consider providers of the same app.
	 *
	 * @return void
	 */
	public function testCollisionCheckIgnoresOtherApps(): void {
		$descriptors = [['id' => 'pipelinq.ping'], ['id' => 'pipelinq.pong']];
		$this->logger->expects($this->never())->method('error');

		$run = new McpDiscoveryRun($this->container(), $this->logger);
		$run->addAll([$this->provider('other', [['id' => 'pipelinq.ping']]), $this->provider('pipelinq', [['id' => 'pipelinq.pong']])]);
		$kept = $run->withoutCollidingDescriptors('pipelinq', $descriptors);

		$this->assertSame([['id' => 'pipelinq.ping']], $kept);
	}//end testCollisionCheckIgnoresOtherApps()
}//end class
