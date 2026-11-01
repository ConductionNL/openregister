<?php

declare(strict_types=1);

/**
 * ObjectService::saveObject() and somebody else's draft (decisions 180 and 216)
 *
 * A draft answers to its owner only. That rule lives in the read query, so the
 * save's own lookup does not find somebody else's draft, and the write went on
 * as a create under a chosen uuid, which the schema's create rule allows. It
 * was then stored over the draft. On a live instance (12 October 2026) a
 * second account changed the first one's draft through PATCH, and through a
 * POST that named the draft's id, and read its content in the answer. GET, PUT
 * and DELETE answered 404 to the same account.
 *
 * The mapper here answers the way the real one does: with the read rule on it
 * finds nothing, with the rule off it finds the draft. Every refusal has its
 * control.
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
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
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

/**
 * Tests that a save cannot reach a draft its caller cannot read.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Db\View
 * @uses \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
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
 */
class ObjectServiceDraftWriteTest extends TestCase {

	/** @var ObjectService */
	private ObjectService $service;

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

	/** @var array<int, array<string, mixed>> What reached the save handler. */
	private array $saves = [];

	/**
	 * Set up fresh service + mocks before each test.
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

		// The controls reach validation; what the object holds is not this test's subject.
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturn(new ValidationResult(null));

		$this->service = new ObjectService(
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
		);
	}//end setUp()

	/**
	 * A register and a schema, whose drafts are the owner's alone unless told otherwise.
	 *
	 * @param bool $draftsVisible Whether the schema lets others see its drafts.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function scope(bool $draftsVisible = false): array {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('ticket');
		if ($draftsVisible === true) {
			$schema->setConfiguration(['draftsVisible' => true]);
		}

		return [$register, $schema];
	}//end scope()

	/**
	 * Sign a user in, or nobody.
	 *
	 * @param string|null $uid The user id, null for nobody.
	 *
	 * @return void
	 */
	private function signedIn(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);
	}//end signedIn()

	/**
	 * Store one object, and make the mapper answer as the real one does for a caller it is hidden from.
	 *
	 * With the read rule on the lookup finds nothing; with it off it finds the object.
	 *
	 * @param string|null $status The stored status, or null for no stored object at all.
	 * @param bool        $exists False when the uuid is not stored.
	 *
	 * @return void
	 */
	private function hiddenFromTheCaller(?string $status, bool $exists = true): void {
		$this->objectMapper->method('find')->willReturnCallback(
			static function (
				string|int $identifier,
				?Register $register = null,
				?Schema $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
			) use (
				$status,
				$exists
			): ObjectEntity {
				if ($exists === false || $_rbac === true) {
					throw new DoesNotExistException('Object not found in magic table');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string) $identifier);
				$entity->setOwner('agent');
				$entity->setStatus($status);

				return $entity;
			}
		);

		$this->saveHandler->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments): ObjectEntity {
				$this->saves[] = $arguments;
				$entity = new ObjectEntity();
				$entity->setUuid('draft-1');

				return $entity;
			}
		);
	}//end hiddenFromTheCaller()

	/**
	 * Save over the uuid, the way PATCH and a POST that names an id do: RBAC on, the uuid given.
	 *
	 * @param bool $rbac          Whether the save is judged for the signed-in caller.
	 * @param bool $draftsVisible Whether the schema lets others see its drafts.
	 *
	 * @return void
	 */
	private function saveOver(bool $rbac = true, bool $draftsVisible = false): void {
		[$register, $schema] = $this->scope(draftsVisible: $draftsVisible);
		$this->service->saveObject(
			object: ['subject' => 'changed by somebody else'],
			register: $register,
			schema: $schema,
			uuid: 'draft-1',
			_rbac: $rbac,
			_multitenancy: $rbac
		);
	}//end saveOver()

	/**
	 * Somebody else's draft is not written, and nothing reaches the save handler.
	 *
	 * @dataProvider notTheOwner
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return void
	 */
	public function testSomebodyElsesDraftIsNotWritten(?string $uid): void {
		$this->signedIn(uid: $uid);
		$this->hiddenFromTheCaller(status: 'draft');

		try {
			// Nobody signed in is judged as an anonymous web caller here: on the command line
			// (where PHPUnit runs) a userless caller is the system, which no draft is kept from.
			\OCA\OpenRegister\Service\AnonymousEvaluationContext::run(fn () => $this->saveOver());
			$this->fail('A save over somebody else\'s draft was let through.');
		} catch (NotAuthorizedException $refused) {
			$this->assertSame('Only the person who started this draft can change it.', $refused->getMessage());
		}

		$this->assertSame([], $this->saves);
	}//end testSomebodyElsesDraftIsNotWritten()

	/**
	 * Callers who are not the draft's owner.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function notTheOwner(): array {
		return [
			'a colleague' => ['colleague'],
			'nobody signed in' => [null],
		];
	}//end notTheOwner()

	/**
	 * Control: a uuid nobody stored is a create under a chosen uuid, as before.
	 *
	 * @return void
	 */
	public function testAChosenUuidNobodyStoredIsStillACreate(): void {
		$this->signedIn(uid: 'colleague');
		$this->hiddenFromTheCaller(status: null, exists: false);

		$this->saveOver();

		$this->assertCount(1, $this->saves);
	}//end testAChosenUuidNobodyStoredIsStillACreate()

	/**
	 * Control: the rule is about drafts. A stored object that is not a draft is judged as it was.
	 *
	 * @return void
	 */
	public function testAHiddenObjectThatIsNoDraftIsJudgedAsBefore(): void {
		$this->signedIn(uid: 'colleague');
		$this->hiddenFromTheCaller(status: 'active');

		$this->saveOver();

		$this->assertCount(1, $this->saves);
	}//end testAHiddenObjectThatIsNoDraftIsJudgedAsBefore()

	/**
	 * Control: with RBAC off (an administrator, a job, a scope-value owner) the caller's own check stands.
	 *
	 * @return void
	 */
	public function testWithRbacOffTheCallerDecides(): void {
		$this->signedIn(uid: 'admin');
		$this->hiddenFromTheCaller(status: 'draft');

		$this->saveOver(rbac: false);

		$this->assertCount(1, $this->saves);
	}//end testWithRbacOffTheCallerDecides()

	/**
	 * Control: a schema that shows its drafts to others lets them be judged by its own rules.
	 *
	 * @return void
	 */
	public function testASchemaThatShowsItsDraftsKeepsItsOwnRules(): void {
		$this->signedIn(uid: 'colleague');
		$this->hiddenFromTheCaller(status: 'draft');

		$this->saveOver(draftsVisible: true);

		$this->assertCount(1, $this->saves);
	}//end testASchemaThatShowsItsDraftsKeepsItsOwnRules()
}//end class
