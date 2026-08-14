<?php

/**
 * LinkedEntityServiceTest
 *
 * Unit tests confirming that LinkedEntityService validates types via the
 * IntegrationRegistry and that TYPE_COLUMN_MAP has been removed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-4
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LinkedEntityService.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\LinkedEntityService
 */
class LinkedEntityServiceTest extends TestCase {

	private MagicMapper $magicMapper;

	private SchemaMapper $schemaMapper;

	private RegisterMapper $registerMapper;

	private OrganisationMapper $organisationMapper;

	private IntegrationRegistry $registry;

	private LoggerInterface $logger;

	private PermissionHandler $permissionHandler;

	private DeepLinkRegistryService $deepLinkRegistry;

	private LinkedEntityService $service;

	protected function setUp(): void {
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->registry = $this->createMock(IntegrationRegistry::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);

		$this->service = new LinkedEntityService(
			$this->magicMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->organisationMapper,
			$this->registry,
			$this->logger,
			$this->permissionHandler,
			$this->deepLinkRegistry
		);
	}//end setUp()

	/**
	 * TYPE_COLUMN_MAP constant must no longer exist on the class.
	 *
	 * @covers \OCA\OpenRegister\Service\LinkedEntityService
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-1
	 */
	public function testTypeColumnMapConstantIsRemoved(): void {
		$rc = new \ReflectionClass(LinkedEntityService::class);
		$this->assertFalse(
			$rc->hasConstant('TYPE_COLUMN_MAP'),
			'TYPE_COLUMN_MAP constant must be removed from LinkedEntityService'
		);
	}//end testTypeColumnMapConstantIsRemoved()

	/**
	 * addLink throws when the type is not registered.
	 *
	 * @covers \OCA\OpenRegister\Service\LinkedEntityService::addLink
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-2
	 */
	public function testAddLinkThrowsForUnknownType(): void {
		$this->registry
			->method('listIds')
			->willReturn(['files', 'notes']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/Invalid linked entity type/');

		$this->service->addLink(objectUuid: 'some-uuid', type: 'unknown', entityId: 'e-1');
	}//end testAddLinkThrowsForUnknownType()

	/**
	 * addLink succeeds for a type that the registry knows.
	 *
	 * Uses a real ObjectEntity (magic __call setters/getters) to verify the
	 * column name is resolved as $type directly (no TYPE_COLUMN_MAP lookup).
	 *
	 * @covers \OCA\OpenRegister\Service\LinkedEntityService::addLink
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-2
	 */
	public function testAddLinkSucceedsForRegisteredType(): void {
		$this->registry
			->method('listIds')
			->willReturn(['notes']);

		$object = new ObjectEntity();
		// SEC-CTRL-4: addLink() now resolves the object's schema and runs the
		// canonical 'update' RBAC check before mutating link columns. Give the
		// object a schema and let schemaMapper resolve it; the mocked
		// PermissionHandler permits the write (checkPermission() returns void).
		$object->setSchema('schema-1');

		$schema = $this->createMock(Schema::class);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->magicMapper->method('find')->willReturn($object);
		$this->magicMapper->expects($this->once())->method('update');

		$result = $this->service->addLink(objectUuid: 'obj-uuid', type: 'notes', entityId: 'note-1');
		$this->assertSame(['note-1'], $result);
	}//end testAddLinkSucceedsForRegisteredType()

	/**
	 * removeLink throws for an unregistered type.
	 *
	 * @covers \OCA\OpenRegister\Service\LinkedEntityService::removeLink
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-2
	 */
	public function testRemoveLinkThrowsForUnknownType(): void {
		$this->registry->method('listIds')->willReturn(['mail']);

		$this->expectException(Exception::class);
		$this->service->removeLink(objectUuid: 'u', type: 'invalid', entityId: 'e');
	}//end testRemoveLinkThrowsForUnknownType()

	/**
	 * validateType error message lists registry ids (not a hardcoded fallback list).
	 *
	 * @covers \OCA\OpenRegister\Service\LinkedEntityService
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-2
	 */
	public function testValidateTypeErrorListsRegistryIds(): void {
		$this->registry->method('listIds')->willReturn(['files', 'notes', 'mail']);

		try {
			$this->service->addLink(objectUuid: 'u', type: 'unknown', entityId: 'e');
			$this->fail('Expected Exception was not thrown');
		} catch (Exception $e) {
			$this->assertStringContainsString('files', $e->getMessage());
			$this->assertStringContainsString('notes', $e->getMessage());
			$this->assertStringContainsString('mail', $e->getMessage());
		}
	}//end testValidateTypeErrorListsRegistryIds()

	/**
	 * reverseLookup validates type via registry before scanning.
	 *
	 * @covers \OCA\OpenRegister\Service\LinkedEntityService::reverseLookup
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-2
	 */
	public function testReverseLookupThrowsForUnknownType(): void {
		$this->registry->method('listIds')->willReturn(['mail']);

		$this->expectException(Exception::class);
		$this->service->reverseLookup(type: 'nope', entityId: 'e-1');
	}//end testReverseLookupThrowsForUnknownType()
}//end class
