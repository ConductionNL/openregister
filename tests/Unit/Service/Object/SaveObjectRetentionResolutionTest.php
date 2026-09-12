<?php

/**
 * SaveObject resolves the retention service through its injected container, never the global server.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Twig\Loader\ArrayLoader;

/**
 * Regression guard for the 2026-09-08 memory blow-up.
 *
 * SaveObject used to fetch RetentionService with `\OC::$server->get()`. Outside a
 * booted Nextcloud that global container knows none of this app's registrations
 * and autowires from scratch, which recurses through a MagicMapper ->
 * SettingsService -> ValidationOperationsHandler -> ValidateObject cycle until
 * memory runs out (19 GB observed). The service is now resolved through the
 * container injected into the constructor, which a unit test controls.
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-objects-must-carry-mdto-compliant-archival-metadata-in-the-retention-field
 */
class SaveObjectRetentionResolutionTest extends TestCase {

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IURLGenerator&MockObject */
	private IURLGenerator $urlGenerator;

	/**
	 * Prepare the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
	}//end setUp()

	/**
	 * Build a SaveObject with mocked collaborators and the given container.
	 *
	 * @param ContainerInterface|null $container The container seam under test.
	 *
	 * @return SaveObject
	 */
	private function buildHandler(?ContainerInterface $container): SaveObject {
		return new SaveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->urlGenerator,
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(ComputedFieldHandler::class),
			$this->createMock(TranslationHandler::class),
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			$this->logger,
			$this->createMock(TmloService::class),
			$this->createMock(FolderManagementHandler::class),
			new ArrayLoader(),
			container: $container,
		);
	}//end buildHandler()

	/**
	 * Call the private resolver.
	 *
	 * @param SaveObject $handler The handler under test.
	 *
	 * @return RetentionService|null
	 */
	private function resolve(SaveObject $handler): ?RetentionService {
		$method = new ReflectionMethod(SaveObject::class, 'resolveRetentionService');
		$method->setAccessible(true);
		return $method->invoke($handler);
	}//end resolve()

	/**
	 * A schema mock with an empty schema object, as the creation path expects.
	 *
	 * @return Schema&MockObject
	 */
	private function emptySchema(): Schema {
		$schemaObject = new \stdClass();
		$schemaObject->properties = [];
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn($schemaObject);
		$schema->method('getConfiguration')->willReturn([]);
		$schema->method('getProperties')->willReturn([]);
		$schema->method('hasPropertyAuthorization')->willReturn(false);
		$schema->setId(1);
		return $schema;
	}//end emptySchema()

	/**
	 * Without a container there is no retention service, and nothing is logged.
	 *
	 * @return void
	 */
	public function testNoContainerResolvesToNull(): void {
		$this->logger->expects($this->never())->method('debug');
		$this->assertNull($this->resolve($this->buildHandler(null)));
	}//end testNoContainerResolvesToNull()

	/**
	 * A container that has the service hands it back.
	 *
	 * @return void
	 */
	public function testContainerServiceIsReturned(): void {
		$retention = $this->createMock(RetentionService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(RetentionService::class)
			->willReturn($retention);

		$this->assertSame($retention, $this->resolve($this->buildHandler($container)));
	}//end testContainerServiceIsReturned()

	/**
	 * A container that cannot build the service degrades to null with a debug line, not an exception.
	 *
	 * @return void
	 */
	public function testContainerFailureDegradesToNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('no such service'));

		$this->assertNull($this->resolve($this->buildHandler($container)));
	}//end testContainerFailureDegradesToNull()

	/**
	 * A container that answers with the wrong type is treated as having no service.
	 *
	 * @return void
	 */
	public function testWrongTypeFromContainerIsIgnored(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		$this->assertNull($this->resolve($this->buildHandler($container)));
	}//end testWrongTypeFromContainerIsIgnored()

	/**
	 * The creation path applies archival metadata through the injected container.
	 *
	 * This is the call that used to go through the global server.
	 *
	 * @return void
	 */
	public function testCreationAppliesArchivalMetadataThroughInjectedContainer(): void {
		$schema = $this->emptySchema();
		$register = $this->getMockBuilder(Register::class)->onlyMethods([])->getMock();
		$register->setId(1);
		$register->setSlug('test');

		$retention = $this->createMock(RetentionService::class);
		$retention->expects($this->once())
			->method('applyArchivalMetadata')
			->with($this->isInstanceOf(ObjectEntity::class), $schema)
			->willReturnCallback(static function (ObjectEntity $entity): ObjectEntity {
				$entity->setDescription('archived-by-test');
				return $entity;
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(RetentionService::class)->willReturn($retention);

		$handler = $this->buildHandler($container);

		$cache = new ReflectionClass(SaveObject::class);
		$property = $cache->getProperty('schemaCache');
		$property->setAccessible(true);
		$property->setValue($handler, ['1' => $schema]);

		$this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/test');
		$this->urlGenerator->method('linkToRoute')->willReturn('/test');

		$method = new ReflectionMethod(SaveObject::class, 'handleObjectCreation');
		$method->setAccessible(true);
		$result = $method->invokeArgs(
			$handler,
			[1, 1, $register, $schema, ['name' => 'Test'], [], 'test-uuid-123', null, false, false, false]
		);

		$this->assertInstanceOf(ObjectEntity::class, $result);
		$this->assertSame('archived-by-test', $result->getDescription());
	}//end testCreationAppliesArchivalMetadataThroughInjectedContainer()

	/**
	 * The handler never reaches for the global server again.
	 *
	 * A plain source assertion, because the failure mode is a process that eats
	 * all memory rather than a wrong return value: there is nothing else to assert on.
	 *
	 * @return void
	 */
	public function testSourceDoesNotConsultTheGlobalServer(): void {
		$source = file_get_contents((new ReflectionClass(SaveObject::class))->getFileName());
		$this->assertIsString($source);
		$this->assertStringNotContainsString(
			'\\OC::$server',
			$source,
			'SaveObject must resolve services through its injected container; the global server autowires an unbounded cycle outside a booted Nextcloud.'
		);
	}//end testSourceDoesNotConsultTheGlobalServer()
}//end class
