<?php

/**
 * A saved view backs a read-only record type (modelling-query-backed-type).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ReadOnlyTypeException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\ObjectSource\ViewObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The Schema, View, Register entities and the provider are real. MagicMapper
 * is the double: the provider's job is to hand it the view's query against the
 * view's source table, and that hand-over is what is asserted.
 */
class ViewBackedTypeTest extends TestCase {

	/**
	 * A schema backed by the "active permits" view, built the way an import builds it.
	 *
	 * @return Schema
	 */
	private function viewBackedSchema(): Schema {
		$schema = new Schema();
		$schema->setId(40);
		$schema->setSlug('active-permit');
		$schema->setHardValidation(false);
		$schema->hydrate(object: ['title' => 'Active permit', 'slug' => 'active-permit', 'x-openregister-view' => ['view' => 'active-permits']]);

		return $schema;
	}//end viewBackedSchema()

	/**
	 * The provider over a real View whose query names one source table.
	 *
	 * @param MagicMapper&MockObject $magic The mapper double.
	 *
	 * @return ViewObjectSourceProvider
	 */
	private function provider(MagicMapper $magic): ViewObjectSourceProvider {
		$view = new View();
		$view->setUuid('active-permits');
		$view->setQuery(
			[
				'registers' => [3],
				'schemas' => [5],
				'searchTerms' => ['north'],
				'facetFilters' => ['status' => ['active'], 'district' => []],
			]
		);

		$views = $this->createMock(ViewMapper::class);
		$views->method('find')->willReturnCallback(
			fn ($id, bool $_rbac = true, bool $_multitenancy = true) => ($id === 'active-permits') ? $view : throw new \OCP\AppFramework\Db\DoesNotExistException('no view')
		);

		$sourceRegister = new Register();
		$sourceRegister->setId(3);
		$sourceSchema = new Schema();
		$sourceSchema->setId(5);
		$sourceSchema->setSlug('permit');

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($sourceRegister);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($sourceSchema);

		return new ViewObjectSourceProvider($views, $registers, $schemas, $magic, $this->createMock(LoggerInterface::class));
	}//end provider()

	public function testTheSchemaDeclaresAViewAsItsReadOnlySource(): void {
		$source = $this->viewBackedSchema()->getObjectSource();

		$this->assertSame('view', $source['provider']);
		$this->assertSame(['view' => 'active-permits'], $source['config']);
		$this->assertTrue($source['readOnly']);
	}//end testTheSchemaDeclaresAViewAsItsReadOnlySource()

	public function testTheTypeListsWhatTheQueryFinds(): void {
		$magic = $this->createMock(MagicMapper::class);
		$permit = new ObjectEntity();
		$permit->setUuid('p-1');

		$magic->expects($this->once())
			->method('searchObjectsInRegisterSchemaTable')
			->with(
				$this->callback(
					function (array $query): bool {
						$this->assertSame(['active'], $query['status']);
						$this->assertArrayNotHasKey('district', $query, 'an empty facet is no filter');
						$this->assertSame('north', $query['_search']);
						$this->assertSame(10, $query['_limit']);
						$this->assertSame(20, $query['_offset']);
						$this->assertTrue($query['_rbac'], 'the reader\'s access to the source rows still applies');
						return true;
					}
				),
				$this->callback(fn (Register $register): bool => $register->getId() === 3),
				$this->callback(fn (Schema $schema): bool => $schema->getId() === 5)
			)
			->willReturn([$permit]);

		$rows = $this->provider($magic)->findAll(
			register: new Register(),
			schema: $this->viewBackedSchema(),
			query: ['limit' => 10, 'offset' => 20],
			config: ['view' => 'active-permits']
		);

		$this->assertSame([$permit], $rows);
	}//end testTheTypeListsWhatTheQueryFinds()

	public function testACallerFilterNarrowsTheViewAndCannotWidenIt(): void {
		$magic = $this->createMock(MagicMapper::class);
		$magic->expects($this->never())->method('searchObjectsInRegisterSchemaTable');

		// status=expired is outside the view's status=active: nothing can match.
		$rows = $this->provider($magic)->findAll(
			register: new Register(),
			schema: $this->viewBackedSchema(),
			query: ['status' => 'expired'],
			config: ['view' => 'active-permits']
		);

		$this->assertSame([], $rows);
	}//end testACallerFilterNarrowsTheViewAndCannotWidenIt()

	public function testTheCountIsTheQueryCount(): void {
		$magic = $this->createMock(MagicMapper::class);
		$magic->expects($this->once())
			->method('countObjectsInRegisterSchemaTable')
			->with($this->callback(fn (array $q): bool => $q['status'] === ['active'] && isset($q['_limit']) === false))
			->willReturn(7);

		$this->assertSame(7, $this->provider($magic)->count(register: new Register(), schema: $this->viewBackedSchema(), query: ['limit' => 5], config: ['view' => 'active-permits']));
	}//end testTheCountIsTheQueryCount()

	/**
	 * ObjectService with the collaborators AppendOnlyTest uses.
	 *
	 * @param SaveObject&MockObject   $save   The save handler.
	 * @param DeleteObject&MockObject $delete The delete handler.
	 *
	 * @return ObjectService
	 */
	private function objectService(SaveObject $save, DeleteObject $delete): ObjectService {
		$cascading = $this->createMock(CascadingHandler::class);
		$cascading->method('handlePreValidationCascading')->willReturnCallback(
			static fn (array $obj, mixed $schema, ?string $uuid, ?int $register): array => [$obj, $uuid]
		);

		$service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$delete,
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->createMock(RenderObject::class),
			$save,
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(ValidateObject::class),
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$cascading,
			$this->createMock(MigrationHandler::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		);

		$prop = (new ReflectionClass(ObjectService::class))->getProperty('currentSchema');
		$prop->setAccessible(true);
		$prop->setValue($service, $this->viewBackedSchema());

		return $service;
	}//end objectService()

	public function testCreatingAnObjectOfTheTypeIsRefusedAs405(): void {
		$save = $this->createMock(SaveObject::class);
		$save->expects($this->never())->method('saveObject');

		try {
			$this->objectService($save, $this->createMock(DeleteObject::class))->saveObject(object: ['name' => 'x'], uuid: null);
			$this->fail('a view-backed type must refuse a create');
		} catch (ReadOnlyTypeException $e) {
			// The controllers map the append-only family to 405; this is one of it.
			$this->assertInstanceOf(AppendOnlyException::class, $e);
			$this->assertSame(405, $e->getCode());
			$this->assertSame('SCHEMA_READ_ONLY', $e->toResponseBody()['error']);
			$this->assertSame('create', $e->getOperation());
		}
	}//end testCreatingAnObjectOfTheTypeIsRefusedAs405()

	public function testDeletingAnObjectOfTheTypeIsRefused(): void {
		$delete = $this->createMock(DeleteObject::class);
		$delete->expects($this->never())->method('deleteObject');

		$this->expectException(ReadOnlyTypeException::class);

		$this->objectService($this->createMock(SaveObject::class), $delete)->deleteObject(uuid: 'p-1');
	}//end testDeletingAnObjectOfTheTypeIsRefused()
}//end class
