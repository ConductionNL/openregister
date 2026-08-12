<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * The pre-hop oversight gate, at the engine level.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowEngineOversightTest extends TestCase
{
    /**
     * A two-step linear flow, so "stopped at the first hop" is distinguishable
     * from "ran everything".
     *
     * @return array<string, mixed> The flow document.
     */
    private function linearFlow(): array
    {
        return [
            'id'    => 'gated',
            'nodes' => [
                ['id' => 'first', 'type' => 'openregister.set-fields'],
                ['id' => 'second', 'type' => 'openregister.set-fields'],
            ],
            'edges' => [['id' => 'first-second', 'from' => 'first', 'to' => 'second']],
        ];
    }//end linearFlow()

    private function check(string $id, ?string $reason): IFlowOversightCheck
    {
        $stub = $this->createMock(IFlowOversightCheck::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('veto')->willReturn($reason);

        return $stub;
    }//end check()

    /**
     * Run the flow with an optional registry, returning the engine result.
     *
     * @param FlowOversightRegistry|null $registry The gate.
     * @param array<string, mixed>       $context  The run context.
     *
     * @return array<string, mixed> The engine result.
     */
    private function runGated(?FlowOversightRegistry $registry, array $context=[]): array
    {
        $engine = new FlowEngine(new FlowDefinitionBuilder(), new \Psr\Log\NullLogger(), $registry);

        // Same subject and marking-store construction as FlowEngineTest — this
        // test is about the gate, not about re-deriving how a run is set up.
        return $engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            new RecordingDispatcher(),
            $context
        );
    }//end runGated()

    /**
     * A veto STOPS the run. It must never skip the hop and continue — a skipped
     * step inside a completed run is indistinguishable from one that ran and
     * did nothing, which is the exact failure this change exists to remove.
     */
    public function testAVetoStopsTheRunRatherThanSkippingTheHop(): void
    {
        $registry = new FlowOversightRegistry(new \Psr\Log\NullLogger());
        $registry->register($this->check('test.kill', 'The kill switch is set.'));

        $result = $this->runGated($registry);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
        $this->assertCount(1, $result['log'], 'the walk must stop at the first refused hop');
        $this->assertSame('stopped', $result['log'][0]['status']);
        $this->assertSame('test.kill', $result['log'][0]['checkId'], 'the run must name what stopped it');
    }//end testAVetoStopsTheRunRatherThanSkippingTheHop()

    /**
     * Positive control: the same flow, same shape, with a consenting check runs
     * to completion. Without this, an engine that stopped on everything would
     * pass the test above.
     */
    public function testAConsentingCheckLetsTheRunComplete(): void
    {
        $registry = new FlowOversightRegistry(new \Psr\Log\NullLogger());
        $registry->register($this->check('test.ok', null));

        $result = $this->runGated($registry);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertCount(2, $result['log']);
    }//end testAConsentingCheckLetsTheRunComplete()

    /**
     * A per-flow opt-out is honoured: the gate is not consulted at all.
     */
    public function testAFlowThatOptsOutIsNotGated(): void
    {
        $registry = new FlowOversightRegistry(new \Psr\Log\NullLogger());
        $registry->register($this->check('test.kill', 'The kill switch is set.'));

        $result = $this->runGated($registry, ['oversight' => false]);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
    }//end testAFlowThatOptsOutIsNotGated()

    /**
     * No registry CONSENTS, exactly as an empty one does — the two are the same
     * statement. The fail-closed property lives in the registry (a check that
     * throws is a veto), not in the engine's ability to find a registry.
     */
    public function testNoRegistryConsents(): void
    {
        $this->assertSame(FlowEngine::STATUS_COMPLETED, $this->runGated(null)['status']);
    }//end testNoRegistryConsents()

    public function testAnEmptyRegistryConsents(): void
    {
        $registry = new FlowOversightRegistry(new \Psr\Log\NullLogger());

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $this->runGated($registry)['status']);
    }//end testAnEmptyRegistryConsents()
}//end class
