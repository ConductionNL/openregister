<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Marking;

/** Subject carrying nothing — the marking lives on the run, not here. */
class RunSubject
{
    public array $bag = [];
}

/** A node that suspends the first time and proceeds once resuming. */
class WaitingNode implements IFlowNode
{
    public int $calls = 0;

    public function __construct(private readonly string $id = 'test.wait')
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return 'Wait';
    }

    public function getDescription(): string
    {
        return 'Waits.';
    }

    public function getIcon(): string
    {
        return 'i.svg';
    }

    public function isAvailableForScope(int $scope): bool
    {
        return true;
    }

    public function validateConfig(array $config): void
    {
    }

    public function execute(array $items, array $config, array $context): array
    {
        $this->calls++;
        if (($context['resuming'] ?? false) !== true) {
            throw new FlowSuspension(new DateTime('@1900000000'), 'waiting for the clock');
        }

        return $items;
    }
}

/** Records the context it was handed, so attribution can be asserted. */
class ContextCapturingNode implements IFlowNode
{
    public array $seenContext = [];

    public function getId(): string
    {
        return 'test.capture';
    }

    public function getDisplayName(): string
    {
        return 'Capture';
    }

    public function getDescription(): string
    {
        return 'Captures its context.';
    }

    public function getIcon(): string
    {
        return 'i.svg';
    }

    public function isAvailableForScope(int $scope): bool
    {
        return true;
    }

    public function validateConfig(array $config): void
    {
    }

    public function execute(array $items, array $config, array $context): array
    {
        $this->seenContext = $context;

        return $items;
    }
}

class FlowRunServiceTest extends TestCase
{
    private FlowRunMapper $mapper;
    private FlowRunService $service;
    private WaitingNode $waiter;

    private ContextCapturingNode $capturer;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(FlowRunMapper::class);
        // insert/update echo the entity back, so assertions read the real state.
        $this->mapper->method('insert')->willReturnArgument(0);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->waiter    = new WaitingNode();
        $this->capturer  = new ContextCapturingNode();

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            function (Event $event): void {
                if ($event instanceof RegisterFlowNodesEvent) {
                    $event->registerNode($this->waiter);
                    $event->registerNode($this->capturer);
                }
            }
        );

        $registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
        $engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));

        // No OrganisationService in the container — the cron/unit case, where a
        // queued run is recorded with no organisation rather than a guessed one.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('not available'));

        $this->service = new FlowRunService(
            $this->mapper,
            $this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
            $engine,
            $registry,
            $this->createMock(LoggerInterface::class),
            $container
        );
    }

    private function waitFlow(): array
    {
        return [
            'id' => 'f1',
            // The step is the NODE (or-flow-action-nodes).
            'nodes' => [['id' => 'hop', 'type' => 'test.wait']],
            'edges' => [],
        ];
    }

    public function testAQueuedRunIsNotExecuted(): void
    {
        $run = $this->service->queue('f1', ['uuid' => 'u1'], 'object.created');

        $this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
        $this->assertSame(0, $this->waiter->calls);
        $this->assertNotEmpty($run->getUuid());
    }

    /**
     * The property the whole issue exists for: a step can pause the run.
     */
    public function testAStepThatSuspendsLeavesTheRunResumable(): void
    {
        $run = $this->service->queue('f1');
        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

        $this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
        $this->assertInstanceOf(DateTime::class, $run->getResumeAt());
        $this->assertFalse($run->isTerminal());
    }

    /**
     * The marking must NOT have advanced past the suspended step, or resuming
     * would skip the very step that asked to wait.
     */
    public function testASuspendedRunKeepsItsPlaceInTheGraph(): void
    {
        $run = $this->service->queue('f1');
        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

        // The marking names the NODE the run is paused on. A suspending step
        // does not advance the token, so it waits on its own place — which is
        // the node's id, since a place is named after its node.
        $this->assertSame(['hop' => 1], $run->getMarking());
    }

    public function testResumingCarriesTheStoredItemsRatherThanReseeding(): void
    {
        $run = $this->service->queue('f1');
        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

        // Something the subject could never produce — proves the items came
        // from storage and not from re-seeding.
        $run->setItems([FlowItems::item(json: ['carried' => 'through-the-pause'])]);

        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

        $this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
        $this->assertSame('through-the-pause', $run->getItems()[0]['json']['carried']);
        $this->assertSame(2, $this->waiter->calls);
    }

    public function testResumeAtIsClearedOnceTheRunIsNoLongerSuspended(): void
    {
        $run = $this->service->queue('f1');
        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());
        $this->assertNotNull($run->getResumeAt());

        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

        // Otherwise the due-runs query keeps picking up a finished run.
        $this->assertNull($run->getResumeAt());
    }

    public function testTheLogAccumulatesAcrossASuspension(): void
    {
        $run = $this->service->queue('f1');
        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());
        $afterSuspend = count($run->getLog());

        $run = $this->service->execute($run, $this->waitFlow(), new RunSubject());

        $this->assertGreaterThan($afterSuspend, count($run->getLog()));
        $this->assertSame('suspended', $run->getLog()[0]['status']);
    }

    /**
     * Re-executing a finished run would repeat every side effect it performed.
     */
    public function testATerminalRunIsNeverReExecuted(): void
    {
        $run = $this->service->queue('f1');
        $run->setStatus(FlowRun::STATUS_COMPLETED);

        $this->service->execute($run, $this->waitFlow(), new RunSubject());

        $this->assertSame(0, $this->waiter->calls);
    }

    public function testAMalformedFlowFailsTheRunRatherThanLeavingItRunning(): void
    {
        $run = $this->service->queue('f1');
        $run = $this->service->execute($run, ['nodes' => []], new RunSubject());

        $this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
        $this->assertNotNull($run->getError());
    }

    /**
     * A flow definition whose single hop runs the context-capturing node.
     *
     * @return array<string,mixed>
     */
    private function captureFlow(): array
    {
        return [
            'id' => 'f1',
            'nodes' => [['id' => 'hop', 'type' => 'test.capture']],
            'edges' => [],
        ];
    }

    /**
     * FAILING PATH (or#2158): nodes read `context['triggeredBy']` to attribute
     * what they do — ObjectWriteNode REFUSES to write without it, SubFlowNode
     * propagates it to child runs, and Hermiq's agent node runs the turn as
     * that user. Before this fix `execute()` set only `runUuid` and `resuming`,
     * so the key was never populated from the run and EVERY trigger reached its
     * nodes ownerless; only hand-injected contexts (tests, harnesses) worked.
     *
     * @return void
     */
    public function testTheRunsOwnerReachesTheNodeContext(): void
    {
        $run = $this->service->queue('f1', ['uuid' => 'u1'], 'object.created', [], 'alice');

        $this->service->execute($run, $this->captureFlow(), new RunSubject());

        $this->assertSame('alice', ($this->capturer->seenContext['triggeredBy'] ?? null));
    }

    /**
     * An explicit context value wins, so a caller can attribute a run to
     * somebody other than whoever queued it.
     *
     * @return void
     */
    public function testAnExplicitContextOwnerIsNotOverwrittenByTheRunsOwner(): void
    {
        $run = $this->service->queue('f1', ['uuid' => 'u1'], 'object.created', ['triggeredBy' => 'bob'], 'alice');

        $this->service->execute($run, $this->captureFlow(), new RunSubject());

        $this->assertSame('bob', ($this->capturer->seenContext['triggeredBy'] ?? null));
    }
}

class FlowRunMarkingStoreTest extends TestCase
{
    public function testTheMarkingRoundTripsThroughTheRun(): void
    {
        $run = new FlowRun();
        $store = new FlowRunMarkingStore($run);

        $store->setMarking(new RunSubject(), new Marking(['a' => 1, 'b' => 1]));

        $this->assertSame(['a' => 1, 'b' => 1], $run->getMarking());
        $this->assertSame(['a' => 1, 'b' => 1], $store->getMarking(new RunSubject())->getPlaces());
    }

    public function testAnEmptyRunStartsWithAnEmptyMarking(): void
    {
        $store = new FlowRunMarkingStore(new FlowRun());

        $this->assertSame([], $store->getMarking(new RunSubject())->getPlaces());
    }

    /**
     * A hand-authored fixture tends to be a list of place names rather than a
     * place => tokens map; accept it rather than silently marking nothing.
     */
    public function testAListOfPlaceNamesIsAccepted(): void
    {
        $run = new FlowRun();
        $run->setMarking(['a', 'b']);

        $this->assertSame(['a' => 1, 'b' => 1], (new FlowRunMarkingStore($run))->getMarking(new RunSubject())->getPlaces());
    }
}
