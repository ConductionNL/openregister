<?php

/**
 * Golden tests for the services SaveObject resolves lazily from the app container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultsInterface;
use OCA\OpenRegister\Kernel\Contracts\CreateGuardInterface;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Kernel\Contracts\Core\RecordRetentionStampsInterface;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Pins the four lazy resolvers: no container, a found service, a container that throws, and a wrong type.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\NestedSaveTransaction
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 */
class SaveObjectContainerServicesGoldenTest extends TestCase {

	/** @var list<string> */
	private array $debug = [];

	/**
	 * The resolvers: method, container id, log label.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function resolvers(): array {
		return [
			'shared master data' => ['resolveSharedMasterDataService', SharedMasterDataService::class, 'SharedMasterDataService'],
			'retention' => ['resolveRetentionService', RecordRetentionStampsInterface::class, 'RetentionService'],
			'unique hints' => ['resolveUniqueHintChecker', UniqueHintChecker::class, 'UniqueHintChecker'],
			'dedup policy' => ['resolveDedupCreatePolicy', CreateGuardInterface::class, 'DedupCreatePolicy'],
		];
	}//end resolvers()

	/**
	 * Call a resolver on a SaveObject with the given container.
	 *
	 * @param string                  $method    The resolver.
	 * @param ContainerInterface|null $container The container.
	 *
	 * @return mixed
	 */
	private function resolve(string $method, ?ContainerInterface $container): mixed {
		return (new ReflectionMethod(SaveObject::class, $method))->invoke($this->handler(container: $container));
	}//end resolve()

	/**
	 * A SaveObject on the given container, logging its debug lines into $this->debug.
	 *
	 * @param ContainerInterface|null $container The container.
	 *
	 * @return SaveObject
	 */
	private function handler(?ContainerInterface $container): SaveObject {
		$this->debug = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message): void {
				$this->debug[] = (string) $message;
			}
		);
		return new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
						unifiedObjectMapper: $this->createMock(MagicMapper::class)
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class),
						container: $container
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->createMock(MetadataHydrationHandler::class),
						filePropertyHandler: $this->createMock(FilePropertyHandler::class),
						linkedEntityHandler: $this->createMock(LinkedEntityPropertyHandler::class),
						computedFieldHandler: $this->createMock(ComputedFieldHandler::class)
					),
					values: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers(
						translationHandler: $this->createMock(TranslationHandler::class),
						translationProjectionService: $this->createMock(TranslationProjectionService::class),
						translationStatusService: $this->createMock(TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $this->createMock(IUserSession::class),
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: $logger
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $this->createMock(OrganisationService::class)
				)
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			folderManagementHandler: $this->createMock(FolderManagementHandler::class)
		);
	}//end handler()

	/**
	 * A container that answers one id with the given value or exception.
	 *
	 * @param string $id     The id it is asked for.
	 * @param mixed  $answer The value, or a Throwable to throw.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $id, mixed $answer): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with($id)->willReturnCallback(
			static function () use ($answer) {
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		return $container;
	}//end container()

	/**
	 * @dataProvider resolvers
	 */
	public function testNoContainerResolvesNothingAndLogsNothing(string $method, string $id, string $label): void {
		$this->assertNull($this->resolve($method, null));
		$this->assertSame([], $this->debug);
	}//end testNoContainerResolvesNothingAndLogsNothing()

	/**
	 * @dataProvider resolvers
	 */
	public function testTheContainersServiceIsReturnedAsIs(string $method, string $id, string $label): void {
		$service = $this->createMock($id);
		$this->assertSame($service, $this->resolve($method, $this->container($id, $service)));
		$this->assertSame([], $this->debug);
	}//end testTheContainersServiceIsReturnedAsIs()

	/**
	 * @dataProvider resolvers
	 */
	public function testAFailingContainerResolvesNothingAndLogsWhy(string $method, string $id, string $label): void {
		$this->assertNull($this->resolve($method, $this->container($id, new RuntimeException('boom'))));
		$this->assertSame(['[SaveObject] ' . $label . ' not available: boom'], $this->debug);
	}//end testAFailingContainerResolvesNothingAndLogsWhy()

	/**
	 * @dataProvider resolvers
	 */
	public function testAnAnswerOfTheWrongTypeResolvesNothing(string $method, string $id, string $label): void {
		$this->assertNull($this->resolve($method, $this->container($id, new stdClass())));
		$this->assertSame([], $this->debug);
	}//end testAnAnswerOfTheWrongTypeResolvesNothing()

	/**
	 * Expression defaults are the rules engine's work: the save path hands the schema and data to the Core port and returns its answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testExpressionDefaultsAreAskedOfTheCorePort(): void {
		$schema = new Schema();
		$port = $this->createMock(ExpressionDefaultsInterface::class);
		$port->expects($this->once())->method('apply')->with($schema, ['a' => 1])->willReturn(['a' => 1, 'derived' => 2]);

		$result = $this->handler(container: $this->container(ExpressionDefaultsInterface::class, $port))->applyExpressionDefaults(schema: $schema, data: ['a' => 1]);

		$this->assertSame(['a' => 1, 'derived' => 2], $result);
	}//end testExpressionDefaultsAreAskedOfTheCorePort()

	/**
	 * A refusal of the port reaches the caller unchanged, so the create is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testAnExpressionDefaultRefusalReachesTheCaller(): void {
		$port = $this->createMock(ExpressionDefaultsInterface::class);
		$port->method('apply')->willThrowException(new ExpressionDefaultException(property: 'due', message: 'due: no date'));

		$this->expectException(ExpressionDefaultException::class);

		$this->handler(container: $this->container(ExpressionDefaultsInterface::class, $port))->applyExpressionDefaults(schema: new Schema(), data: []);
	}//end testAnExpressionDefaultRefusalReachesTheCaller()

	/**
	 * A container that cannot serve the port fails loudly instead of skipping the defaults.
	 *
	 * @return void
	 */
	public function testAContainerThatCannotServeTheExpressionDefaultsPortFailsLoudly(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('boom');

		$this->handler(container: $this->container(ExpressionDefaultsInterface::class, new RuntimeException('boom')))->applyExpressionDefaults(schema: new Schema(), data: []);
	}//end testAContainerThatCannotServeTheExpressionDefaultsPortFailsLoudly()

	/**
	 * A hand-built handler with no container derives nothing and returns the data as given.
	 *
	 * @return void
	 */
	public function testWithoutAContainerNoExpressionDefaultIsDerived(): void {
		$this->assertSame(['a' => 1], $this->handler(container: null)->applyExpressionDefaults(schema: new Schema(), data: ['a' => 1]));
	}//end testWithoutAContainerNoExpressionDefaultIsDerived()
}//end class
