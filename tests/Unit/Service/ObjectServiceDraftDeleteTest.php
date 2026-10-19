<?php

declare(strict_types=1);

/**
 * ObjectService::deleteObject() and drafts (decisions 180 and 216)
 *
 * A draft is not yet a record: it holds unsent input, nothing refers to it,
 * and it was never received. A schema with an `x-openregister-archival` block
 * refuses every user delete, which made a draft on such a schema immortal:
 * its owner could not discard it and the draft cleanup could not remove it.
 * pipelinq's `ticket` is such a schema, and the half-typed contact moments it
 * keeps as drafts are what decision 216's cleanup exists for.
 *
 * So a STORED draft passes the archival gate, and its delete is permanent.
 * Every test here has its control: the same delete on an object that is not
 * a draft is refused, or stays a soft delete.
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
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-be-deletable-on-every-schema-and-its-delete-must-be-permanent
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
use OCA\OpenRegister\Exception\ArchivalImmutableException;
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
 * Tests that a stored draft can be deleted on every schema, for good, and that nothing else gets past the archival gate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectServiceDraftDeleteTest extends TestCase {

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

	private IUserSession&\PHPUnit\Framework\MockObject\MockObject $userSession;

	private IGroupManager&\PHPUnit\Framework\MockObject\MockObject $groupManager;

	/**
	 * Set up fresh service + mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
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
			$this->userSession,
			$this->createMock(SearchTrailService::class),
			$this->groupManager,
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
	 * A register and a schema, with or without an archival block.
	 *
	 * @param bool $archival Whether the schema declares `x-openregister-archival`.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function scope(bool $archival): array {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('ticket');
		if ($archival === true) {
			$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P2Y']]]);
		}

		return [$register, $schema];
	}//end scope()

	/**
	 * Make the mapper answer with an object in the given stored status, or with nothing.
	 *
	 * @param string|null $status   The stored status.
	 * @param bool        $reachable False when the caller's lookup finds nothing.
	 *
	 * @return void
	 */
	private function stored(?string $status, bool $reachable = true): void {
		$this->objectMapper->method('find')->willReturnCallback(
			static function (string|int $identifier) use ($status, $reachable): ObjectEntity {
				if ($reachable === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string) $identifier);
				$entity->setOwner('agent');
				$entity->setStatus($status);

				return $entity;
			}
		);
	}//end stored()

	/**
	 * Record what the delete handler is asked, and answer true.
	 *
	 * @param array $asked Receives the `permanent` flag of every call.
	 *
	 * @return void
	 */
	private function recordHandler(array &$asked): void {
		$this->deleteHandler->method('deleteObject')->willReturnCallback(
			static function (mixed ...$args) use (&$asked): bool {
				// Position 8 is `permanent` in DeleteObject::deleteObject().
				$asked[] = ($args[8] ?? false);

				return true;
			}
		);
	}//end recordHandler()

	/**
	 * A stored draft on an archival schema is deleted, and for good.
	 *
	 * @return void
	 */
	public function testADraftOnAnArchivalSchemaIsDeletedForGood(): void {
		[$register, $schema] = $this->scope(archival: true);
		$this->stored(status: 'draft');
		$asked = [];
		$this->recordHandler(asked: $asked);

		$result = $this->service->deleteObject(uuid: 'draft-1', register: $register, schema: $schema, _rbac: false, _multitenancy: false);

		$this->assertTrue($result);
		$this->assertSame([true], $asked, 'The draft was not handed to the delete handler as a permanent delete.');
	}//end testADraftOnAnArchivalSchemaIsDeletedForGood()

	/**
	 * Control: on the same schema an object that is not a draft is refused, whatever its status.
	 *
	 * @dataProvider notADraft
	 *
	 * @param string|null $status The stored status.
	 *
	 * @return void
	 */
	public function testARecordOnAnArchivalSchemaIsStillRefused(?string $status): void {
		[$register, $schema] = $this->scope(archival: true);
		$this->stored(status: $status);
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(ArchivalImmutableException::class);

		$this->service->deleteObject(uuid: 'record-1', register: $register, schema: $schema, _rbac: false, _multitenancy: false);
	}//end testARecordOnAnArchivalSchemaIsStillRefused()

	/**
	 * Stored statuses that are not `draft`.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function notADraft(): array {
		return [
			'active' => ['active'],
			'no stored status (deduced from dates)' => [null],
			'a status that only looks like it' => ['Draft'],
		];
	}//end notADraft()

	/**
	 * A draft the caller's lookup cannot reach is not their draft: the gate refuses as before.
	 *
	 * @return void
	 */
	public function testAnUnreachableObjectOnAnArchivalSchemaIsStillRefused(): void {
		[$register, $schema] = $this->scope(archival: true);
		$this->stored(status: 'draft', reachable: false);
		$this->deleteHandler->expects($this->never())->method('deleteObject');

		$this->expectException(ArchivalImmutableException::class);

		$this->service->deleteObject(uuid: 'somebody-elses-draft', register: $register, schema: $schema);
	}//end testAnUnreachableObjectOnAnArchivalSchemaIsStillRefused()

	/**
	 * On a schema without an archival block a draft's delete is permanent too.
	 *
	 * @return void
	 */
	public function testADraftIsDeletedForGoodOnAnySchema(): void {
		[$register, $schema] = $this->scope(archival: false);
		$this->stored(status: 'draft');
		$asked = [];
		$this->recordHandler(asked: $asked);

		$this->assertTrue($this->service->deleteObject(uuid: 'draft-1', register: $register, schema: $schema, _rbac: false, _multitenancy: false));
		$this->assertSame([true], $asked);
	}//end testADraftIsDeletedForGoodOnAnySchema()

	/**
	 * Somebody else's draft is not deleted through the single-object path, and the handler is never asked.
	 *
	 * @return void
	 */
	public function testSomebodyElsesDraftIsNotDeleted(): void {
		[$register, $schema] = $this->scope(archival: false);
		$this->stored(status: 'draft');
		$asked = [];
		$this->recordHandler(asked: $asked);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		try {
			// The stored draft belongs to 'agent'; the caller (no session user) is not that owner.
			// Judged as an anonymous web caller: a userless command-line caller is the system, which no draft is kept from.
			\OCA\OpenRegister\Service\AnonymousEvaluationContext::run(
				fn () => $this->service->deleteObject(uuid: 'draft-1', register: $register, schema: $schema, _rbac: true, _multitenancy: false)
			);
		} finally {
			$this->assertSame([], $asked);
		}
	}//end testSomebodyElsesDraftIsNotDeleted()

	/**
	 * Sign in as a user in the given groups.
	 *
	 * @param string        $uid    The user id.
	 * @param array<string> $groups The user's groups.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups): void {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end signIn()

	/**
	 * The owner deletes their own draft, for good.
	 *
	 * @return void
	 */
	public function testTheOwnerMayDeleteTheirOwnDraft(): void {
		[$register, $schema] = $this->scope(archival: false);
		$this->stored(status: 'draft');
		$asked = [];
		$this->recordHandler(asked: $asked);

		// The stored draft belongs to 'agent'.
		$this->signIn(uid: 'agent', groups: ['staff']);
		$this->assertTrue($this->service->deleteObject(uuid: 'draft-1', register: $register, schema: $schema, _rbac: true, _multitenancy: false));
		$this->assertSame([true], $asked, "the owner's own draft is deleted for good");
	}//end testTheOwnerMayDeleteTheirOwnDraft()

	/**
	 * An administrator is not kept from another user's draft, as on the read path.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayDeleteSomebodyElsesDraft(): void {
		[$register, $schema] = $this->scope(archival: false);
		$this->stored(status: 'draft');
		$asked = [];
		$this->recordHandler(asked: $asked);

		$this->signIn(uid: 'root', groups: ['admin']);
		$this->assertTrue($this->service->deleteObject(uuid: 'draft-1', register: $register, schema: $schema, _rbac: true, _multitenancy: false));
		$this->assertSame([true], $asked);
	}//end testAnAdministratorMayDeleteSomebodyElsesDraft()

	/**
	 * A non-owner who is not an administrator is still kept from the draft.
	 *
	 * @return void
	 */
	public function testANonOwnerNonAdministratorIsStillKept(): void {
		[$register, $schema] = $this->scope(archival: false);
		$this->stored(status: 'draft');
		$asked = [];
		$this->recordHandler(asked: $asked);

		$this->signIn(uid: 'mallory', groups: ['staff']);
		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		try {
			$this->service->deleteObject(uuid: 'draft-1', register: $register, schema: $schema, _rbac: true, _multitenancy: false);
		} finally {
			$this->assertSame([], $asked);
		}
	}//end testANonOwnerNonAdministratorIsStillKept()

	/**
	 * Control: an ordinary object's delete stays the soft delete it was.
	 *
	 * @return void
	 */
	public function testAnOrdinaryDeleteStaysSoft(): void {
		[$register, $schema] = $this->scope(archival: false);
		$this->stored(status: 'active');
		$asked = [];
		$this->recordHandler(asked: $asked);

		$this->assertTrue($this->service->deleteObject(uuid: 'record-1', register: $register, schema: $schema, _rbac: false, _multitenancy: false));
		$this->assertSame([false], $asked, 'A delete of an object that is not a draft became permanent.');
	}//end testAnOrdinaryDeleteStaysSoft()
}//end class
