<?php

/**
 * ObjectService searchObjectsBySlug Unit Tests
 *
 * Covers the runtime-schema-api spec requirement:
 * "ObjectService.searchObjectsBySlug resolves slugs at the slug-aware layer"
 *
 * Tests in this file are SPEC-SPECIFIC for change `openregister-runtime-schema-api`
 * and do NOT overlap with the broader ObjectService test suite.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

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
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ObjectService::searchObjectsBySlug — spec REQ
 * "ObjectService.searchObjectsBySlug resolves slugs at the slug-aware layer".
 */
class ObjectServiceSearchBySlugTest extends TestCase {

	/** @var QueryHandler&MockObject */
	private QueryHandler $queryHandler;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	private ObjectService $service;

	/**
	 * Build an ObjectService with every dependency mocked except the ones
	 * the test directly exercises (RegisterMapper, SchemaMapper, QueryHandler).
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->queryHandler = $this->createMock(QueryHandler::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->service = new ObjectService(
			dataManipHandler:    $this->createMock(DataManipulationHandler::class),
			deleteHandler:       $this->createMock(DeleteObject::class),
			getHandler:          $this->createMock(GetObject::class),
			permissionHandler:   $this->createMock(PermissionHandler::class),
			renderHandler:       $this->createMock(RenderObject::class),
			saveHandler:         $this->createMock(SaveObject::class),
			saveObjectsHandler:  $this->createMock(SaveObjects::class),
			searchQueryHandler:  $this->createMock(SearchQueryHandler::class),
			validateHandler:     $this->createMock(ValidateObject::class),
			lockHandler:         $this->createMock(LockHandler::class),
			auditHandler:        $this->createMock(AuditHandler::class),
			relationHandler:     $this->createMock(RelationHandler::class),
			mergeHandler:        $this->createMock(MergeHandler::class),
			facetHandler:        $this->createMock(FacetHandler::class),
			metadataHandler:     $this->createMock(MetadataHandler::class),
			perfOptHandler:      $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler:        $this->queryHandler,
			revertHandler:       $this->createMock(RevertHandler::class),
			utilityHandler:      $this->createMock(UtilityHandler::class),
			validationHandler:   $this->createMock(ValidationHandler::class),
			cascadingHandler:    $this->createMock(CascadingHandler::class),
			migrationHandler:    $this->createMock(MigrationHandler::class),
			registerMapper:      $this->registerMapper,
			schemaMapper:        $this->schemaMapper,
			viewMapper:          $this->createMock(ViewMapper::class),
			objectMapper:        $this->createMock(MagicMapper::class),
			fileService:         $this->createMock(FileService::class),
			userSession:         $this->createMock(IUserSession::class),
			searchTrailService:  $this->createMock(SearchTrailService::class),
			groupManager:        $this->createMock(IGroupManager::class),
			userManager:         $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger:              $this->createMock(LoggerInterface::class),
			cacheHandler:        $this->createMock(CacheHandler::class),
			settingsService:     $this->createMock(SettingsService::class),
			dateTimeNormalizer:  $this->createMock(DateTimeNormalizer::class),
			container:           $this->createMock(IAppContainer::class),
			objectSourceRegistry: $this->createMock(ObjectSourceRegistry::class)
		);

	}//end setUp()

	/**
	 * REQ: searchObjectsBySlug resolves both slugs and delegates to searchObjects
	 * with numeric @self.register / @self.schema.
	 *
	 * Scenario: "Search by slug-pair" — the query handed to the QueryHandler
	 * MUST contain the numeric IDs derived from the mappers, with extra
	 * filter keys preserved at the top level.
	 */
	public function testSearchObjectsBySlugResolvesAndDelegates(): void {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('openbuild');
		// The register MUST carry the schema. This used to be left unset, and the
		// test passed only because resolution fell through to the global
		// SchemaMapper::find() — i.e. the assertion was green *because of* the
		// defect. openspec/changes/register-scoped-slug-resolution.
		$register->setSchemas([42]);

		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('application');

		$this->registerMapper
			->expects($this->once())
			->method('find')
			->with($this->equalTo('openbuild'))
			->willReturn($register);

		$this->schemaMapper
			->expects($this->once())
			->method('findBySlugInIds')
			->with($this->equalTo('application'), $this->equalTo([42]))
			->willReturn($schema);

		// The global resolver MUST NOT be reached: the caller named a register.
		$this->schemaMapper->expects($this->never())->method('find');

		$this->queryHandler
			->expects($this->once())
			->method('searchObjects')
			->with(
				$this->callback(function (array $query): bool {
					// Numeric IDs MUST land in @self, not slugs.
					return ($query['@self']['register'] ?? null) === 7
						&& ($query['@self']['schema'] ?? null) === 42
						&& ($query['status'] ?? null) === 'published';
				})
			)
			->willReturn([]);

		$result = $this->service->searchObjectsBySlug(
			'openbuild',
			'application',
			['status' => 'published']
		);

		$this->assertSame([], $result);

	}//end testSearchObjectsBySlugResolvesAndDelegates()

	/**
	 * REQ: Unknown register slug throws DoesNotExistException with a message
	 * that identifies which slug failed.
	 */
	public function testSearchObjectsBySlugThrowsWhenRegisterSlugUnknown(): void {
		$this->registerMapper
			->expects($this->once())
			->method('find')
			->with($this->equalTo('ghost-register'))
			->willThrowException(new DoesNotExistException('not found'));

		// The schema mapper must NOT be hit when the register lookup already failed.
		$this->schemaMapper->expects($this->never())->method('find');

		// The query handler MUST NOT be hit either.
		$this->queryHandler->expects($this->never())->method('searchObjects');

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessageMatches(
			'/searchObjectsBySlug: register slug not found.*ghost-register/'
		);

		$this->service->searchObjectsBySlug(
			'ghost-register',
			'application',
			[]
		);

	}//end testSearchObjectsBySlugThrowsWhenRegisterSlugUnknown()

	/**
	 * REQ: A schema slug the named register does not carry is REFUSED — never
	 * resolved globally.
	 *
	 * This test previously asserted the opposite: that resolution fell through to
	 * `SchemaMapper::find()`, whose failure was rewrapped. That fallback is the
	 * defect. openspec/changes/register-scoped-slug-resolution.
	 */
	public function testSearchObjectsBySlugRefusesSchemaSlugOutsideTheRegister(): void {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('openbuild');
		$register->setSchemas([42]);

		$this->registerMapper
			->expects($this->once())
			->method('find')
			->willReturn($register);

		$this->schemaMapper
			->expects($this->once())
			->method('findBySlugInIds')
			->with($this->equalTo('ghost-schema'), $this->equalTo([42]))
			->willReturn(null);

		// Three same-slug schemas exist elsewhere. The count reaches the message so
		// the operator is not told "your slug is wrong" when copies demonstrably exist.
		$this->schemaMapper
			->expects($this->once())
			->method('countBySlug')
			->with($this->equalTo('ghost-schema'))
			->willReturn(3);

		// The global resolver MUST NOT be consulted.
		$this->schemaMapper->expects($this->never())->method('find');
		$this->queryHandler->expects($this->never())->method('searchObjects');

		$this->expectException(SchemaNotInRegisterException::class);
		$this->expectExceptionMessageMatches(
			'/"ghost-schema" is not carried by register "openbuild" \(id 7\).*3 schema\(s\) elsewhere/s'
		);

		$this->service->searchObjectsBySlug(
			'openbuild',
			'ghost-schema',
			[]
		);

	}//end testSearchObjectsBySlugRefusesSchemaSlugOutsideTheRegister()

	/**
	 * REQ: A register carrying NO schemas refuses every slug, with a message that
	 * names the repair command.
	 *
	 * This is the shape that bit DocuDesk: register `document` (id 6) had an empty
	 * schemas list while nine same-slug schemas existed, so the old fallback
	 * resolved to a schema with no table under that register — returning an EMPTY
	 * result set indistinguishable from "this register holds no objects".
	 */
	public function testSearchObjectsBySlugRefusesWhenRegisterCarriesNoSchemas(): void {
		$register = new Register();
		$register->setId(6);
		$register->setSlug('document');
		$register->setSchemas([]);

		$this->registerMapper
			->expects($this->once())
			->method('find')
			->willReturn($register);

		$this->schemaMapper
			->expects($this->once())
			->method('findBySlugInIds')
			->with($this->equalTo('anonymizationLink'), $this->equalTo([]))
			->willReturn(null);

		$this->schemaMapper
			->expects($this->once())
			->method('countBySlug')
			->willReturn(9);

		$this->schemaMapper->expects($this->never())->method('find');
		$this->queryHandler->expects($this->never())->method('searchObjects');

		$this->expectException(SchemaNotInRegisterException::class);
		$this->expectExceptionMessageMatches(
			'/carries no schemas at all.*9 schema\(s\) elsewhere.*relink-schemas/s'
		);

		$this->service->searchObjectsBySlug(
			'document',
			'anonymizationLink',
			[]
		);

	}//end testSearchObjectsBySlugRefusesWhenRegisterCarriesNoSchemas()

}//end class
