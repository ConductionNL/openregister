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
 * Regression: a PENDING schema ref left by a previous caller must not be
 * re-resolved inside the register a LATER find() names.
 */
class ObjectServiceStaleSchemaRefTest extends TestCase {

	/** @var QueryHandler&MockObject */
	private QueryHandler $queryHandler;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	private ObjectService $service;

	/**
	 * Build an ObjectService with every dependency mocked except the mappers.
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
	 * THE BUG, AS IT PRESENTED ON THE DEV INSTANCE.
	 *
	 * `setSchema()` remembers its RAW ref so that a later `setRegister()` can
	 * re-resolve it inside the register the caller names — that is what makes
	 * `setSchema()->setRegister()` and `setRegister()->setSchema()` agree.
	 *
	 * But the pending ref is instance state on a SHARED service, and `find()`
	 * calls `setRegister()` BEFORE it sets its own schema. So a ref left behind
	 * by an unrelated earlier caller gets re-resolved inside THIS call's
	 * register — a register that has nothing to do with it — and the call dies
	 * on a schema it never asked for.
	 *
	 * Observed: every `openconnector` object read on the instance failed with
	 * `Schema slug "application" is not carried by register "openconnector"`,
	 * while the caller had asked for `synchronization`. The name in the error
	 * belongs to a previous caller.
	 *
	 * `find()` already restores `currentRegister`/`currentSchema` and clears the
	 * pending ref in its `finally` (BUG-OBJ-13). This is the same isolation,
	 * missing at the OTHER end: entering the call.
	 *
	 * @return void
	 */
	public function testAPendingSchemaRefFromAPreviousCallerIsNotResolvedInThisCallsRegister(): void {
		// A previous, unrelated caller anchored the shared service on a schema
		// by SLUG and never called find(), so the pending ref is still set.
		$appSchema = new Schema();
		$appSchema->setId(28);
		$appSchema->setSlug('application');

		$this->schemaMapper->method('find')->willReturn($appSchema);
		$this->service->setSchema('application');

		// Now an unrelated caller reads an object in a register that does NOT
		// carry `application` — the shape of every openconnector read.
		$register = new Register();
		$register->setId(65);
		$register->setSlug('openconnector');
		$register->setSchemas([221]);

		$synchronization = new Schema();
		$synchronization->setId(221);
		$synchronization->setSlug('synchronization');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findBySlugInIds')->willReturnCallback(
			static function (string $slug, array $ids) use ($synchronization) {
				return ($slug === 'synchronization') ? $synchronization : null;
			}
		);
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			static function ($ref, array $ids) use ($synchronization) {
				return ($ref === 'synchronization' || $ref === 221) ? $synchronization : null;
			}
		);

		// BEFORE THE FIX this throws SchemaNotInRegisterException naming
		// "application" — a schema this call never mentioned.
		$this->service->find(
			id: 'c3dce9e3-86a8-44d7-98f6-51c4a06f4b31',
			register: 'openconnector',
			schema: 'synchronization',
			_rbac: false,
			_multitenancy: false
		);

		$this->addToAssertionCount(1);

	}//end testAPendingSchemaRefFromAPreviousCallerIsNotResolvedInThisCallsRegister()
}//end class
