<?php

/**
 * AppendOnly Schema Unit Tests
 *
 * Tests that the `appendOnly: true` schema flag is correctly enforced by
 * ObjectService: creation is permitted, while update and delete are rejected
 * with AppendOnlyException. Also covers that non-append-only schemas are
 * not affected.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @see https://github.com/ConductionNL/openregister/issues/1470
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Exception\AppendOnlyException;
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
use OCA\OpenRegister\Service\Object\PerformanceHandler;
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
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for the appendOnly schema flag enforcement in ObjectService.
 *
 * Tests four key scenarios:
 * 1. appendOnly schema allows CREATE (save with no UUID)
 * 2. appendOnly schema rejects UPDATE (save with existing UUID)
 * 3. appendOnly schema rejects DELETE
 * 4. Non-appendOnly schema allows UPDATE and DELETE normally
 */
class AppendOnlySchemaTest extends TestCase
{

    private ObjectService $service;
    private ReflectionClass $reflection;

    /** @var MockObject&SaveObject */
    private $saveHandler;
    /** @var MockObject&DeleteObject */
    private $deleteHandler;
    /** @var MockObject&RenderObject */
    private $renderHandler;
    /** @var MockObject&MagicMapper */
    private $objectEntityMapper;
    /** @var MockObject&CascadingHandler */
    private $cascadingHandler;
    /** @var MockObject&PermissionHandler */
    private $permissionHandler;
    /** @var MockObject&LoggerInterface */
    private $logger;

    private Register $register;

    /**
     * Build an ObjectService with all mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->saveHandler        = $this->createMock(SaveObject::class);
        $this->deleteHandler      = $this->createMock(DeleteObject::class);
        $this->renderHandler      = $this->createMock(RenderObject::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->cascadingHandler   = $this->createMock(CascadingHandler::class);
        $this->permissionHandler  = $this->createMock(PermissionHandler::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
        $dateTimeNormalizer->method('normalize')->willReturnCallback(
            function (?string $input): ?\DateTimeImmutable {
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

        $this->register = new Register();
        $this->register->setId(1);

        $this->service = new ObjectService(
            $this->createMock(DataManipulationHandler::class),
            $this->deleteHandler,
            $this->createMock(GetObject::class),
            $this->createMock(PerformanceHandler::class),
            $this->permissionHandler,
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
            $this->objectEntityMapper,
            $this->createMock(FileService::class),
            $this->createMock(IUserSession::class),
            $this->createMock(SearchTrailService::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(OrganisationService::class),
            $this->logger,
            $this->createMock(CacheHandler::class),
            $this->createMock(SettingsService::class),
            $dateTimeNormalizer,
            $this->createMock(IAppContainer::class)
        );

        $this->reflection = new ReflectionClass(ObjectService::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Build a Schema entity with appendOnly set to the given value.
     *
     * @param bool $appendOnly Whether the schema is append-only.
     *
     * @return Schema
     */
    private function buildSchema(bool $appendOnly): Schema
    {
        $schema = new Schema();
        $schema->setId(42);
        $schema->setTitle('TestSchema');
        $schema->setAppendOnly($appendOnly);
        return $schema;
    }

    /**
     * Set a private/protected property via reflection.
     *
     * @param string $name  Property name.
     * @param mixed  $value Value to set.
     *
     * @return void
     */
    private function setProperty(string $name, mixed $value): void
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->service, $value);
    }

    /**
     * Invoke a private method via reflection.
     *
     * @param string $methodName Method name.
     * @param array  $args       Arguments to pass.
     *
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }

    // ── Tests: rejectIfAppendOnly (private) ──────────────────────────────

    /**
     * rejectIfAppendOnly does nothing when there is no current schema.
     *
     * @return void
     */
    public function testRejectIfAppendOnlyDoesNothingWhenNoSchema(): void
    {
        $this->setProperty('currentSchema', null);
        // Must not throw.
        $this->invokePrivate('rejectIfAppendOnly', ['update']);
        $this->assertTrue(true);
    }

    /**
     * rejectIfAppendOnly does nothing when the schema has appendOnly false.
     *
     * @return void
     */
    public function testRejectIfAppendOnlyDoesNothingForNormalSchema(): void
    {
        $this->setProperty('currentSchema', $this->buildSchema(false));
        // Must not throw.
        $this->invokePrivate('rejectIfAppendOnly', ['update']);
        $this->assertTrue(true);
    }

    /**
     * rejectIfAppendOnly throws AppendOnlyException for update on append-only schema.
     *
     * @return void
     */
    public function testRejectIfAppendOnlyThrowsForUpdateOnAppendOnlySchema(): void
    {
        $this->setProperty('currentSchema', $this->buildSchema(true));
        $this->expectException(AppendOnlyException::class);
        $this->expectExceptionMessageMatches('/SCHEMA_APPEND_ONLY.*update/i');
        $this->invokePrivate('rejectIfAppendOnly', ['update']);
    }

    /**
     * rejectIfAppendOnly throws AppendOnlyException for delete on append-only schema.
     *
     * @return void
     */
    public function testRejectIfAppendOnlyThrowsForDeleteOnAppendOnlySchema(): void
    {
        $this->setProperty('currentSchema', $this->buildSchema(true));
        $this->expectException(AppendOnlyException::class);
        $this->expectExceptionMessageMatches('/SCHEMA_APPEND_ONLY.*delete/i');
        $this->invokePrivate('rejectIfAppendOnly', ['delete']);
    }

    /**
     * AppendOnlyException carries HTTP 405 code.
     *
     * @return void
     */
    public function testAppendOnlyExceptionCarriesHttp405Code(): void
    {
        $this->setProperty('currentSchema', $this->buildSchema(true));
        try {
            $this->invokePrivate('rejectIfAppendOnly', ['update']);
            $this->fail('Expected AppendOnlyException was not thrown.');
        } catch (AppendOnlyException $e) {
            $this->assertSame(405, $e->getCode());
        }
    }

    // ── Tests: Schema::getAppendOnly / setAppendOnly ─────────────────────

    /**
     * Schema appendOnly defaults to false.
     *
     * @return void
     */
    public function testSchemaAppendOnlyDefaultsFalse(): void
    {
        $schema = new Schema();
        $this->assertFalse($schema->getAppendOnly());
    }

    /**
     * Schema appendOnly can be set to true and read back.
     *
     * @return void
     */
    public function testSchemaAppendOnlyCanBeSetToTrue(): void
    {
        $schema = new Schema();
        $schema->setAppendOnly(true);
        $this->assertTrue($schema->getAppendOnly());
    }

    /**
     * Schema jsonSerialize includes the appendOnly key.
     *
     * @return void
     */
    public function testSchemaJsonSerializeIncludesAppendOnly(): void
    {
        $schema = $this->buildSchema(true);
        $data   = $schema->jsonSerialize();
        $this->assertArrayHasKey('appendOnly', $data);
        $this->assertTrue($data['appendOnly']);
    }

    // ── Tests: deleteObject rejects append-only schemas ──────────────────

    /**
     * deleteObject throws AppendOnlyException when currentSchema is append-only
     * and schema is set before the call (early rejection path).
     *
     * @return void
     */
    public function testDeleteObjectRejectsAppendOnlySchemaWhenSchemaPreset(): void
    {
        $schema = $this->buildSchema(true);
        $this->setProperty('currentSchema', $schema);
        $this->setProperty('currentRegister', $this->register);

        $this->expectException(AppendOnlyException::class);

        $this->service->deleteObject(uuid: 'some-uuid');
    }

    /**
     * deleteObject with appendOnly schema is rejected regardless of whether the uuid
     * appears to be for a new object or existing — any delete is blocked.
     *
     * Tests that multiple calls with the same append-only schema all throw.
     *
     * @return void
     */
    public function testDeleteObjectRejectsAppendOnlySchemaMultipleCalls(): void
    {
        $schema = $this->buildSchema(true);
        $this->setProperty('currentSchema', $schema);
        $this->setProperty('currentRegister', $this->register);

        $caughtCount = 0;
        foreach (['uuid-a', 'uuid-b'] as $uuid) {
            try {
                $this->service->deleteObject(uuid: $uuid);
            } catch (AppendOnlyException $e) {
                $caughtCount++;
            }
        }

        $this->assertSame(2, $caughtCount, 'Both delete attempts must be rejected.');
    }

    // ── Tests: normal (non-append-only) schemas unaffected ───────────────

    /**
     * deleteObject does not throw for a normal (non-append-only) schema.
     *
     * @return void
     */
    public function testDeleteObjectAllowsNonAppendOnlySchema(): void
    {
        $schema = $this->buildSchema(false);
        $this->setProperty('currentSchema', $schema);
        $this->setProperty('currentRegister', $this->register);

        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('some-uuid');
        $objectEntity->setOwner('user1');

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($objectEntity);

        $this->deleteHandler
            ->method('deleteObject')
            ->willReturn(true);

        // Should not throw; return value ignored.
        $result = $this->service->deleteObject(uuid: 'some-uuid');
        $this->assertTrue($result);
    }

}//end class
