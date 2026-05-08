<?php

declare(strict_types=1);

/**
 * LegalHoldService Unit Tests
 *
 * Tests the legal hold management service.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for LegalHoldService
 */
class LegalHoldServiceTest extends TestCase
{
    private MagicMapper&MockObject $objectMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private IUserSession&MockObject $userSession;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;
    private LegalHoldService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper     = $this->createMock(MagicMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->jobList          = $this->createMock(IJobList::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new LegalHoldService(
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->userSession,
            $this->jobList,
            $this->logger
        );
    }

    /**
     * Helper to set up a mock user.
     */
    private function setupMockUser(string $uid = 'test-user'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    /**
     * Helper to create a mock ObjectEntity with retention.
     */
    private function createMockObject(array $retention = []): ObjectEntity&MockObject
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('getRetention')->willReturn($retention);
        $object->method('getUuid')->willReturn('test-uuid-123');
        return $object;
    }

    /**
     * Test placing a legal hold on an object.
     */
    public function testPlaceHold(): void
    {
        $this->setupMockUser('archivaris-1');

        $object = $this->createMockObject();
        $object->expects($this->once())
            ->method('setRetention')
            ->with($this->callback(function (array $retention): bool {
                return $retention['legalHold']['active'] === true
                    && $retention['legalHold']['reason'] === 'WOO-verzoek 2025-0142'
                    && $retention['legalHold']['placedBy'] === 'archivaris-1';
            }));

        $this->objectMapper->expects($this->once())->method('update');

        $result = $this->service->placeHold($object, 'WOO-verzoek 2025-0142');
        $this->assertSame($object, $result);
    }

    /**
     * Test releasing a legal hold preserves history.
     */
    public function testReleaseHold(): void
    {
        $this->setupMockUser('archivaris-2');

        $object = $this->createMockObject([
            'legalHold' => [
                'active'     => true,
                'reason'     => 'WOO-verzoek 2025-0142',
                'placedBy'   => 'archivaris-1',
                'placedDate' => '2026-01-01T00:00:00+00:00',
                'history'    => [],
            ],
        ]);

        $object->expects($this->once())
            ->method('setRetention')
            ->with($this->callback(function (array $retention): bool {
                return $retention['legalHold']['active'] === false
                    && count($retention['legalHold']['history']) === 1
                    && $retention['legalHold']['history'][0]['releasedBy'] === 'archivaris-2';
            }));

        $this->objectMapper->expects($this->once())->method('update');

        $result = $this->service->releaseHold($object, 'WOO-verzoek afgehandeld');
        $this->assertSame($object, $result);
    }

    /**
     * Test hasActiveHold returns true when hold is active.
     */
    public function testHasActiveHoldTrue(): void
    {
        $object = $this->createMockObject([
            'legalHold' => ['active' => true, 'reason' => 'test'],
        ]);

        $this->assertTrue($this->service->hasActiveHold($object));
    }

    /**
     * Test hasActiveHold returns false when no hold.
     */
    public function testHasActiveHoldFalse(): void
    {
        $object = $this->createMockObject([]);

        $this->assertFalse($this->service->hasActiveHold($object));
    }

    /**
     * Test hasActiveHold returns false when hold is released.
     */
    public function testHasActiveHoldReleased(): void
    {
        $object = $this->createMockObject([
            'legalHold' => ['active' => false, 'reason' => 'old'],
        ]);

        $this->assertFalse($this->service->hasActiveHold($object));
    }

    /**
     * Test hasActiveHoldFromRetention works with raw retention data.
     */
    public function testHasActiveHoldFromRetention(): void
    {
        $this->assertTrue(
            $this->service->hasActiveHoldFromRetention(['legalHold' => ['active' => true]])
        );

        $this->assertFalse(
            $this->service->hasActiveHoldFromRetention(['legalHold' => ['active' => false]])
        );

        $this->assertFalse(
            $this->service->hasActiveHoldFromRetention([])
        );
    }

    /**
     * Test bulk hold queues a background job.
     */
    public function testBulkPlaceHold(): void
    {
        $this->setupMockUser('admin');

        $this->jobList->expects($this->once())
            ->method('add')
            ->with(
                \OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob::class,
                $this->callback(function (array $args): bool {
                    return $args['schemaId'] === 42
                        && $args['registerId'] === 10
                        && $args['reason'] === 'Rekenkameronderzoek'
                        && $args['placedBy'] === 'admin';
                })
            );

        $this->service->bulkPlaceHold(42, 10, 'Rekenkameronderzoek');
    }

    /**
     * Test system user fallback when no user session.
     */
    public function testPlaceHoldSystemUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $object = $this->createMockObject();
        $object->expects($this->once())
            ->method('setRetention')
            ->with($this->callback(function (array $retention): bool {
                return $retention['legalHold']['placedBy'] === 'system';
            }));

        $this->objectMapper->expects($this->once())->method('update');

        $this->service->placeHold($object, 'System hold');
    }
}
