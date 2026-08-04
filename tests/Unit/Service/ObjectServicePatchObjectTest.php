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
class ObjectServicePatchObjectTest extends TestCase
{

    private ObjectService $service;

    private ReflectionClass $reflection;

    /** @var MockObject&MagicMapper */
    private $objectMapper;

    /** @var MockObject&CascadingHandler */
    private $cascadingHandler;

    /** @var MockObject&PermissionHandler */
    private $permissionHandler;

    private Register $register;

    private Schema $schema;


    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper      = $this->createMock(MagicMapper::class);
        $this->cascadingHandler  = $this->createMock(CascadingHandler::class);
        $this->permissionHandler = $this->createMock(PermissionHandler::class);

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
            $this->createMock(DateTimeNormalizer::class),
            $this->createMock(IAppContainer::class),
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
    private function merge(array $stored, array $patch): array
    {
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
    private function stubCascading(): void
    {
        $this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
            static fn (array $object, ?Schema $schema=null, ?string $uuid=null): array => [$object, $uuid]
        );

    }//end stubCascading()


    private function setProperty(string $name, mixed $value): void
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->service, $value);

    }//end setProperty()


    // ── Merge rules (REQ-OWN-013) ───────────────────────────────────────


    public function testAnOmittedKeyIsPreserved(): void
    {
        $merged = $this->merge(['title' => 'Alpha', 'status' => 'open'], ['status' => 'closed']);

        $this->assertSame(['title' => 'Alpha', 'status' => 'closed'], $merged);

    }//end testAnOmittedKeyIsPreserved()


    public function testAnExplicitNullClearsAndLeavesTheRestAlone(): void
    {
        $merged = $this->merge(['title' => 'Alpha', 'status' => 'open'], ['title' => null]);

        $this->assertArrayHasKey('title', $merged);
        $this->assertNull($merged['title']);
        $this->assertSame('open', $merged['status']);

    }//end testAnExplicitNullClearsAndLeavesTheRestAlone()


    public function testNestedObjectsMergeRatherThanReplace(): void
    {
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


    public function testANestedMergeRecursesMoreThanOneLevel(): void
    {
        $merged = $this->merge(
            ['a' => ['b' => ['c' => 1, 'd' => 2]]],
            ['a' => ['b' => ['d' => 3]]]
        );

        $this->assertSame(['a' => ['b' => ['c' => 1, 'd' => 3]]], $merged);

    }//end testANestedMergeRecursesMoreThanOneLevel()


    public function testArraysAreReplacedWholesaleAndNeverElementMerged(): void
    {
        $merged = $this->merge(['tags' => ['a', 'b', 'c']], ['tags' => ['x']]);

        $this->assertSame(['tags' => ['x']], $merged, 'a positional merge would corrupt any reordered list');

    }//end testArraysAreReplacedWholesaleAndNeverElementMerged()


    public function testAnEmptyListReplacesRatherThanBeingTreatedAsNoChange(): void
    {
        $merged = $this->merge(['tags' => ['a', 'b']], ['tags' => []]);

        $this->assertSame(['tags' => []], $merged);

    }//end testAnEmptyListReplacesRatherThanBeingTreatedAsNoChange()


    public function testANewKeyIsAdded(): void
    {
        $merged = $this->merge(['title' => 'Alpha'], ['status' => 'open']);

        $this->assertSame(['title' => 'Alpha', 'status' => 'open'], $merged);

    }//end testANewKeyIsAdded()


    public function testAnObjectReplacingAScalarDoesNotAttemptAMerge(): void
    {
        $merged = $this->merge(['contact' => 'a@example.org'], ['contact' => ['email' => 'b@example.org']]);

        $this->assertSame(['contact' => ['email' => 'b@example.org']], $merged);

    }//end testAnObjectReplacingAScalarDoesNotAttemptAMerge()


    // ── Identifier resolution (REQ-OWN-013) ─────────────────────────────


    public function testAUuidIdentifierIsNotCastToInt(): void
    {
        $uuid = '9f1c2b7e-3a4d-4c8e-9b21-5f6a7c8d9e01';
        $seen = null;

        $existing = new ObjectEntity();
        $existing->setUuid($uuid);
        $existing->setObject(['title' => 'Alpha']);

        $this->stubCascading();
        $this->objectMapper->method('find')->willReturnCallback(
            function (string | int $identifier, ?Register $register=null, ?Schema $schema=null) use (&$seen, $existing): ObjectEntity {
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


    public function testTheMergedResultIsWhatTravelsOnToTheSave(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('u-1');
        $existing->setObject(['title' => 'Alpha', 'status' => 'draft', 'contact' => ['name' => 'A', 'email' => 'a@example.org']]);

        $this->objectMapper->method('find')->willReturn($existing);
        $this->setProperty('currentRegister', $this->register);
        $this->setProperty('currentSchema', $this->schema);

        $seen = null;
        $this->cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
            static function (array $object, ?Schema $schema=null, ?string $uuid=null) use (&$seen): array {
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


    // ── Attribution and enforcement (REQ-OWN-013) ───────────────────────


    public function testTheRbacFlagIsForwardedRatherThanSilentlyDiscarded(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('u-1');
        $existing->setObject(['title' => 'Alpha']);

        $this->stubCascading();
        $this->objectMapper->method('find')->willReturn($existing);
        $this->setProperty('currentSchema', $this->schema);

        $seen = [];
        $this->permissionHandler->method('checkPermission')->willReturnCallback(
            static function (Schema $schema, string $action, ?string $userId=null, ?string $objectOwner=null, bool $_rbac=true) use (&$seen): void {
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


    public function testTheMethodAcceptsAnExplicitActingUser(): void
    {
        $method = $this->reflection->getMethod('patchObject');
        $names  = [];
        foreach ($method->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        $this->assertSame(
            ['objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy', 'currentUser'],
            $names,
            'the signature mirrors saveObject()\'s parameter vocabulary'
        );

        $currentUser = $method->getParameters()[6];
        $this->assertSame(IUser::class, (string) $currentUser->getType()->getName());
        $this->assertTrue($currentUser->allowsNull());

    }//end testTheMethodAcceptsAnExplicitActingUser()


    // ── deleteObject's explicit acting user (REQ-OWN-003, REQ-OWN-012) ──


    public function testDeleteObjectAcceptsAnExplicitActingUserAndDefaultsToTheSession(): void
    {
        $method     = $this->reflection->getMethod('deleteObject');
        $parameters = $method->getParameters();
        $last       = $parameters[(count($parameters) - 1)];

        $this->assertSame('currentUser', $last->getName());
        $this->assertSame(IUser::class, (string) $last->getType()->getName());
        $this->assertTrue($last->isDefaultValueAvailable());
        $this->assertNull($last->getDefaultValue(), 'null keeps today\'s session-resolved behaviour for every existing caller');

    }//end testDeleteObjectAcceptsAnExplicitActingUserAndDefaultsToTheSession()


    public function testDeleteObjectForwardsTheActingUserIntoThePermissionCheck(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('u-1');
        $existing->setOwner('bob');
        $existing->setObject([]);

        $this->objectMapper->method('find')->willReturn($existing);
        $this->setProperty('currentSchema', $this->schema);
        $this->setProperty('currentRegister', $this->register);

        $seen = null;
        $this->permissionHandler->method('checkPermission')->willReturnCallback(
            static function (Schema $schema, string $action, ?string $userId=null) use (&$seen): void {
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


    public function testDeleteObjectWithoutAnActingUserStillResolvesFromTheSession(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('u-1');
        $existing->setOwner('bob');
        $existing->setObject([]);

        $this->objectMapper->method('find')->willReturn($existing);
        $this->setProperty('currentSchema', $this->schema);
        $this->setProperty('currentRegister', $this->register);

        $seen = 'untouched';
        $this->permissionHandler->method('checkPermission')->willReturnCallback(
            static function (Schema $schema, string $action, ?string $userId=null) use (&$seen): void {
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
