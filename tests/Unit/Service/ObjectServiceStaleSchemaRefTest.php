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

	/**
	 * THE SAME LEAK, VIA findAll() — the shape that broke a flow run.
	 *
	 * `find()` was guarded first (#2790) and the ref was made single-use
	 * (#2792), but single-use only means the leaked ref claims ONE victim
	 * instead of many: it is still handed to the FIRST `setRegister()` that
	 * follows, whoever that is.
	 *
	 * Measured on development 2026-08-22: an unrelated operation left a
	 * `synchronization_contract` ref behind, and a flow's `object-write` — which
	 * names its OWN register and schema — was refused with
	 * `Schema slug "synchronization_contract" is not carried by register
	 * "fns-…-reg"`. A register that had never heard of that slug, named in an
	 * error for a call that never mentioned it.
	 *
	 * @return void
	 */
	public function testALeakedRefDoesNotRefuseAnOperationThatNamesItsOwnScope(): void {
		$contract = new Schema();
		$contract->setId(222);
		$contract->setSlug('synchronization_contract');

		$this->schemaMapper->method('find')->willReturn($contract);
		// An unrelated operation anchors on a schema by slug and never sets a
		// register — exactly what leaves a ref pending.
		$this->service->setSchema('synchronization_contract');

		// A flow now writes into ITS OWN register, naming both halves.
		$flowRegister = new Register();
		$flowRegister->setId(15);
		$flowRegister->setSlug('fns-run-reg');
		$flowRegister->setSchemas([9001]);

		$target = new Schema();
		$target->setId(9001);
		$target->setSlug('target');

		$this->registerMapper->method('find')->willReturn($flowRegister);
		$this->schemaMapper->method('findBySlugInIds')->willReturnCallback(
			static fn (string $slug, array $ids) => ($slug === 'target') ? $target : null
		);
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			static fn ($ref, array $ids) => ($ref === 'target' || $ref === 9001) ? $target : null
		);

		// BEFORE THIS FIX: SchemaNotInRegisterException naming
		// "synchronization_contract" inside "fns-run-reg". findAll() is the
		// lighter of the two paths and is the one openconnector's contract
		// lookup actually takes (SynchronizationService passes register+schema
		// inside `filters`, which prepareFindAllConfig turns into
		// setRegister()->setSchema()).
		$this->service->findAll(
			config: ['filters' => ['register' => 'fns-run-reg', 'schema' => 'target']]
		);

		$this->addToAssertionCount(1);

	}//end testALeakedRefDoesNotRefuseAnOperationThatNamesItsOwnScope()

	/**
	 * The same leak, on the WRITE path — which find() never covered.
	 *
	 * find() clears the pending ref itself. saveObject() and patchObject() do
	 * not go through find(): they call setContextFromParameters(), which was
	 * left unguarded, so the ref survived into the write path and was consumed
	 * by the setRegister() of an operation that never mentioned it.
	 *
	 * MEASURED on buildiq's E2E 2026-08-22 12:26 UTC, AFTER the single-use fix
	 * landed at 11:09:
	 *
	 *     POST /apps/docudesk/api/templates
	 *     Failed to create template: Schema slug "application" is not carried by
	 *     register "docudesk" (id 19) …
	 *
	 * Docudesk asked for register 19 / schema 18 (`template`). `application` is
	 * openbuild's schema, left pending by an unrelated earlier call on the same
	 * shared ObjectService instance.
	 *
	 * Driven through the private helper by reflection rather than through
	 * saveObject(): the leak lives entirely in context resolution, and the rest
	 * of a save needs a dozen more collaborators that would obscure what is
	 * being asserted.
	 *
	 * @return void
	 */
	public function testAPendingSchemaRefDoesNotLeakIntoAWriteThatNamesBoth(): void {
		// An earlier, unrelated caller anchored the shared service on a slug.
		$appSchema = new Schema();
		$appSchema->setId(28);
		$appSchema->setSlug('application');

		$this->schemaMapper->method('find')->willReturn($appSchema);
		$this->service->setSchema('application');

		// Now a write names BOTH sides outright — docudesk's own pair.
		$register = new Register();
		$register->setId(19);
		$register->setSlug('docudesk');
		$register->setSchemas([18]);

		$template = new Schema();
		$template->setId(18);
		$template->setSlug('template');

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			static function ($ref, array $ids) use ($template) {
				return ((int) $ref === 18) ? $template : null;
			}
		);

		$setContext = new \ReflectionMethod($this->service, 'setContextFromParameters');
		$setContext->setAccessible(true);

		// BEFORE THE FIX this throws SchemaNotInRegisterException naming
		// "application" — a schema this write never mentioned.
		$setContext->invoke($this->service, 19, 18);

		$schema = new \ReflectionProperty($this->service, 'currentSchema');
		$schema->setAccessible(true);

		$this->assertSame(
			18,
			$schema->getValue($this->service)?->getId(),
			'the write must resolve the schema it named, not the one left pending'
		);

	}//end testAPendingSchemaRefDoesNotLeakIntoAWriteThatNamesBoth()


	/**
	 * setSchema() BEFORE setRegister() still resolves inside that register.
	 *
	 * openregister#2786. Roughly 30 call sites across 26 controllers set the
	 * schema first and the register second — the copy-pasted `validateObject()`
	 * helper in the link controllers, plus ObjectsController and TagsController.
	 * With no register in context yet, `setSchema()` on a slug can only match
	 * globally, and the observed cost was concrete: `task` resolved to
	 * openbuild's schema on a planix request and returned 500.
	 *
	 * The fix belonged in ObjectService, not in 30 hand-corrected call sites:
	 * `setRegister()` re-resolves the pending ref, so the boundary holds
	 * whichever way round the two setters are called. This test asserts that
	 * property directly rather than trusting the comment that describes it —
	 * a global find() is wired to return the WRONG app's schema, so an
	 * implementation that skipped the re-resolution would keep it and fail here.
	 *
	 * @return void
	 */
	public function testSchemaSetBeforeRegisterStillResolvesInsideThatRegister(): void {
		// What a GLOBAL slug match returns: another app's `task`.
		$foreignTask = new Schema();
		$foreignTask->setId(74);
		$foreignTask->setSlug('task');

		// What this register actually carries under the same slug.
		$ownTask = new Schema();
		$ownTask->setId(9477);
		$ownTask->setSlug('task');

		$register = new Register();
		$register->setId(19);
		$register->setSlug('planix');
		$register->setSchemas([9477]);

		$this->schemaMapper->method('find')->willReturn($foreignTask);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findBySlugInIds')->willReturnCallback(
			static function (string $slug, array $ids) use ($ownTask) {
				return ($slug === 'task' && in_array(9477, $ids, true)) ? $ownTask : null;
			}
		);
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			static function ($ref, array $ids) use ($ownTask) {
				return ($ref === 'task' || $ref === 9477) ? $ownTask : null;
			}
		);

		// The controller ordering, verbatim.
		$this->service->setSchema('task');
		$this->service->setRegister('planix');

		$schema = new \ReflectionProperty(ObjectService::class, 'currentSchema');
		$schema->setAccessible(true);

		$this->assertSame(
			9477,
			$schema->getValue($this->service)?->getId(),
			'schema-before-register must resolve within the register, not globally'
		);
	}//end testSchemaSetBeforeRegisterStillResolvesInsideThatRegister()


	/**
	 * A BARE setRegister() must survive a ref that belongs to somebody else.
	 *
	 * Every guard before this one protects an OPERATION — `find()`, `findAll()`,
	 * a save — by discarding the pending ref as it enters. `setRegister()` is
	 * not an operation, so it has no such guard: it takes whatever ref is
	 * pending, re-resolves it inside the register the caller just named, and a
	 * miss THROWS out of a method whose caller only asked to name its register.
	 *
	 * Measured on the development instance 2026-08-27, WITH #2918 (the read-path
	 * clear) already merged and running: every public read of a portaliq portal
	 * failed with `Schema slug "application" is not carried by register
	 * "portaliq"`. `PortalResolver` calls `setRegister('portaliq')` first and
	 * `setSchema('portal')` after — the correct order — and never mentions
	 * `application` at all. The portal served its shell and answered 404 for its
	 * own site, menus, pages and glossary: a whole app's public surface down,
	 * over a slug it does not own.
	 *
	 * So the ref is DROPPED here rather than thrown on. The schema context is
	 * cleared with it, which is the half that keeps this honest: a caller that
	 * genuinely chained `setSchema('typo')->setRegister($r)` still fails, at its
	 * operation, with a missing schema context — rather than quietly reading
	 * whichever table a stale context last pointed at.
	 *
	 * @return void
	 */
	public function testABareSetRegisterSurvivesAForeignPendingRef(): void {
		// Left behind by an unrelated caller — buildiq registers navigation from
		// Application::boot(), so this ref is pending on EVERY request.
		$application = new Schema();
		$application->setId(193);
		$application->setSlug('application');
		$this->schemaMapper->method('find')->willReturn($application);
		$this->service->setSchema('application');

		// A different app now names its OWN register. It does not carry
		// `application`, and it never asked for it.
		$portaliq = new Register();
		$portaliq->setId(35);
		$portaliq->setSlug('portaliq');
		$portaliq->setSchemas([501]);

		$portal = new Schema();
		$portal->setId(501);
		$portal->setSlug('portal');

		$this->registerMapper->method('find')->willReturn($portaliq);
		$this->schemaMapper->method('findBySlugInIds')->willReturnCallback(
			static fn (string $slug, array $ids) => ($slug === 'portal' ? $portal : null)
		);
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			static fn ($ref, array $ids) => (($ref === 'portal' || $ref === 501) ? $portal : null)
		);

		// BEFORE THE FIX this throws SchemaNotInRegisterException naming
		// "application" — and took every public page of the portal with it.
		$this->service->setRegister('portaliq');

		$schema = new \ReflectionProperty(ObjectService::class, 'currentSchema');
		$schema->setAccessible(true);
		$this->assertNull(
			$schema->getValue($this->service),
			'a ref that could not be resolved must leave NO schema context — substituting a '
			.'wrong-but-plausible schema is the one outcome worse than throwing'
		);

		// And the caller's own chain still works, which is the whole point:
		// this is the order PortalResolver and CmsReader use.
		$this->service->setSchema('portal');
		$this->assertSame(
			501,
			$schema->getValue($this->service)?->getId(),
			'the caller naming its own register and schema must be served its own schema'
		);

		$ref = new \ReflectionProperty(ObjectService::class, 'currentSchemaRef');
		$ref->setAccessible(true);
		$this->assertNull(
			$ref->getValue($this->service),
			'the foreign ref must be consumed, not left pending for the NEXT caller'
		);
	}//end testABareSetRegisterSurvivesAForeignPendingRef()


	/**
	 * A COMPLETED register+schema chain must not leave a ref behind at all.
	 *
	 * Every case above starts from `setSchema($s)` with NO register set — the
	 * ref is pending precisely because it could not be resolved yet. This one
	 * starts from the opposite and far more common order, `setRegister($r)`
	 * THEN `setSchema($s)`, which is what `prepareFindAllConfig()` does on
	 * every `findAll()`. That path resolves the schema immediately inside the
	 * register and returns early — and used to return with the ref still set,
	 * even though nothing was left to resolve.
	 *
	 * The consequence is not theoretical. Measured 2026-08-27 on a fresh
	 * instance: buildiq registers its navigation entries from
	 * `Application::boot()`, so this exact chain ran on EVERY request with
	 * `application`, and every request ended with that ref pending. The next
	 * caller to name its own register — portaliq's PortalResolver — was refused
	 * with `Schema slug "application" is not carried by register "portaliq"`,
	 * fails closed by design, and every public portal page 404'd.
	 *
	 * #2803 cleared the ref on the save path; this is the read path.
	 *
	 * @return void
	 */
	public function testACompletedRegisterThenSchemaChainLeavesNoPendingRef(): void {
		$buildiq = new Register();
		$buildiq->setId(20);
		$buildiq->setSlug('buildiq');
		$buildiq->setSchemas([193]);

		$application = new Schema();
		$application->setId(193);
		$application->setSlug('application');

		$portaliq = new Register();
		$portaliq->setId(35);
		$portaliq->setSlug('portaliq');
		// Deliberately does NOT carry `application` — that is the whole point.
		$portaliq->setSchemas([705]);

		$this->registerMapper->method('find')->willReturnCallback(
			static function ($ref) use ($buildiq, $portaliq) {
				return ($ref === 'portaliq' || $ref === 35) ? $portaliq : $buildiq;
			}
		);
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			static function ($ref, array $ids) use ($application) {
				return (in_array(193, $ids, true) === true && ($ref === 'application' || $ref === 193))
					? $application
					: null;
			}
		);

		// The chain prepareFindAllConfig() runs: register first, then schema.
		$this->service->setRegister('buildiq');
		$this->service->setSchema('application');

		$pendingRef = new \ReflectionProperty(ObjectService::class, 'currentSchemaRef');
		$pendingRef->setAccessible(true);

		$this->assertNull(
			$pendingRef->getValue($this->service),
			'a schema resolved inside an already-set register leaves nothing to re-resolve, '
			. 'so the pending ref must not survive the call'
		);

		// BEFORE THE FIX this throws SchemaNotInRegisterException naming
		// "application" — a slug this caller never mentioned, belonging to a
		// register it has nothing to do with.
		$this->service->setRegister('portaliq');

		$this->addToAssertionCount(1);

	}//end testACompletedRegisterThenSchemaChainLeavesNoPendingRef()
}//end class
