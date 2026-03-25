<?php

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCA\OpenRegister\Service\ApprovalService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApprovalServiceTest extends TestCase
{
    private ApprovalService $service;
    private ApprovalChainMapper $chainMapper;
    private ApprovalStepMapper $stepMapper;
    private WorkflowExecutionMapper $executionMapper;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->chainMapper = $this->createMock(ApprovalChainMapper::class);
        $this->stepMapper = $this->createMock(ApprovalStepMapper::class);
        $this->executionMapper = $this->createMock(WorkflowExecutionMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ApprovalService(
            $this->chainMapper,
            $this->stepMapper,
            $this->executionMapper,
            $this->groupManager,
            $this->logger
        );
    }

    public function testInitializeChainCreatesStepsWithCorrectStatuses(): void
    {
        $chain = new ApprovalChain();
        $chain->hydrate([
            'steps' => [
                ['order' => 1, 'role' => 'teamleider', 'statusOnApprove' => 'wacht', 'statusOnReject' => 'afgewezen'],
                ['order' => 2, 'role' => 'afdelingshoofd', 'statusOnApprove' => 'goedgekeurd', 'statusOnReject' => 'afgewezen'],
            ],
        ]);

        $step1 = new ApprovalStep();
        $step1->hydrate(['status' => 'pending', 'stepOrder' => 1]);

        $step2 = new ApprovalStep();
        $step2->hydrate(['status' => 'waiting', 'stepOrder' => 2]);

        $callCount = 0;
        $this->stepMapper->expects($this->exactly(2))
            ->method('createFromArray')
            ->willReturnCallback(function ($data) use (&$callCount, $step1, $step2) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertSame('pending', $data['status']);
                    $this->assertSame(1, $data['stepOrder']);
                    return $step1;
                }
                $this->assertSame('waiting', $data['status']);
                $this->assertSame(2, $data['stepOrder']);
                return $step2;
            });

        $result = $this->service->initializeChain($chain, 'obj-123');

        $this->assertCount(2, $result);
    }

    public function testApproveStepThrowsIfNotPending(): void
    {
        $step = new ApprovalStep();
        $step->hydrate(['status' => 'approved']);

        $this->stepMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($step);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Step is not in pending status');

        $this->service->approveStep(1, 'admin');
    }

    public function testApproveStepThrowsIfUserNotInRole(): void
    {
        $step = new ApprovalStep();
        $step->hydrate(['status' => 'pending', 'role' => 'teamleider']);

        $this->stepMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($step);

        $this->groupManager->expects($this->once())
            ->method('isInGroup')
            ->with('user1', 'teamleider')
            ->willReturn(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You are not authorised for this approval step');

        $this->service->approveStep(1, 'user1');
    }

    public function testRejectStepThrowsIfNotPending(): void
    {
        $step = new ApprovalStep();
        $step->hydrate(['status' => 'waiting']);

        $this->stepMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($step);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Step is not in pending status');

        $this->service->rejectStep(1, 'admin');
    }
}
