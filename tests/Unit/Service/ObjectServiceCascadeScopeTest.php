<?php

declare(strict_types=1);

/**
 * ObjectService::saveObject() and the create check of nested objects
 *
 * The pre-validation cascade creates nested inline objects before the parent is
 * validated. Each create is checked for the caller's create rights unless the
 * save runs with RBAC off, so the save tells the save handler which mode it runs
 * in for as long as the cascade runs, and closes that scope even when the
 * cascade refuses.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Exception\NotAuthorizedException;
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
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;

/**
 * The pre-validation cascade runs inside a cascade scope that carries the save's RBAC mode.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\Object\BulkCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\ObjectSaveRun
 * @uses   \OCA\OpenRegister\Service\Object\ObjectCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\ReferencePreviewInvalidation
 * @uses   \OCA\OpenRegister\Service\Object\SavedObjectScope
 * @uses   \OCA\OpenRegister\Service\Object\SavedScopeIds
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Search\Db\View
 * @uses \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses \OCA\OpenRegister\Service\Object\ObjectFindRequest
 * @uses \OCA\OpenRegister\Service\Object\ObjectFindScope
 * @uses \OCA\OpenRegister\Service\Object\ObjectFindTarget
 * @uses \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 * @uses \OCA\OpenRegister\Service\Object\RepeatingGroupValidator
 * @uses \OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator
 * @uses \OCA\OpenRegister\Service\Hinge\LensResolver
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\Object\ObjectDateValues
 * @uses \OCA\OpenRegister\Service\Object\SaveAccessRequest
 */
class ObjectServiceCascadeScopeTest extends TestCase {

	/** @var ObjectService */
	private ObjectService $service;

	/** @var array<string, mixed> The data the save handler was given. */
	private array $savedData = [];

	/** @var MockObject&SaveObject */
	private MockObject $saveHandler;

	/** @var MockObject&IUserSession */
	private MockObject $session;

	/** @var MockObject&RenderObject */
	private MockObject $renderHandler;

	/** @var MockObject&DeleteObject */
	private MockObject $deleteHandler;

	/** @var MockObject&MagicMapper */
	private MockObject $objectMapper;

	/** @var MockObject&CascadingHandler */
	private MockObject $cascadingHandler;

	/** @var MockObject&DateTimeNormalizer */
	private MockObject $dateTimeNormalizer;

	/** @var list<string> The scope and cascade calls, in order. */
	private array $calls = [];

	/**
	 * Set up the service over a save handler and a cascading handler that record their calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);
		$this->dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$this->renderHandler->method('renderEntity')->willReturnArgument(0);
		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('Object not found'));

		$this->saveHandler->method('enterCascadeScope')->willReturnCallback(
			function (bool $_rbac): void {
				$this->calls[] = 'enter:' . var_export($_rbac, true);
			}
		);
		$this->saveHandler->method('leaveCascadeScope')->willReturnCallback(
			function (): void {
				$this->calls[] = 'leave';
			}
		);
		$this->saveHandler->method('saveObject')->willReturnCallback(
			function (...$arguments): ObjectEntity {
				$this->calls[] = 'save';
				$this->savedData = $arguments[2];
				$entity = new ObjectEntity();
				$entity->setUuid('11111111-1111-1111-1111-111111111111');
				return $entity;
			}
		);

		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturn(new ValidationResult(null));

		$this->service = new ObjectService(...ObjectServiceFactory::args(
			$this->createMock(DataManipulationHandler::class),
			$this->deleteHandler,
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->renderHandler,
			$this->saveHandler,
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$validator,
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
			$this->cascadingHandler,
			$this->createMock(MigrationHandler::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->objectMapper,
			$this->createMock(FileService::class),
			$this->session,
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->dateTimeNormalizer,
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		));
	}//end setUp()

	/**
	 * Create one ticket with a nested note.
	 *
	 * @param bool $rbac Whether the save applies RBAC.
	 *
	 * @return void
	 */
	private function createTicket(bool $rbac): void {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('ticket');
		$this->service->saveObject(
			object: ['subject' => 'new', 'note' => ['text' => 'nested']],
			register: $register,
			schema: $schema,
			_rbac: $rbac,
			_multitenancy: $rbac
		);
	}//end createTicket()

	/**
	 * Make the cascading handler record its run, or refuse.
	 *
	 * @param bool $refuse Whether the cascade refuses a nested create.
	 *
	 * @return void
	 */
	private function cascade(bool $refuse = false): void {
		$this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			function (array $obj, mixed $schema, ?string $uuid) use ($refuse): array {
				$this->calls[] = 'cascade';
				if ($refuse === true) {
					throw new NotAuthorizedException(message: 'no create rights for note');
				}

				return [$obj, $uuid];
			}
		);
	}//end cascade()

	public function testTheCascadeRunsInAScopeWithTheSavesRbacOn(): void {
		$this->cascade();
		$this->createTicket(rbac: true);

		$this->assertSame(expected: ['enter:true', 'cascade', 'leave', 'save'], actual: $this->calls);
	}

	public function testTheCascadeRunsInAScopeWithTheSavesRbacOff(): void {
		$this->cascade();
		$this->createTicket(rbac: false);

		$this->assertSame(expected: ['enter:false', 'cascade', 'leave', 'save'], actual: $this->calls);
	}

	/**
	 * The audit row of the created object is written inside the handler, so the
	 * client-cause mark has to come through the cascade with the object.
	 */
	public function testTheClientCauseMarkReachesTheSaveHandlerPastTheCascade(): void {
		$this->cascade();
		foreach (['applyAlwaysDefaults', 'applyExpressionDefaults', 'seedLifecycleFieldOnCreate'] as $step) {
			$this->saveHandler->method($step)->willReturnArgument(1);
		}

		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('ticket');

		$this->service->saveObject(
			object: ['subject' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]],
			register: $register,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);

		$this->assertTrue($this->savedData['@self'][WriteCause::CLIENT_ATTEMPT_KEY]);
	}//end testTheClientCauseMarkReachesTheSaveHandlerPastTheCascade()

	public function testARefusedCascadeClosesItsScopeAndSavesNothing(): void {
		$this->cascade(refuse: true);

		try {
			$this->createTicket(rbac: true);
			$this->fail('The refused cascade was saved.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame(expected: 'no create rights for note', actual: $e->getMessage());
		}

		$this->assertSame(expected: ['enter:true', 'cascade', 'leave'], actual: $this->calls);
	}
}//end class
