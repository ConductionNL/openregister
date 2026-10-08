<?php

/**
 * Unit tests for the cache invalidation after a bulk save.
 *
 * `ObjectService::saveObjects()` invalidated the list cache only when the
 * handler's statistics named `objectsCreated` / `objectsUpdated`. SaveObjects
 * has never returned those keys: its statistics count `saved` and `updated`.
 * So the sum was always 0, nothing was invalidated, and a list read after a
 * bulk write served the rows from before it. Measured on the import preview:
 * the commit reported `applied: 3` while the list still showed the old rows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions and mock builders.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
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

class ObjectServiceBulkSaveCacheTest extends TestCase {

	/**
	 * The bulk handler the service delegates to.
	 *
	 * @var SaveObjects&MockObject
	 */
	private SaveObjects $saveObjectsHandler;

	/**
	 * The cache the service must invalidate.
	 *
	 * @var CacheHandler&MockObject
	 */
	private CacheHandler $cacheHandler;

	/**
	 * The service under test.
	 *
	 * @var ObjectService
	 */
	private ObjectService $service;

	/**
	 * The register the bulk save names.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The schema the bulk save names.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build an ObjectService over mocks, keeping the cache handler in reach.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Schema();
		$this->schema->setId(137);
		$this->schema->setSlug('import-target');

		$this->register = new Register();
		$this->register->setId(97);
		$this->register->setSlug('import-register');
		$this->register->setSchemas([137]);

		$this->saveObjectsHandler = $this->createMock(SaveObjects::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);

		$this->service = new ObjectService(
			dataManipHandler: $this->createMock(DataManipulationHandler::class),
			deleteHandler: $this->createMock(DeleteObject::class),
			getHandler: $this->createMock(GetObject::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			renderHandler: $this->createMock(RenderObject::class),
			saveHandler: $this->createMock(SaveObject::class),
			saveObjectsHandler: $this->saveObjectsHandler,
			searchQueryHandler: $this->createMock(SearchQueryHandler::class),
			validateHandler: $this->createMock(ValidateObject::class),
			lockHandler: $this->createMock(LockHandler::class),
			auditHandler: $this->createMock(AuditHandler::class),
			relationHandler: $this->createMock(RelationHandler::class),
			mergeHandler: $this->createMock(MergeHandler::class),
			facetHandler: $this->createMock(FacetHandler::class),
			metadataHandler: $this->createMock(MetadataHandler::class),
			perfOptHandler: $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler: $this->createMock(QueryHandler::class),
			revertHandler: $this->createMock(RevertHandler::class),
			utilityHandler: $this->createMock(UtilityHandler::class),
			validationHandler: $this->createMock(ValidationHandler::class),
			cascadingHandler: $this->createMock(CascadingHandler::class),
			migrationHandler: $this->createMock(MigrationHandler::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			viewMapper: $this->createMock(ViewMapper::class),
			objectMapper: $this->createMock(MagicMapper::class),
			fileService: $this->createMock(FileService::class),
			userSession: $this->createMock(IUserSession::class),
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $this->cacheHandler,
			settingsService: $this->createMock(SettingsService::class),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			container: $this->createMock(IAppContainer::class),
			objectSourceRegistry: $this->createMock(ObjectSourceRegistry::class)
		);
	}//end setUp()

	/**
	 * The statistics SaveObjects really returns, with the given counts.
	 *
	 * The shape is SaveObjects::initializeSaveResult(), not an invented one: a
	 * fixture with the keys the consumer hoped for is how this went unnoticed.
	 *
	 * @param int $saved   Rows created.
	 * @param int $updated Rows updated.
	 *
	 * @return array<string, mixed> The bulk result.
	 */
	private function bulkResult(int $saved, int $updated): array {
		return [
			'saved' => [],
			'updated' => [],
			'unchanged' => [],
			'invalid' => [],
			'errors' => [],
			'statistics' => [
				'totalProcessed' => ($saved + $updated),
				'saved' => $saved,
				'updated' => $updated,
				'unchanged' => 0,
				'invalid' => 0,
				'errors' => 0,
				'processingTimeMs' => 0,
			],
		];
	}//end bulkResult()

	/**
	 * A bulk save that created rows invalidates the cache for its scope.
	 *
	 * @return void
	 */
	public function testABulkSaveThatWroteRowsInvalidatesTheListCache(): void {
		$this->saveObjectsHandler->method('saveObjects')->willReturn($this->bulkResult(saved: 1, updated: 2));

		$this->cacheHandler->expects($this->once())
			->method('invalidateForObjectChange')
			->with(null, 'bulk_save', 97, 137);

		$this->service->saveObjects(
			objects: [['bsn' => '111'], ['bsn' => '222'], ['bsn' => '333']],
			register: $this->register,
			schema: $this->schema
		);
	}//end testABulkSaveThatWroteRowsInvalidatesTheListCache()

	/**
	 * A bulk save that wrote nothing leaves the cache alone.
	 *
	 * The control for the test above: without it, an implementation that
	 * invalidated on every call would pass too.
	 *
	 * @return void
	 */
	public function testABulkSaveThatWroteNothingDoesNotInvalidate(): void {
		$this->saveObjectsHandler->method('saveObjects')->willReturn($this->bulkResult(saved: 0, updated: 0));

		$this->cacheHandler->expects($this->never())->method('invalidateForObjectChange');

		$this->service->saveObjects(
			objects: [['bsn' => '111']],
			register: $this->register,
			schema: $this->schema
		);
	}//end testABulkSaveThatWroteNothingDoesNotInvalidate()
}//end class
