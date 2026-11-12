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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
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
use OCA\OpenRegister\Service\RetentionServiceInterface;
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
			'retention' => ['resolveRetentionService', RetentionServiceInterface::class, 'RetentionService'],
			'unique hints' => ['resolveUniqueHintChecker', UniqueHintChecker::class, 'UniqueHintChecker'],
			'dedup policy' => ['resolveDedupCreatePolicy', DedupCreatePolicyInterface::class, 'DedupCreatePolicy'],
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
		$this->debug = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message): void {
				$this->debug[] = (string) $message;
			}
		);
		$handler = new SaveObject(
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

		return (new ReflectionMethod(SaveObject::class, $method))->invoke($handler);
	}//end resolve()

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
}//end class
