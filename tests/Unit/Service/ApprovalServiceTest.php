<?php

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCA\OpenRegister\Event\ApprovalStepApprovedEvent;
use OCA\OpenRegister\Event\ApprovalStepCompletedEvent;
use OCA\OpenRegister\Event\ApprovalStepInitiatedEvent;
use OCA\OpenRegister\Event\ApprovalStepRejectedEvent;
use OCA\OpenRegister\Service\ApprovalService;
use OCP\EventDispatcher\IEventDispatcher;
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
    private IEventDispatcher $eventDispatcher;
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        $this->chainMapper = $this->createMock(ApprovalChainMapper::class);
        $this->stepMapper = $this->createMock(ApprovalStepMapper::class);
        $this->executionMapper = $this->createMock(WorkflowExecutionMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $this->service = new ApprovalService(
            $this->chainMapper,
            $this->stepMapper,
            $this->executionMapper,
            $this->groupManager,
            $this->logger,
            $this->eventDispatcher,
            $this->schemaMapper
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

    /**
     * Initializing a chain MUST dispatch one ApprovalStepInitiatedEvent
     * for the first step (which is the only step that becomes `pending`).
     */
    public function testInitializeChainDispatchesInitiatedEventForFirstStep(): void
    {
        $chain = new ApprovalChain();
        $chain->hydrate([
            'steps' => [
                ['order' => 1, 'role' => 'teamleider'],
                ['order' => 2, 'role' => 'afdelingshoofd'],
            ],
        ]);

        $step1 = new ApprovalStep();
        $step1->hydrate(['status' => 'pending', 'stepOrder' => 1, 'objectUuid' => 'obj-123']);

        $step2 = new ApprovalStep();
        $step2->hydrate(['status' => 'waiting', 'stepOrder' => 2, 'objectUuid' => 'obj-123']);

        $this->stepMapper->method('createFromArray')
            ->willReturnOnConsecutiveCalls($step1, $step2);

        $dispatched = [];
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
            });

        $this->service->initializeChain($chain, 'obj-123');

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(ApprovalStepInitiatedEvent::class, $dispatched[0]);
        $this->assertSame('obj-123', $dispatched[0]->getObjectUuid());
        $this->assertSame($step1, $dispatched[0]->getStep());
        $this->assertSame($chain, $dispatched[0]->getChain());
    }

    /**
     * Approving a non-final step MUST dispatch BOTH
     * ApprovalStepApprovedEvent (for the just-approved step) AND
     * ApprovalStepInitiatedEvent (for the next step now `pending`).
     */
    public function testApproveStepDispatchesApprovedAndInitiatedForNextStep(): void
    {
        $chain = new ApprovalChain();
        $chain->setId(99);
        $chain->hydrate([
            'steps' => [
                ['order' => 1, 'role' => 'teamleider', 'statusOnApprove' => 'wacht'],
                ['order' => 2, 'role' => 'afdelingshoofd', 'statusOnApprove' => 'goedgekeurd'],
            ],
        ]);

        $current = new ApprovalStep();
        $current->hydrate(['status' => 'pending', 'role' => 'teamleider', 'stepOrder' => 1, 'chainId' => 99, 'objectUuid' => 'obj-7']);

        $waiting = new ApprovalStep();
        $waiting->hydrate(['status' => 'waiting', 'role' => 'afdelingshoofd', 'stepOrder' => 2, 'chainId' => 99, 'objectUuid' => 'obj-7']);

        $this->stepMapper->method('find')->with(1)->willReturn($current);
        $this->chainMapper->method('find')->with(99)->willReturn($chain);
        $this->stepMapper->method('findByChainAndObject')->willReturn([$current, $waiting]);
        $this->groupManager->method('isInGroup')->with('alice', 'teamleider')->willReturn(true);

        $dispatched = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatchTyped')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
            });

        $result = $this->service->approveStep(1, 'alice', 'looks good');

        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(ApprovalStepApprovedEvent::class, $dispatched[0]);
        $this->assertSame('alice', $dispatched[0]->getUserId());
        $this->assertSame('wacht', $dispatched[0]->getStatusOnApprove());
        $this->assertSame($waiting, $dispatched[0]->getNextStep());
        $this->assertFalse($dispatched[0]->isFinalStep());

        $this->assertInstanceOf(ApprovalStepInitiatedEvent::class, $dispatched[1]);
        $this->assertSame($waiting, $dispatched[1]->getStep());
        $this->assertSame('obj-7', $dispatched[1]->getObjectUuid());

        $this->assertSame($waiting, $result['nextStep']);
    }

    /**
     * Approving the FINAL step MUST dispatch ApprovalStepApprovedEvent
     * AND ApprovalStepCompletedEvent — NOT ApprovalStepInitiatedEvent.
     */
    public function testApproveStepDispatchesCompletedEventForFinalStep(): void
    {
        $chain = new ApprovalChain();
        $chain->setId(42);
        $chain->hydrate([
            'steps' => [
                ['order' => 1, 'role' => 'afdelingshoofd', 'statusOnApprove' => 'goedgekeurd'],
            ],
        ]);

        $final = new ApprovalStep();
        $final->hydrate(['status' => 'pending', 'role' => 'afdelingshoofd', 'stepOrder' => 1, 'chainId' => 42, 'objectUuid' => 'obj-final']);

        $this->stepMapper->method('find')->with(5)->willReturn($final);
        $this->chainMapper->method('find')->with(42)->willReturn($chain);
        $this->stepMapper->method('findByChainAndObject')->willReturn([$final]);
        $this->groupManager->method('isInGroup')->with('bob', 'afdelingshoofd')->willReturn(true);

        $dispatched = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatchTyped')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
            });

        $result = $this->service->approveStep(5, 'bob', '');

        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(ApprovalStepApprovedEvent::class, $dispatched[0]);
        $this->assertTrue($dispatched[0]->isFinalStep());
        $this->assertNull($dispatched[0]->getNextStep());

        $this->assertInstanceOf(ApprovalStepCompletedEvent::class, $dispatched[1]);
        $this->assertSame($chain, $dispatched[1]->getChain());
        $this->assertSame($final, $dispatched[1]->getFinalStep());
        $this->assertSame('bob', $dispatched[1]->getUserId());
        $this->assertSame('goedgekeurd', $dispatched[1]->getStatusOnApprove());
        $this->assertSame('obj-final', $dispatched[1]->getObjectUuid());

        $this->assertNull($result['nextStep']);
    }

    /**
     * Rejecting a step MUST dispatch ApprovalStepRejectedEvent and
     * MUST NOT dispatch any Initiated/Completed events — the chain is
     * terminated.
     */
    public function testRejectStepDispatchesRejectedEventOnly(): void
    {
        $chain = new ApprovalChain();
        $chain->setId(11);
        $chain->hydrate([
            'steps' => [
                ['order' => 1, 'role' => 'teamleider', 'statusOnReject' => 'afgewezen'],
            ],
        ]);

        $step = new ApprovalStep();
        $step->hydrate(['status' => 'pending', 'role' => 'teamleider', 'stepOrder' => 1, 'chainId' => 11, 'objectUuid' => 'obj-rej']);

        $this->stepMapper->method('find')->with(9)->willReturn($step);
        $this->chainMapper->method('find')->with(11)->willReturn($chain);
        $this->groupManager->method('isInGroup')->with('carol', 'teamleider')->willReturn(true);

        $dispatched = [];
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event;
            });

        $this->service->rejectStep(9, 'carol', 'missing evidence');

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(ApprovalStepRejectedEvent::class, $dispatched[0]);
        $this->assertSame('carol', $dispatched[0]->getUserId());
        $this->assertSame('afgewezen', $dispatched[0]->getStatusOnReject());
        $this->assertSame('obj-rej', $dispatched[0]->getObjectUuid());
    }

    /**
     * FAILING PATH (approval-chains-declarative): an approver equal to the
     * step's `requesterId` MUST be rejected when the chain's schema declares
     * `x-openregister-approval-chains[<chainName>].separationOfDuties` (default
     * true when the annotation exists).
     */
    public function testApproveStepRejectsRequesterWhenSeparationOfDutiesDeclared(): void
    {
        $chain = new ApprovalChain();
        $chain->setId(77);
        $chain->hydrate([
            'name'     => 'submit-approval',
            'schemaId' => 5,
            'steps'    => [['order' => 1, 'role' => 'finance-clerks']],
        ]);

        $step = new ApprovalStep();
        $step->hydrate([
            'status'      => 'pending',
            'role'        => 'finance-clerks',
            'stepOrder'   => 1,
            'chainId'     => 77,
            'objectUuid'  => 'obj-sod',
            'requesterId' => 'alice',
        ]);

        $schema = new \OCA\OpenRegister\Db\Schema();
        $schema->setConfiguration([
            'x-openregister-approval-chains' => [
                'submit-approval' => [
                    'transition'         => 'submit',
                    'approvers'          => [['role' => 'finance-clerks', 'min' => 1]],
                    'separationOfDuties' => true,
                ],
            ],
        ]);

        $this->stepMapper->method('find')->with(1)->willReturn($step);
        $this->chainMapper->method('find')->with(77)->willReturn($chain);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You may not decide an approval step you requested yourself');

        // alice is the requester AND (hypothetically) in the finance-clerks
        // group — separation of duties must reject before the role check.
        $this->groupManager->method('isInGroup')->willReturn(true);

        $this->service->approveStep(1, 'alice', 'self-approving');
    }

    /**
     * Pre-existing pure-CRUD-provisioned chains (no matching declarative
     * annotation) MUST remain completely unaffected — the requester/approver
     * being the same person is not rejected when no schema declares
     * `x-openregister-approval-chains` for that chain name.
     */
    public function testApproveStepAllowsRequesterEqualsApproverWhenNoDeclarativeEntry(): void
    {
        $chain = new ApprovalChain();
        $chain->setId(78);
        $chain->hydrate([
            'name'     => 'legacy-crud-chain',
            'schemaId' => 5,
            'steps'    => [['order' => 1, 'role' => 'teamleider', 'statusOnApprove' => 'goedgekeurd']],
        ]);

        $step = new ApprovalStep();
        $step->hydrate([
            'status'      => 'pending',
            'role'        => 'teamleider',
            'stepOrder'   => 1,
            'chainId'     => 78,
            'objectUuid'  => 'obj-crud',
            'requesterId' => 'dave',
        ]);

        // Schema exists but has no x-openregister-approval-chains entry named
        // "legacy-crud-chain" — the pure-CRUD flow this chain was provisioned
        // through has no declarative counterpart at all.
        $schema = new \OCA\OpenRegister\Db\Schema();
        $schema->setConfiguration([]);

        $this->stepMapper->method('find')->with(1)->willReturn($step);
        $this->chainMapper->method('find')->with(78)->willReturn($chain);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->groupManager->method('isInGroup')->willReturn(true);
        $this->stepMapper->method('findByChainAndObject')->willReturn([$step]);

        // Must NOT throw — dave deciding his own pure-CRUD step is unaffected.
        $result = $this->service->approveStep(1, 'dave', '');

        $this->assertSame('approved', $result['step']->getStatus());
    }
}
