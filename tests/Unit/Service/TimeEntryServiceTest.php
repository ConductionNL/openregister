<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\TimeLink;
use OCA\OpenRegister\Db\TimeLinkMapper;
use OCA\OpenRegister\Service\TimeEntryService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TimeEntryService.
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
 */
class TimeEntryServiceTest extends TestCase
{

    private TimeLinkMapper&MockObject $mapper;
    private IAppConfig&MockObject $appConfig;
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private LoggerInterface&MockObject $logger;
    private TimeEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper       = $this->getMockBuilder(TimeLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'findByObjectUuid', 'sumDurationByObjectUuid', 'updateTotalForObject',
                'findDistinctObjectUuids', 'insert', 'delete',
            ])
            ->getMock();
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->service = new TimeEntryService(
            timeLinkMapper: $this->mapper,
            appConfig: $this->appConfig,
            appManager: $this->appManager,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            logger: $this->logger
        );
    }//end setUp()

    public function testGetBackendNameReturnsConfiguredValue(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'time-tracker.backend', 'timemanager')
            ->willReturn('custom-tracker');

        $this->assertSame('custom-tracker', $this->service->getBackendName());
    }//end testGetBackendNameReturnsConfiguredValue()

    public function testGetBackendNameDefaultsToTimemanager(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('timemanager');

        $this->assertSame('timemanager', $this->service->getBackendName());
    }//end testGetBackendNameDefaultsToTimemanager()

    public function testIsBackendAvailableTrue(): void
    {
        $this->appConfig->method('getValueString')->willReturn('timemanager');
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(true);

        $this->assertTrue($this->service->isBackendAvailable());
    }//end testIsBackendAvailableTrue()

    public function testIsBackendAvailableFalse(): void
    {
        $this->appConfig->method('getValueString')->willReturn('timemanager');
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(false);

        $this->assertFalse($this->service->isBackendAvailable());
    }//end testIsBackendAvailableFalse()

    public function testGetEntriesForObjectReturnsPaginatedResult(): void
    {
        $link = new TimeLink();
        $link->setObjectUuid('obj-1');
        $link->setDurationMinutes(30);

        $this->mapper->method('findByObjectUuid')->with('obj-1')->willReturn([$link]);
        $this->mapper->method('sumDurationByObjectUuid')->with('obj-1')->willReturn(30);

        $result = $this->service->getEntriesForObject(objectUuid: 'obj-1');

        $this->assertSame(1, $result['total']);
        $this->assertSame(30, $result['totalMinutes']);
        $this->assertCount(1, $result['results']);
    }//end testGetEntriesForObjectReturnsPaginatedResult()

    public function testLogTimeCreatesLinkAndRecalculates(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        $this->appConfig->method('getValueString')->willReturn('timemanager');

        $saved = new TimeLink();
        $saved->setObjectUuid('obj-2');
        $saved->setDurationMinutes(60);

        $this->mapper->expects($this->once())->method('insert')->willReturn($saved);
        $this->mapper->method('sumDurationByObjectUuid')->with('obj-2')->willReturn(60);
        $this->mapper->expects($this->once())->method('updateTotalForObject')
            ->with('obj-2', 60);

        $result = $this->service->logTime(
            objectUuid: 'obj-2',
            registerId: 1,
            durationMinutes: 60
        );

        $this->assertSame('obj-2', $result->getObjectUuid());
    }//end testLogTimeCreatesLinkAndRecalculates()

    public function testLogTimeThrowsOnZeroDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->logTime(objectUuid: 'obj-3', registerId: 1, durationMinutes: 0);
    }//end testLogTimeThrowsOnZeroDuration()

    public function testDeleteEntryThrowsWhenNotFound(): void
    {
        $this->mapper->method('findByObjectUuid')->willReturn([]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Time entry not found.');

        $this->service->deleteEntry(entryId: 99, objectUuid: 'obj-x');
    }//end testDeleteEntryThrowsWhenNotFound()

    public function testFormatMinutesReturnsHoursAndMinutes(): void
    {
        $this->assertSame('1h 30m', $this->service->formatMinutes(90));
        $this->assertSame('2h', $this->service->formatMinutes(120));
        $this->assertSame('45m', $this->service->formatMinutes(45));
        $this->assertSame('0m', $this->service->formatMinutes(0));
    }//end testFormatMinutesReturnsHoursAndMinutes()

    public function testRecalculateTotalReturnsNewValue(): void
    {
        $this->mapper->method('sumDurationByObjectUuid')->with('uuid-1')->willReturn(150);
        $this->mapper->expects($this->once())->method('updateTotalForObject')
            ->with('uuid-1', 150);

        $result = $this->service->recalculateTotal(objectUuid: 'uuid-1');
        $this->assertSame(150, $result);
    }//end testRecalculateTotalReturnsNewValue()
}//end class
