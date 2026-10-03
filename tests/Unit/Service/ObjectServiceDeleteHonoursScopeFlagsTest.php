<?php

declare(strict_types=1);

/**
 * ObjectService::deleteObject() honours the caller's _rbac and _multitenancy flags
 *
 * The delete handler honours both flags, but the lookups deleteObject() runs
 * before it did not: the object lookup and the transferred-object guard both
 * applied the session's RBAC and tenant scope whatever the caller asked for.
 * A sessionless caller that passed `_multitenancy: false` (learniq's xAPI
 * document store, answering a cmi5 AU with no Nextcloud session) got "Object
 * not found in magic table" for an object that exists, and the delete handler
 * never ran.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Db\Register;
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
use ReflectionClass;

/**
 * Tests that deleteObject()'s own lookups use the flags the caller passed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectServiceDeleteHonoursScopeFlagsTest extends TestCase {

	/** @var ObjectService */
	private ObjectService $service;

	/** @var ReflectionClass<ObjectService> */
	private ReflectionClass $reflection;

	/** @var MockObject&SaveObject */
	private MockObject $saveHandler;

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

	/**
	 * Set up fresh service + mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);
		$this->dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);

		// saveObject() returns renderHandler->renderEntity($savedObject, …);
		// echo the saved entity back so the tests can assertSame() on it.
		$this->renderHandler->method('renderEntity')->willReturnArgument(0);

		// normalize() echoes input unchanged (no date coercion side-effects needed here).
		$this->dateTimeNormalizer->method('normalize')->willReturnCallback(
			static function (?string $input): ?\DateTimeImmutable {
				if ($input === null || trim($input) === '') {
					return null;
				}

				try {
					return new \DateTimeImmutable($input);
				} catch (\Throwable $e) {
					return null;
				}
			}
		);

		// CascadingHandler: return object unchanged, UUID unchanged.
		$this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static function (array $obj, mixed $schema, ?string $uuid, ?int $register): array {
				return [$obj, $uuid];
			}
		);

		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->deleteHandler,
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->renderHandler,
			$this->saveHandler,
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
			$this->cascadingHandler,
			$this->createMock(MigrationHandler::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->objectMapper,
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
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
		);

		$this->reflection = new ReflectionClass(ObjectService::class);
	}//end setUp()

	/**
	 * A register and a schema that scope the delete to one magic table.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function scope(): array {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('xapi-document');

		return [$register, $schema];
	}//end scope()

	/**
	 * A find() double for an object that lives in another tenant.
	 *
	 * It answers only a lookup made with RBAC and multitenancy both off, the
	 * way MagicMapper hides a row outside the session's organisation, and
	 * records the flags of every lookup.
	 *
	 * @param array $retention The object's retention block.
	 * @param array $calls     Receives [includeDeleted, _rbac, _multitenancy] per call.
	 *
	 * @return void
	 */
	private function stubObjectInAnotherTenant(array $retention, array &$calls): void {
		$this->objectMapper->method('find')->willReturnCallback(
			static function (
				string|int $identifier,
				mixed $register = null,
				mixed $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
				bool $_multitenancy = true
			) use ($retention, &$calls): ObjectEntity {
				$calls[] = [$includeDeleted, $_rbac, $_multitenancy];
				if ($_rbac === false && $_multitenancy === false) {
					$entity = new ObjectEntity();
					$entity->setUuid((string) $identifier);
					$entity->setRetention($retention);
					return $entity;
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
			}
		);
	}//end stubObjectInAnotherTenant()

	/**
	 * A caller that turned RBAC and multitenancy off reaches the delete handler.
	 *
	 * @return void
	 */
	public function testACallerWithoutScopeFiltersCanDeleteTheObject(): void {
		[$register, $schema] = $this->scope();
		$calls = [];
		$this->stubObjectInAnotherTenant(retention: [], calls: $calls);

		$this->deleteHandler->expects($this->once())
			->method('deleteObject')
			->willReturnCallback(
				function (mixed ...$args): bool {
					// The handler still gets the caller's flags, as it always did.
					$this->assertFalse($args[4] ?? true);
					$this->assertFalse($args[5] ?? true);
					return true;
				}
			);

		$result = $this->service->deleteObject(
			uuid: 'activity-state-1',
			register: $register,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);

		$this->assertTrue($result);
		$this->assertNotSame([], $calls);
		foreach ($calls as [$includeDeleted, $rbac, $multitenancy]) {
			$this->assertFalse($rbac, 'a lookup inside deleteObject() applied RBAC the caller turned off');
			$this->assertFalse($multitenancy, 'a lookup inside deleteObject() applied the tenant scope the caller turned off');
		}
	}//end testACallerWithoutScopeFiltersCanDeleteTheObject()

	/**
	 * A caller that keeps the default flags still cannot reach another tenant's object.
	 *
	 * @return void
	 */
	public function testADefaultCallerStillCannotSeeAnotherTenantsObject(): void {
		[$register, $schema] = $this->scope();
		$calls = [];
		$this->stubObjectInAnotherTenant(retention: [], calls: $calls);
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		$this->service->deleteObject(uuid: 'activity-state-1', register: $register, schema: $schema);
	}//end testADefaultCallerStillCannotSeeAnotherTenantsObject()

	/**
	 * The transferred-object guard sees what the caller's delete would touch.
	 *
	 * Before, it looked with the session scope, missed an object outside it,
	 * and let a `_multitenancy: false` delete through to a transferred record.
	 *
	 * @return void
	 */
	public function testATransferredObjectIsRefusedForACallerWithoutScopeFilters(): void {
		[$register, $schema] = $this->scope();
		$calls = [];
		$this->stubObjectInAnotherTenant(retention: ['archiefstatus' => 'overgebracht'], calls: $calls);
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->expectExceptionMessageMatches('/^OBJECT_TRANSFERRED:/');

		$this->service->deleteObject(
			uuid: 'activity-state-1',
			register: $register,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);
	}//end testATransferredObjectIsRefusedForACallerWithoutScopeFilters()
}//end class
