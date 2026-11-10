<?php

declare(strict_types=1);

/**
 * ObjectService: a write to a uuid that is stored but hidden from the caller is judged as an update of that row.
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
 *
 * @spec openspec/changes/insert-race-follow-ups/specs/object-lifecycle/spec.md
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
use OCA\OpenRegister\Service\Object\PermissionCheckOptions;
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
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;

/**
 * Tests that a save cannot reach a draft its caller cannot read.
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
 * @uses   \OCA\OpenRegister\Service\Object\SaveAccessRequest
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
 */
class ObjectServiceHiddenRowWriteTest extends TestCase {

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
	 * The permission checks, as [action, userId, owner, rbac, the uuid of the object judged].
	 *
	 * @var list<array{0: string, 1: ?string, 2: ?string, 3: bool, 4: ?string}>
	 */
	private array $checks = [];

	/**
	 * The lookups past the read rule, as [rbac, multitenancy].
	 *
	 * @var list<array{0: bool, 1: bool}>
	 */
	private array $lookups = [];

	/**
	 * Whether update is refused unless the acting user owns the object.
	 *
	 * @var bool
	 */
	private bool $updateNeedsOwner = false;

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
			$this->permissions(),
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
		// The stored row lies inside the caller's organisations; only the read rules hide it.
		$this->objectMapper->method('callerOrganisationsHold')->willReturn(true);
		$this->objectMapper->method('find')->willReturnCallback(
			function (
				string|int $identifier,
				?Register $register = null,
				?Schema $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
				bool $_multitenancy = true,
			) use (
				$status,
				$exists
			): ObjectEntity {
				$this->lookups[] = [$_rbac, $_multitenancy];
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
	 * A permission handler that records every check and grants it.
	 *
	 * @return PermissionHandler
	 */
	private function permissions(): PermissionHandler {
		$this->checks = [];
		$handler = $this->createMock(PermissionHandler::class);
		$handler->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action, PermissionCheckOptions $options = new PermissionCheckOptions(), bool $_rbac = true): void {
				$userId = $options->userId;
				$objectOwner = $options->objectOwner;
				$object = $options->object;
				$this->checks[] = [$action, $userId, $objectOwner, $_rbac, $object?->getUuid()];
				if ($this->updateNeedsOwner === true && $action === 'update' && ($userId ?? 'mallory') !== $objectOwner) {
					throw new NotAuthorizedException(message: 'no update');
				}
			}
		);
		return $handler;
	}//end permissions()

	/**
	 * Save over the uuid with the given RBAC flag.
	 *
	 * @param bool $rbac Whether the save is judged for the signed-in caller.
	 *
	 * @return void
	 */
	private function saveOver(bool $rbac): void {
		[$register, $schema] = $this->scope();
		$this->service->saveObject(
			object: ['subject' => 'changed'],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: 'draft-1'),
			_rbac: $rbac,
			_multitenancy: $rbac
		);
	}//end saveOver()

	public function testAStoredRowTheCallerCannotSeeMustPassCreateAndUpdate(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: null);

		$this->saveOver(rbac: true);

		$this->assertSame([['create', null, null, true, null], ['update', null, 'agent', true, 'draft-1']], $this->checks);
		// The caller's own lookup, then the one past the read rule (unscoped, so a
		// row of any organisation is found). The organisation is compared on that
		// row, not looked up through the read filter.
		$this->assertSame([[true, true], [false, false]], array_slice($this->lookups, 0, 2));
		$this->assertNotContains([false, true], $this->lookups);
	}//end testAStoredRowTheCallerCannotSeeMustPassCreateAndUpdate()

	public function testAStoredRowTheCallerMayNotUpdateIsNotWritten(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: null);
		$this->updateNeedsOwner = true;

		try {
			$this->saveOver(rbac: true);
			$this->fail('The update rule should have refused the write.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('no update', $e->getMessage());
		}

		$this->assertSame([], $this->saves);
	}//end testAStoredRowTheCallerMayNotUpdateIsNotWritten()

	/**
	 * One argument of the last SaveObject::saveObject() call, by parameter name.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return mixed The value it was given.
	 */
	private function savedArgument(string $name): mixed {
		$this->assertNotSame([], $this->saves, 'nothing was saved');
		$arguments = $this->saves[count($this->saves) - 1];
		if (array_key_exists($name, $arguments) === true) {
			return $arguments[$name];
		}

		foreach ((new \ReflectionMethod(SaveObject::class, 'saveObject'))->getParameters() as $index => $parameter) {
			if ($parameter->getName() === $name) {
				return ($arguments[$index] ?? $parameter->getDefaultValue());
			}
		}

		$this->fail('SaveObject::saveObject() has no parameter ' . $name);
	}//end savedArgument()

	public function testTheWriteContinuesWithTheUuidOfTheRowTheCheckRanOn(): void {
		// "1" is the id of the row the caller may see, whose uuid is uuid-b.
		$this->signedIn('mallory');
		$visible = new ObjectEntity();
		$visible->setUuid('uuid-b');
		$visible->setOwner('mallory');
		$this->objectMapper->method('find')->willReturn($visible);
		$this->saveHandler->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments) use ($visible): ObjectEntity {
				$this->saves[] = $arguments;
				return $visible;
			}
		);
		[$register, $schema] = $this->scope();

		$this->service->saveObject(
			object: ['subject' => 'x'],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: '1')
		);

		$this->assertSame('uuid-b', $this->savedArgument(name: 'uuid'));
		$this->assertFalse($this->savedArgument(name: 'failIfExists'));
	}//end testTheWriteContinuesWithTheUuidOfTheRowTheCheckRanOn()

	public function testAHiddenRowsWriteContinuesWithItsUuid(): void {
		// The identifier "7" names the hidden row by its id; its uuid is hidden-7.
		$this->signedIn('mallory');
		// The hidden row lies inside the caller's organisations; only the read rules hide it.
		$this->objectMapper->method('callerOrganisationsHold')->willReturn(true);
		$this->objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier, ?Register $register = null, ?Schema $schema = null, bool $includeDeleted = false, bool $_rbac = true): ObjectEntity {
				if ($_rbac === true) {
					throw new DoesNotExistException('Object not found in magic table');
				}

				$hidden = new ObjectEntity();
				$hidden->setUuid('hidden-7');
				$hidden->setOwner('agent');
				return $hidden;
			}
		);
		$this->saveHandler->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments): ObjectEntity {
				$this->saves[] = $arguments;
				return new ObjectEntity();
			}
		);
		[$register, $schema] = $this->scope();

		$this->service->saveObject(
			object: ['subject' => 'x'],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: '7')
		);

		$this->assertSame('hidden-7', $this->savedArgument(name: 'uuid'));
	}//end testAHiddenRowsWriteContinuesWithItsUuid()

	public function testAChosenIdNothingVisibleAnswersToMayOnlyInsert(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: null, exists: false);

		$this->saveOver(rbac: true);

		$this->assertSame('draft-1', $this->savedArgument(name: 'uuid'));
		$this->assertTrue($this->savedArgument(name: 'failIfExists'), 'an unfiltered match of the id must be refused, not updated');
	}//end testAChosenIdNothingVisibleAnswersToMayOnlyInsert()

	public function testWithRbacOffTheIdentifierAndTheUpsertStay(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: null, exists: false);

		$this->saveOver(rbac: false);

		$this->assertSame('draft-1', $this->savedArgument(name: 'uuid'));
		$this->assertFalse($this->savedArgument(name: 'failIfExists'));
	}//end testWithRbacOffTheIdentifierAndTheUpsertStay()

	public function testANewUuidIsJudgedAsACreate(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: null, exists: false);

		$this->saveOver(rbac: true);

		$this->assertSame([['create', null, null, true, null]], $this->checks);
	}//end testANewUuidIsJudgedAsACreate()

	public function testWithRbacOffTheStoredRowIsNotLookedUp(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: null);

		$this->saveOver(rbac: false);

		$this->assertSame([['create', null, null, false, null]], $this->checks);
	}//end testWithRbacOffTheStoredRowIsNotLookedUp()

	public function testSomebodyElsesDraftIsStillRefused(): void {
		$this->signedIn('mallory');
		$this->hiddenFromTheCaller(status: 'draft');

		$this->expectException(NotAuthorizedException::class);
		$this->saveOver(rbac: true);
	}//end testSomebodyElsesDraftIsStillRefused()
	public function testASoftDeletedRowIsJudgedAgainstTheStoredObject(): void {
		$this->signedIn('mallory');
		// The stored row lies inside the caller's organisations; only the read rules hide it.
		$this->objectMapper->method('callerOrganisationsHold')->willReturn(true);
		$this->objectMapper->method('find')->willReturnCallback(
			function (
				string|int $identifier,
				?Register $register = null,
				?Schema $schema = null,
				bool $includeDeleted = false,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ObjectEntity {
				$this->lookups[] = [$_rbac, $_multitenancy, $includeDeleted];
				if ($includeDeleted === false) {
					throw new DoesNotExistException('Object not found in magic table');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string) $identifier);
				$entity->setOwner('agent');
				$entity->setDeleted(['deletedBy' => 'agent']);
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

		$this->saveOver(rbac: true);

		$this->assertSame([['create', null, null, true, null], ['update', null, 'agent', true, 'draft-1']], $this->checks);
		$this->assertContains([false, false, true], $this->lookups);
	}//end testASoftDeletedRowIsJudgedAgainstTheStoredObject()

}//end class
