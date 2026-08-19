<?php
/**
 * Regression tests for bulk-write invariants: append-only enforcement and
 * CREATE-vs-UPDATE action derivation on the bulk path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openregister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The bulk pipeline is a SEPARATE code path from the single-object save; it
 * does not delegate to SaveObject. Two invariants the single-object path
 * guarantees were therefore absent here:
 *
 *  - append-only: a bulk POST carrying an existing uuid silently rewrote the
 *    row that ObjectService::saveObject would have rejected;
 *  - action derivation: every row was authorized as 'create', so a caller with
 *    create-but-not-update rights could rewrite existing rows in bulk.
 */
class Wave12BulkSafeguardsTest extends TestCase
{
    private MagicMapper&MockObject $objectMapper;
    private PermissionHandler&MockObject $permissionHandler;
    private LoggerInterface&MockObject $logger;
    private SaveObjects $service;

    protected function setUp(): void
    {
        $this->objectMapper      = $this->createMock(MagicMapper::class);
        $this->permissionHandler = $this->createMock(PermissionHandler::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $this->service = new SaveObjects(
            $this->objectMapper,
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(SaveObject::class),
            $userSession,
            $this->createMock(OrganisationService::class),
            $this->logger,
            $this->createMock(IGroupManager::class),
            $this->permissionHandler,
            $this->createMock(ValidateObject::class),
            $this->createMock(IEventDispatcher::class),
            $this->createMock(AuditTrailMapper::class)
        );
    }

    /**
     * Make $uuids resolve as already-existing rows.
     */
    private function existing(array $uuids): void
    {
        $entities = [];
        foreach ($uuids as $uuid) {
            $entity = new ObjectEntity();
            $entity->setUuid($uuid);
            $entities[] = $entity;
        }

        $this->objectMapper->method('findMultiple')->willReturn($entities);
    }

    private function schema(bool $appendOnly): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('test-schema');
        $schema->setAppendOnly($appendOnly);
        return $schema;
    }

    /**
     * Drive the private per-row guard directly.
     */
    private function guard(array $rows, Schema $schema, array &$result): array
    {
        $result = [
            'invalid'    => [],
            'statistics' => [
                'invalid' => 0,
                'errors'  => 0,
            ],
        ];

        $method = new ReflectionMethod(SaveObjects::class, 'enforceChunkGuards');
        $method->setAccessible(true);

        return $method->invokeArgs(
            $this->service,
            [$rows, [1 => $schema], true, false, &$result]
        );
    }

    public function testUpdatingAnExistingRowOnAnAppendOnlySchemaIsRejected(): void
    {
        // The live gap: this row used to sail through to an unconditional upsert.
        $this->existing(['aaaa-1111']);
        $this->permissionHandler->method('hasPermission')->willReturn(true);

        $rows   = [['uuid' => 'aaaa-1111', 'schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $allowed = $this->guard($rows, $this->schema(appendOnly: true), $result);

        $this->assertSame([], $allowed, 'the append-only update must not reach persistence');
        $this->assertSame(1, $result['statistics']['invalid']);
        $this->assertSame('AppendOnlyException', $result['invalid'][0]['type']);
    }

    public function testInsertingANewRowOnAnAppendOnlySchemaIsAllowed(): void
    {
        // Append-only rejects UPDATE, not INSERT. Rejecting inserts would make
        // the schema unusable.
        $this->existing([]);
        $this->permissionHandler->method('hasPermission')->willReturn(true);

        $rows   = [['uuid' => 'bbbb-2222', 'schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $allowed = $this->guard($rows, $this->schema(appendOnly: true), $result);

        $this->assertCount(1, $allowed);
        $this->assertSame(0, $result['statistics']['invalid']);
    }

    public function testAClientSuppliedUuidForANewObjectIsACreateNotAnUpdate(): void
    {
        // uuid presence is NOT a proxy for "update". A caller may choose the id
        // of a brand-new object; treating that as an update would reject a
        // legitimate insert on an append-only schema.
        $this->existing([]);
        $this->permissionHandler->method('hasPermission')->willReturn(true);

        $rows   = [['uuid' => 'cccc-3333', 'schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $allowed = $this->guard($rows, $this->schema(appendOnly: true), $result);

        $this->assertCount(1, $allowed, 'a create with a chosen uuid must not be treated as an update');
    }

    public function testAnExistingRowIsAuthorizedAsUpdateNotCreate(): void
    {
        // The privilege gap: every row used to be authorized as 'create'.
        $this->existing(['dddd-4444']);

        $this->permissionHandler->expects($this->once())
            ->method('hasPermission')
            ->with(
                $this->anything(),
                'update'
            )
            ->willReturn(true);

        $rows   = [['uuid' => 'dddd-4444', 'schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $this->guard($rows, $this->schema(appendOnly: false), $result);
    }

    public function testANewRowIsAuthorizedAsCreate(): void
    {
        $this->existing([]);

        $this->permissionHandler->expects($this->once())
            ->method('hasPermission')
            ->with(
                $this->anything(),
                'create'
            )
            ->willReturn(true);

        $rows   = [['schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $this->guard($rows, $this->schema(appendOnly: false), $result);
    }

    public function testACallerDeniedUpdateCannotRewriteAnExistingRowInBulk(): void
    {
        $this->existing(['eeee-5555']);
        $this->permissionHandler->method('hasPermission')->willReturn(false);

        $rows   = [['uuid' => 'eeee-5555', 'schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $allowed = $this->guard($rows, $this->schema(appendOnly: false), $result);

        $this->assertSame([], $allowed);
        $this->assertSame('PermissionDeniedException', $result['invalid'][0]['type']);
        $this->assertStringContainsString('update', $result['invalid'][0]['error']);
    }

    public function testAppendOnlyRejectionIsPerRowNotPerChunk(): void
    {
        // One bad row must not fail the whole batch — bulk save is partial by
        // contract, and the good insert has to land.
        $this->existing(['ffff-6666']);
        $this->permissionHandler->method('hasPermission')->willReturn(true);

        $rows = [
            ['uuid' => 'ffff-6666', 'schema' => 1, 'object' => ['x' => 1]],
            ['uuid' => 'ffff-7777', 'schema' => 1, 'object' => ['x' => 2]],
        ];
        $result  = [];
        $allowed = $this->guard($rows, $this->schema(appendOnly: true), $result);

        $this->assertCount(1, $allowed);
        $this->assertSame('ffff-7777', $allowed[0]['uuid']);
        $this->assertSame(1, $result['statistics']['invalid']);
    }

    public function testRowsWithoutAUuidAreNeverQueriedForExistence(): void
    {
        // Perf contract: a chunk of pure inserts must not hit the database to
        // discover that none of them exist.
        $this->objectMapper->expects($this->never())->method('findMultiple');
        $this->permissionHandler->method('hasPermission')->willReturn(true);

        $rows   = [['schema' => 1, 'object' => ['x' => 1]]];
        $result = [];
        $this->guard($rows, $this->schema(appendOnly: false), $result);
    }
}
