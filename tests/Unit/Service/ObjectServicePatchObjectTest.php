<?php

/**
 * Unit coverage for ObjectService's PATCH-semantic write path.
 *
 * `saveObject()` is PUT-semantic: a property absent from the payload is written
 * as null. `patchObject()` is the supported partial-write path, and these tests
 * pin the four defects it used to carry — the `(int)` identifier cast, the
 * unforwarded `_rbac` / `_multitenancy` flags, the missing acting user, and the
 * shallow unscoped `array_merge` — as well as the merge rules themselves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
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
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Tests for ObjectService::patchObject() and its merge rules.
 */
class ObjectServicePatchObjectTest extends TestCase {

	private ObjectService $service;

	private ReflectionClass $reflection;

	/** @var MockObject&MagicMapper */
	private $objectMapper;

	/** @var MockObject&CascadingHandler */
	private $cascadingHandler;

	/** @var MockObject&PermissionHandler */
	private $permissionHandler;

	/** @var MockObject&SchemaMapper */
	private $schemaMapper;

	/** @var MockObject&IAppContainer */
	private $container;

	/** What the container hands back for SchemaTypeConverter::class. */
	private mixed $converterAnswer = null;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->container = $this->createMock(IAppContainer::class);

		// The container answers with the REAL converter: the encode half of the
		// rule is the thing under test, so mocking it would test nothing. A
		// test that needs a container with no converter overwrites the answer.
		$this->converterAnswer = new SchemaTypeConverter();
		$this->container->method('get')->willReturnCallback(
			function (string $id): mixed {
				if ($id === SchemaTypeConverter::class) {
					return $this->converterAnswer;
				}

				throw new \RuntimeException('unexpected container lookup: ' . $id);
			}
		);

		$this->register = new Register();
		$this->register->setId(1);

		$this->schema = new Schema();
		$this->schema->setId(2);

		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->createMock(DeleteObject::class),
			$this->createMock(GetObject::class),
			$this->permissionHandler,
			$this->createMock(RenderObject::class),
			$this->createMock(SaveObject::class),
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
			$this->schemaMapper,
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
			$this->createMock(DateTimeNormalizer::class),
			$this->container,
			$this->createMock(ObjectSourceRegistry::class)
		);

		$this->reflection = new ReflectionClass(ObjectService::class);

	}//end setUp()

	/**
	 * Exercise the merge rules directly. The merge is the whole contract; the
	 * save that follows it is `saveObject()`'s own well-covered path.
	 *
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $patch
	 *
	 * @return array<string, mixed>
	 */
	private function merge(array $stored, array $patch): array {
		$method = $this->reflection->getMethod('mergePatchData');
		$method->setAccessible(true);

		return $method->invokeArgs($this->service, [$stored, $patch]);
	}//end merge()

	/**
	 * Hand the cascading handler its real tuple shape.
	 *
	 * The default mock returns null, which makes `handleCascadingWithContextPreservation()`
	 * warn on `$cascadeResult[0]` — an artefact of the mock, not of the code under test.
	 *
	 * @return void
	 */
	private function stubCascading(): void {
		$this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static fn (array $object, ?Schema $schema = null, ?string $uuid = null): array => [$object, $uuid]
		);

	}//end stubCascading()

	private function setProperty(string $name, mixed $value): void {
		$property = $this->reflection->getProperty($name);
		$property->setAccessible(true);
		$property->setValue($this->service, $value);

	}//end setProperty()

	// ── Merge rules (REQ-OWN-013) ───────────────────────────────────────

	public function testAnOmittedKeyIsPreserved(): void {
		$merged = $this->merge(['title' => 'Alpha', 'status' => 'open'], ['status' => 'closed']);

		$this->assertSame(['title' => 'Alpha', 'status' => 'closed'], $merged);

	}//end testAnOmittedKeyIsPreserved()

	public function testAnExplicitNullClearsAndLeavesTheRestAlone(): void {
		$merged = $this->merge(['title' => 'Alpha', 'status' => 'open'], ['title' => null]);

		$this->assertArrayHasKey('title', $merged);
		$this->assertNull($merged['title']);
		$this->assertSame('open', $merged['status']);

	}//end testAnExplicitNullClearsAndLeavesTheRestAlone()

	public function testNestedObjectsMergeRatherThanReplace(): void {
		$merged = $this->merge(
			['contact' => ['name' => 'Alpha', 'email' => 'a@example.org']],
			['contact' => ['email' => 'b@example.org']]
		);

		$this->assertSame(
			['contact' => ['name' => 'Alpha', 'email' => 'b@example.org']],
			$merged,
			'the shallow array_merge this replaced would have dropped contact.name'
		);

	}//end testNestedObjectsMergeRatherThanReplace()

	public function testANestedMergeRecursesMoreThanOneLevel(): void {
		$merged = $this->merge(
			['a' => ['b' => ['c' => 1, 'd' => 2]]],
			['a' => ['b' => ['d' => 3]]]
		);

		$this->assertSame(['a' => ['b' => ['c' => 1, 'd' => 3]]], $merged);

	}//end testANestedMergeRecursesMoreThanOneLevel()

	public function testArraysAreReplacedWholesaleAndNeverElementMerged(): void {
		$merged = $this->merge(['tags' => ['a', 'b', 'c']], ['tags' => ['x']]);

		$this->assertSame(['tags' => ['x']], $merged, 'a positional merge would corrupt any reordered list');

	}//end testArraysAreReplacedWholesaleAndNeverElementMerged()

	public function testAnEmptyListReplacesRatherThanBeingTreatedAsNoChange(): void {
		$merged = $this->merge(['tags' => ['a', 'b']], ['tags' => []]);

		$this->assertSame(['tags' => []], $merged);

	}//end testAnEmptyListReplacesRatherThanBeingTreatedAsNoChange()

	public function testANewKeyIsAdded(): void {
		$merged = $this->merge(['title' => 'Alpha'], ['status' => 'open']);

		$this->assertSame(['title' => 'Alpha', 'status' => 'open'], $merged);

	}//end testANewKeyIsAdded()

	public function testAnObjectReplacingAScalarDoesNotAttemptAMerge(): void {
		$merged = $this->merge(['contact' => 'a@example.org'], ['contact' => ['email' => 'b@example.org']]);

		$this->assertSame(['contact' => ['email' => 'b@example.org']], $merged);

	}//end testAnObjectReplacingAScalarDoesNotAttemptAMerge()

	// ── Identifier resolution (REQ-OWN-013) ─────────────────────────────

	public function testAUuidIdentifierIsNotCastToInt(): void {
		$uuid = '9f1c2b7e-3a4d-4c8e-9b21-5f6a7c8d9e01';
		$seen = null;

		$existing = new ObjectEntity();
		$existing->setUuid($uuid);
		$existing->setObject(['title' => 'Alpha']);

		$this->stubCascading();
		$this->objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier, ?Register $register = null, ?Schema $schema = null) use (&$seen, $existing): ObjectEntity {
				if ($seen === null) {
					$seen = ['identifier' => $identifier, 'register' => $register, 'schema' => $schema];
				}

				return $existing;
			}
		);

		try {
			$this->service->patchObject(
				objectId: $uuid,
				data: ['status' => 'closed'],
				register: $this->register,
				schema: $this->schema
			);
		} catch (Throwable $e) {
			// The deep saveObject pipeline is not the subject here; the lookup is.
		}

		$this->assertSame($uuid, $seen['identifier'], '(int) $objectId would have made this 9');
		$this->assertSame($this->register, $seen['register'], 'the lookup is scoped to one magic table');
		$this->assertSame($this->schema, $seen['schema']);

	}//end testAUuidIdentifierIsNotCastToInt()

	public function testTheMergedResultIsWhatTravelsOnToTheSave(): void {
		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setObject(['title' => 'Alpha', 'status' => 'draft', 'contact' => ['name' => 'A', 'email' => 'a@example.org']]);

		$this->objectMapper->method('find')->willReturn($existing);
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$seen = null;
		$this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static function (array $object, ?Schema $schema = null, ?string $uuid = null) use (&$seen): array {
				$seen = $object;

				return [$object, $uuid];
			}
		);

		try {
			$this->service->patchObject(objectId: 'u-1', data: ['title' => 'Beta', 'contact' => ['email' => 'b@example.org']]);
		} catch (Throwable $e) {
			// Expected — the rest of the save pipeline is mocked out.
		}

		$this->assertNotNull($seen, 'the merged payload reached the save pipeline');
		$this->assertSame('Beta', $seen['title']);
		$this->assertSame('draft', $seen['status'], 'an unmentioned property survives the patch');
		$this->assertSame(['name' => 'A', 'email' => 'b@example.org'], $seen['contact']);
		$this->assertSame('u-1', $seen['id'], 'the save is addressed at the object that was resolved');

	}//end testTheMergedResultIsWhatTravelsOnToTheSave()

	// ── The decode/encode seam ──────────────────────────────────────────
	//
	// The read path decodes: SchemaTypeConverter::convertString() turns a
	// `type: string` value that looks like JSON into an ARRAY. So
	// $existing->getObject() hands back an array where the schema says string,
	// the merge puts it straight back, and validation refuses the save —
	// failing a patch because of a property the caller never mentioned. These
	// pin the re-encode that closes the seam, and the limit on it.

	/**
	 * Run a patch far enough to observe the payload that reaches the save.
	 *
	 * @param array<string, mixed> $storedObject The object as a read hands it back.
	 * @param array<string, mixed> $properties   The schema's property declarations.
	 * @param array<string, mixed> $patch             The caller's partial payload.
	 * @param bool                 $schemaLookupFails Make the schema lookup throw.
	 * @param string|null          $schemaId          The schema the stored object names.
	 *
	 * @return array<string, mixed>|null The payload as the save pipeline saw it.
	 */
	private function payloadReachingTheSave(
		array $storedObject,
		array $properties,
		array $patch,
		bool $schemaLookupFails = false,
		?string $schemaId = '2'
	): ?array {
		if ($schemaLookupFails === true) {
			$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('schema gone'));
		} else {
			$schema = new Schema();
			$schema->setId(2);
			$schema->setProperties($properties);
			$this->schemaMapper->method('find')->willReturn($schema);
		}

		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setSchema($schemaId);
		$existing->setObject($storedObject);

		$this->objectMapper->method('find')->willReturn($existing);
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$seen = null;
		$this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static function (array $object, ?Schema $schema = null, ?string $uuid = null) use (&$seen): array {
				$seen = $object;

				return [$object, $uuid];
			}
		);

		try {
			$this->service->patchObject(objectId: 'u-1', data: $patch);
		} catch (Throwable $e) {
			// Expected — the rest of the save pipeline is mocked out.
		}

		return $seen;
	}//end payloadReachingTheSave()

	public function testAStringTypedPropertyHoldingJsonSurvivesAPatchThatNeverMentionsIt(): void {
		$stored = '[{"status":"open","at":"2026-09-11"},{"status":"closed","at":"2026-09-12"}]';

		$seen = $this->payloadReachingTheSave(
			// What a read actually hands back: the JSON-looking string decoded.
			['title' => 'Alpha', 'statusHistory' => json_decode($stored, true)],
			['title' => ['type' => 'string'], 'statusHistory' => ['type' => 'string']],
			['title' => 'probe']
		);

		$this->assertNotNull($seen, 'the merged payload reached the save pipeline');
		$this->assertSame('probe', $seen['title']);
		$this->assertSame(
			$stored,
			$seen['statusHistory'],
			'without the re-encode this arrives as an array and validation refuses a patch that never named it'
		);

	}//end testAStringTypedPropertyHoldingJsonSurvivesAPatchThatNeverMentionsIt()

	public function testAnArrayTheCallerSuppliedReachesValidationUnchanged(): void {
		$seen = $this->payloadReachingTheSave(
			['title' => 'Alpha', 'statusHistory' => [['status' => 'open']]],
			['title' => ['type' => 'string'], 'statusHistory' => ['type' => 'string']],
			['statusHistory' => [['status' => 'closed']]]
		);

		$this->assertNotNull($seen);
		$this->assertSame(
			[['status' => 'closed']],
			$seen['statusHistory'],
			'a caller that passes an array for a string property keeps its loud refusal rather than a silent rewrite'
		);

	}//end testAnArrayTheCallerSuppliedReachesValidationUnchanged()

	public function testASchemaThatWillNotResolveLeavesTheMergedDataAlone(): void {
		$seen = $this->payloadReachingTheSave(
			['title' => 'Alpha', 'statusHistory' => [['status' => 'open']]],
			['title' => ['type' => 'string'], 'statusHistory' => ['type' => 'string']],
			['title' => 'probe'],
			schemaLookupFails: true
		);

		$this->assertNotNull($seen);
		$this->assertSame(
			[['status' => 'open']],
			$seen['statusHistory'],
			'no schema, no claim: the caller keeps the behaviour it had before this change'
		);

	}//end testASchemaThatWillNotResolveLeavesTheMergedDataAlone()

	public function testAnObjectWithNoSchemaLeavesTheMergedDataAlone(): void {
		$seen = $this->payloadReachingTheSave(
			['title' => 'Alpha', 'statusHistory' => [['status' => 'open']]],
			['title' => ['type' => 'string'], 'statusHistory' => ['type' => 'string']],
			['title' => 'probe'],
			schemaId: null
		);

		$this->assertNotNull($seen);
		$this->assertSame([['status' => 'open']], $seen['statusHistory']);

	}//end testAnObjectWithNoSchemaLeavesTheMergedDataAlone()

	public function testAContainerThatAnswersWithNoConverterDoesNotFatal(): void {
		// Calling a method on null would turn a patch into a fatal error, which
		// is a worse failure than the refusal this change exists to remove.
		$this->converterAnswer = null;

		$seen = $this->payloadReachingTheSave(
			['title' => 'Alpha', 'statusHistory' => [['status' => 'open']]],
			['title' => ['type' => 'string'], 'statusHistory' => ['type' => 'string']],
			['title' => 'probe']
		);

		$this->assertNotNull($seen, 'the patch ran rather than fatalling');
		$this->assertSame([['status' => 'open']], $seen['statusHistory']);

	}//end testAContainerThatAnswersWithNoConverterDoesNotFatal()

	public function testAnArrayTypedPropertyIsNotEncodedByTheRestore(): void {
		$seen = $this->payloadReachingTheSave(
			['title' => 'Alpha', 'tags' => ['a', 'b']],
			['title' => ['type' => 'string'], 'tags' => ['type' => 'array']],
			['title' => 'probe']
		);

		$this->assertNotNull($seen);
		$this->assertSame(['a', 'b'], $seen['tags'], 'the restore must not reach properties the schema really does call arrays');

	}//end testAnArrayTypedPropertyIsNotEncodedByTheRestore()

	// ── Attribution and enforcement (REQ-OWN-013) ───────────────────────

	public function testTheRbacFlagIsForwardedRatherThanSilentlyDiscarded(): void {
		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setObject(['title' => 'Alpha']);

		$this->stubCascading();
		$this->objectMapper->method('find')->willReturn($existing);
		$this->setProperty('currentSchema', $this->schema);

		$seen = [];
		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true) use (&$seen): void {
				$seen[] = $_rbac;
			}
		);

		try {
			$this->service->patchObject(objectId: 'u-1', data: ['title' => 'Beta'], _rbac: false);
		} catch (Throwable $e) {
			// Expected — the rest of the save pipeline is mocked out.
		}

		$this->assertNotSame([], $seen, 'the permission check ran');
		$this->assertSame([false], array_unique($seen), 'the caller\'s _rbac reached the check instead of being dropped');

	}//end testTheRbacFlagIsForwardedRatherThanSilentlyDiscarded()

	public function testTheMethodAcceptsAnExplicitActingUser(): void {
		$method = $this->reflection->getMethod('patchObject');
		$names = [];
		foreach ($method->getParameters() as $parameter) {
			$names[] = $parameter->getName();
		}

		$this->assertSame(
			['objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser'],
			$names,
			'the signature mirrors saveObject()\'s parameter vocabulary'
		);

		$currentUser = $method->getParameters()[6];
		$this->assertSame(IUser::class, (string)$currentUser->getType()->getName());
		$this->assertTrue($currentUser->allowsNull());

	}//end testTheMethodAcceptsAnExplicitActingUser()

	// ── deleteObject's explicit acting user (REQ-OWN-003, REQ-OWN-012) ──

	public function testDeleteObjectAcceptsAnExplicitActingUserAndDefaultsToTheSession(): void {
		// BY NAME, not by position. This read `$parameters[count - 1]` and so
		// asserted "currentUser is LAST", which is not what the test is about —
		// appending any further optional parameter broke it while the property
		// it means to protect was untouched.
		$found = null;
		foreach ($this->reflection->getMethod('deleteObject')->getParameters() as $parameter) {
			if ($parameter->getName() === 'currentUser') {
				$found = $parameter;
				break;
			}
		}

		$this->assertNotNull($found, 'deleteObject() must accept an explicit acting user');
		$this->assertSame(IUser::class, (string)$found->getType()->getName());
		$this->assertTrue($found->isDefaultValueAvailable());
		$this->assertNull($found->getDefaultValue(), 'null keeps today\'s session-resolved behaviour for every existing caller');

	}//end testDeleteObjectAcceptsAnExplicitActingUserAndDefaultsToTheSession()

	/**
	 * The permanence opt-out exists, is boolean, and defaults to the tombstone.
	 *
	 * The default is the whole point: a soft delete is what makes a mistaken
	 * delete recoverable, so `permanent` must be something a caller reaches for
	 * deliberately rather than something they inherit (openregister#2459).
	 *
	 * @return void
	 */
	public function testDeleteObjectOffersPermanenceAndDefaultsToASoftDelete(): void {
		$found = null;
		foreach ($this->reflection->getMethod('deleteObject')->getParameters() as $parameter) {
			if ($parameter->getName() === 'permanent') {
				$found = $parameter;
				break;
			}
		}

		$this->assertNotNull($found, 'deleteObject() must offer a permanence opt-out');
		$this->assertSame('bool', (string)$found->getType()->getName());
		$this->assertTrue($found->isDefaultValueAvailable());
		$this->assertFalse($found->getDefaultValue(), 'the tombstone stays the default for every existing caller');

	}//end testDeleteObjectOffersPermanenceAndDefaultsToASoftDelete()

	public function testDeleteObjectForwardsTheActingUserIntoThePermissionCheck(): void {
		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setOwner('bob');
		$existing->setObject([]);

		$this->objectMapper->method('find')->willReturn($existing);
		$this->setProperty('currentSchema', $this->schema);
		$this->setProperty('currentRegister', $this->register);

		$seen = null;
		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $userId = null) use (&$seen): void {
				$seen = ['action' => $action, 'userId' => $userId];
			}
		);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');

		try {
			$this->service->deleteObject(
				uuid: 'u-1',
				register: $this->register,
				schema: $this->schema,
				currentUser: $alice
			);
		} catch (Throwable $e) {
			// Expected — the delete handler is mocked out.
		}

		$this->assertSame('delete', $seen['action']);
		$this->assertSame('alice', $seen['userId'], 'a sessionless caller can now be attributed');

	}//end testDeleteObjectForwardsTheActingUserIntoThePermissionCheck()

	public function testDeleteObjectWithoutAnActingUserStillResolvesFromTheSession(): void {
		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setOwner('bob');
		$existing->setObject([]);

		$this->objectMapper->method('find')->willReturn($existing);
		$this->setProperty('currentSchema', $this->schema);
		$this->setProperty('currentRegister', $this->register);

		$seen = 'untouched';
		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $userId = null) use (&$seen): void {
				$seen = $userId;
			}
		);

		try {
			$this->service->deleteObject(uuid: 'u-1', register: $this->register, schema: $this->schema);
		} catch (Throwable $e) {
			// Expected — the delete handler is mocked out.
		}

		$this->assertNull($seen, 'null is still the signal to resolve the subject from IUserSession');

	}//end testDeleteObjectWithoutAnActingUserStillResolvesFromTheSession()
}//end class
