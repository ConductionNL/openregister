<?php

declare(strict_types=1);

/**
 * TransferListService Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for TransferListService.
 */
class TransferListServiceTest extends TestCase
{
    private MagicMapper&MockObject $objectMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private IAppConfig&MockObject $appConfig;
    private INotificationManager&MockObject $notificationManager;
    private LoggerInterface&MockObject $logger;
    private TransferListService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new TransferListService(
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->appConfig,
            $this->notificationManager,
            $this->logger,
        );
    }

    /**
     * Test creating a transfer list.
     */
    public function testCreateTransferList(): void
    {
        $objects = [
            $this->createObjectEntity('uuid-1', 1, 1),
            $this->createObjectEntity('uuid-2', 1, 1),
        ];

        $result = $this->service->createTransferList($objects);

        $this->assertNotEmpty($result['uuid']);
        $this->assertEquals(TransferListService::STATUS_IN_REVIEW, $result['status']);
        $this->assertCount(2, $result['objectReferences']);
        $this->assertEquals(2, $result['objectCount']);
    }

    /**
     * Test creating a transfer list with no objects throws.
     */
    public function testCreateTransferListEmptyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createTransferList([]);
    }

    /**
     * Test approving a transfer list.
     */
    public function testApproveTransferList(): void
    {
        $list = $this->createSampleList();
        $result = $this->service->approveTransferList($list, 'archivist-1');

        $this->assertEquals(TransferListService::STATUS_APPROVED, $result['status']);
        $this->assertEquals('archivist-1', $result['approvalMetadata']['approvedBy']);
    }

    /**
     * Test approving a non-in-review list throws.
     */
    public function testApproveNonReviewListThrows(): void
    {
        $list = $this->createSampleList();
        $list['status'] = TransferListService::STATUS_APPROVED;

        $this->expectException(InvalidArgumentException::class);
        $this->service->approveTransferList($list, 'archivist-1');
    }

    /**
     * Test rejecting a transfer list.
     */
    public function testRejectTransferList(): void
    {
        $list = $this->createSampleList();
        $result = $this->service->rejectTransferList($list, 'archivist-1', 'Not ready for transfer');

        $this->assertEquals(TransferListService::STATUS_REJECTED, $result['status']);
        $this->assertEquals('Not ready for transfer', $result['approvalMetadata']['rejectionReason']);
    }

    /**
     * Test rejecting without reason throws.
     */
    public function testRejectWithoutReasonThrows(): void
    {
        $list = $this->createSampleList();

        $this->expectException(InvalidArgumentException::class);
        $this->service->rejectTransferList($list, 'archivist-1', '');
    }

    /**
     * Test excluding objects from a transfer list.
     */
    public function testExcludeObjects(): void
    {
        $list = $this->createSampleList();
        $result = $this->service->excludeObjects($list, ['uuid-1'], 'Metadata incomplete');

        $this->assertCount(1, $result['exclusions']);
        $this->assertCount(1, $result['objectReferences']);
        $this->assertEquals(1, $result['objectCount']);
        $this->assertEquals('uuid-2', $result['objectReferences'][0]['uuid']);
    }

    /**
     * Test getting objects on active transfer lists.
     */
    public function testGetObjectsOnActiveTransferLists(): void
    {
        $lists = [
            [
                'status' => TransferListService::STATUS_IN_REVIEW,
                'objectReferences' => [
                    ['uuid' => 'uuid-1'],
                    ['uuid' => 'uuid-2'],
                ],
            ],
            [
                'status' => TransferListService::STATUS_REJECTED,
                'objectReferences' => [
                    ['uuid' => 'uuid-3'],
                ],
            ],
        ];

        $result = $this->service->getObjectsOnActiveTransferLists($lists);

        $this->assertCount(2, $result);
        $this->assertContains('uuid-1', $result);
        $this->assertContains('uuid-2', $result);
        $this->assertNotContains('uuid-3', $result);
    }

    /**
     * Test notification sending.
     */
    public function testNotifyArchivists(): void
    {
        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->expects($this->once())
            ->method('createNotification')
            ->willReturn($notification);

        $this->notificationManager->expects($this->once())
            ->method('notify')
            ->with($notification);

        $list = $this->createSampleList();
        $this->service->notifyArchivists($list);
    }

    /**
     * Create a sample transfer list.
     *
     * @return array<string,mixed> Sample list data.
     */
    private function createSampleList(): array
    {
        return [
            'uuid' => 'transfer-uuid-1',
            'status' => TransferListService::STATUS_IN_REVIEW,
            'objectReferences' => [
                ['uuid' => 'uuid-1', 'schema' => 1, 'register' => 1],
                ['uuid' => 'uuid-2', 'schema' => 1, 'register' => 1],
            ],
            'exclusions' => [],
            'objectCount' => 2,
        ];
    }

    /**
     * Create a mock ObjectEntity.
     *
     * @param string   $uuid     The UUID.
     * @param int|null $schema   The schema ID.
     * @param int|null $register The register ID.
     *
     * @return ObjectEntity&MockObject The mock object.
     */
    private function createObjectEntity(string $uuid, ?int $schema = null, ?int $register = null): ObjectEntity&MockObject
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('getUuid')->willReturn($uuid);
        $object->method('getSchema')->willReturn($schema);
        $object->method('getRegister')->willReturn($register);

        return $object;
    }
}
