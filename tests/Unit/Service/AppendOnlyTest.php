<?php

declare(strict_types=1);

/**
 * AppendOnly Schema Flag Tests
 *
 * Tests that appendOnly: true on a schema allows INSERT but rejects UPDATE and DELETE
 * with AppendOnlyException. Also verifies that schemas without the flag are unaffected.
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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
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
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for the appendOnly schema flag enforcement in ObjectService.
 *
 * Scenarios:
 * 1. saveObject (no UUID) on appendOnly schema → allowed (INSERT)
 * 2. saveObject (with UUID) on appendOnly schema → throws AppendOnlyException (UPDATE)
 * 3. deleteObject on appendOnly schema → throws AppendOnlyException (DELETE)
 * 4. saveObject (with UUID) on normal schema → allowed (no flag)
 * 5. deleteObject on normal schema → allowed (no flag)
 * 6. AppendOnlyException toResponseBody() returns expected structure
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AppendOnlyTest extends TestCase
{

    /** @var ObjectService */
    private ObjectService $service;

    /** @var ReflectionClass<ObjectService> */
    private ReflectionClass $reflection;

    /** @var MockObject&SaveObject */
    private MockObject $saveHandler;

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->saveHandler        = $this->createMock(SaveObject::class);
        $this->deleteHandler      = $this->createMock(DeleteObject::class);
        $this->objectMapper       = $this->createMock(MagicMapper::class);
        $this->cascadingHandler   = $this->createMock(CascadingHandler::class);
        $this->dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);

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
            $this->createMock(PerformanceHandler::class),
            $this->createMock(PermissionHandler::class),
            $this->createMock(RenderObject::class),
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
            $this->createMock(IAppContainer::class)
        );

        $this->reflection = new ReflectionClass(ObjectService::class);
    }//end setUp()

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Inject a value into a private/protected property via reflection.
     *
     * @param string $name  Property name
     * @param mixed  $value Value to set
     *
     * @return void
     */
    private function setProperty(string $name, mixed $value): void
    {
        $prop = $this->reflection->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->service, $value);
    }//end setProperty()

    /**
     * Build a Schema entity with the desired appendOnly flag.
     *
     * @param bool        $appendOnly Whether to mark the schema as append-only
     * @param string|null $slug       Optional slug for the schema
     *
     * @return Schema
     */
    private function makeSchema(bool $appendOnly, ?string $slug='test-schema'): Schema
    {
        $schema = new Schema();
        $schema->setId(99);
        $schema->setAppendOnly($appendOnly);
        if ($slug !== null) {
            $schema->setSlug($slug);
        }

        return $schema;
    }//end makeSchema()

    // =========================================================================
    // 1. INSERT allowed on append-only schema (no UUID → new object)
    // =========================================================================

    /**
     * saveObject without a UUID on an append-only schema must reach the SaveObject
     * handler without throwing (INSERT is permitted).
     *
     * @return void
     */
    public function testSaveObjectInsertAllowedOnAppendOnlySchema(): void
    {
        $schema = $this->makeSchema(appendOnly: true);

        // Inject the schema context so ObjectService believes it is append-only.
        $this->setProperty('currentSchema', $schema);
        $this->setProperty('currentRegister', null);

        // SaveObject handler returns a stub entity on success.
        $savedEntity = new ObjectEntity();
        $savedEntity->setUuid('new-uuid-1234');

        $this->saveHandler->method('saveObject')->willReturn($savedEntity);
        $this->saveHandler->method('applyAlwaysDefaults')->willReturnArgument(1);
        // clearAllCaches() returns void — left unconfigured (a void mock method is a no-op).

        // ObjectMapper: no existing object with this UUID (it's a new insert).
        $this->objectMapper->method('find')->willThrowException(
            new \OCP\AppFramework\Db\DoesNotExistException('not found')
        );

        // Should NOT throw — INSERT is permitted even on append-only schemas.
        $result = $this->service->saveObject(
            object: ['name' => 'entry'],
            uuid: null
        );

        $this->assertSame($savedEntity, $result);
    }//end testSaveObjectInsertAllowedOnAppendOnlySchema()

    // =========================================================================
    // 2. UPDATE rejected on append-only schema (UUID present)
    // =========================================================================

    /**
     * saveObject with a UUID on an append-only schema must throw AppendOnlyException.
     *
     * @return void
     */
    public function testSaveObjectUpdateRejectedOnAppendOnlySchema(): void
    {
        $schema = $this->makeSchema(appendOnly: true, slug: 'xapi-statement');
        $this->setProperty('currentSchema', $schema);
        $this->setProperty('currentRegister', null);

        // The object exists in the DB (it's an update).
        $existingEntity = new ObjectEntity();
        $existingEntity->setUuid('existing-uuid-abc');
        $this->objectMapper->method('find')->willReturn($existingEntity);

        $this->expectException(AppendOnlyException::class);
        $this->expectExceptionMessageMatches('/SCHEMA_APPEND_ONLY/');

        $this->service->saveObject(
            object: ['name' => 'updated-entry'],
            uuid: 'existing-uuid-abc'
        );
    }//end testSaveObjectUpdateRejectedOnAppendOnlySchema()

    // =========================================================================
    // 3. DELETE rejected on append-only schema
    // =========================================================================

    /**
     * deleteObject on an append-only schema must throw AppendOnlyException.
     *
     * @return void
     */
    public function testDeleteObjectRejectedOnAppendOnlySchema(): void
    {
        $schema = $this->makeSchema(appendOnly: true, slug: 'attestation');
        $this->setProperty('currentSchema', $schema);

        $this->expectException(AppendOnlyException::class);
        $this->expectExceptionCode(405);

        $this->service->deleteObject(uuid: 'some-uuid-xyz');
    }//end testDeleteObjectRejectedOnAppendOnlySchema()

    // =========================================================================
    // 4. UPDATE allowed on ordinary (non-append-only) schema
    // =========================================================================

    /**
     * saveObject with UUID on a schema without appendOnly must pass through to
     * SaveObject handler without throwing.
     *
     * @return void
     */
    public function testSaveObjectUpdateAllowedOnOrdinarySchema(): void
    {
        $schema = $this->makeSchema(appendOnly: false, slug: 'ordinary');
        $this->setProperty('currentSchema', $schema);
        $this->setProperty('currentRegister', null);

        $savedEntity = new ObjectEntity();
        $savedEntity->setUuid('ordinary-uuid-456');

        $this->saveHandler->method('saveObject')->willReturn($savedEntity);
        $this->saveHandler->method('applyAlwaysDefaults')->willReturnArgument(1);
        // clearAllCaches() returns void — left unconfigured (a void mock method is a no-op).

        // Existing object found — normal UPDATE path.
        $existingEntity = new ObjectEntity();
        $existingEntity->setUuid('ordinary-uuid-456');
        $this->objectMapper->method('find')->willReturn($existingEntity);

        // Must not throw.
        $result = $this->service->saveObject(
            object: ['name' => 'updated'],
            uuid: 'ordinary-uuid-456'
        );

        $this->assertSame($savedEntity, $result);
    }//end testSaveObjectUpdateAllowedOnOrdinarySchema()

    // =========================================================================
    // 5. DELETE allowed on ordinary (non-append-only) schema
    // =========================================================================

    /**
     * deleteObject on a schema without appendOnly must NOT throw AppendOnlyException
     * and must delegate to the deleteHandler.
     *
     * @return void
     */
    public function testDeleteObjectAllowedOnOrdinarySchema(): void
    {
        $schema = $this->makeSchema(appendOnly: false, slug: 'ordinary');
        $this->setProperty('currentSchema', $schema);

        // Mock the object mapper for the permission check inside deleteObject.
        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('del-uuid-789');
        $objectEntity->setRetention([]);
        $this->objectMapper->method('find')->willReturn($objectEntity);

        // deleteHandler should be called and returns true.
        $this->deleteHandler
            ->expects($this->once())
            ->method('deleteObject')
            ->willReturn(true);

        $result = $this->service->deleteObject(uuid: 'del-uuid-789');

        $this->assertTrue($result);
    }//end testDeleteObjectAllowedOnOrdinarySchema()

    // =========================================================================
    // 6. AppendOnlyException response body structure
    // =========================================================================

    /**
     * AppendOnlyException::toResponseBody() must return the expected keys/values.
     *
     * @return void
     */
    public function testAppendOnlyExceptionResponseBody(): void
    {
        $exception = new AppendOnlyException(
            schemaIdentifier: 'xapi-statement',
            operation: 'delete'
        );

        $body = $exception->toResponseBody();

        $this->assertSame('SCHEMA_APPEND_ONLY', $body['error']);
        $this->assertSame('xapi-statement', $body['schema']);
        $this->assertSame('delete', $body['operation']);
        $this->assertStringContainsString('xapi-statement', $body['message']);
        $this->assertSame(405, $exception->getCode());
    }//end testAppendOnlyExceptionResponseBody()

    // =========================================================================
    // 7. Schema::isAppendOnly() reflects setAppendOnly()
    // =========================================================================

    /**
     * Schema entity must expose isAppendOnly() that reflects the stored flag.
     *
     * @return void
     */
    public function testSchemaIsAppendOnlyGetter(): void
    {
        $schema = new Schema();

        // Default must be false (backward compatible).
        $this->assertFalse($schema->isAppendOnly());

        $schema->setAppendOnly(true);
        $this->assertTrue($schema->isAppendOnly());

        $schema->setAppendOnly(false);
        $this->assertFalse($schema->isAppendOnly());
    }//end testSchemaIsAppendOnlyGetter()
}//end class
