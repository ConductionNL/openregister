<?php
/**
 * What the nodes say a flow fires on, against what the columns say.
 *
 * The cutover from four trigger COLUMNS to trigger NODES changes which flows
 * run when an event fires. That is not a refactor, it is a behaviour change,
 * and the only way to make it safely is to be able to answer "would this flow
 * fire on the same events afterwards?" for each flow individually. These tests
 * pin that answer — including the cases where it is NO.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerDerivation
 */
class FlowTriggerDerivationTest extends TestCase
{

    private FlowTriggerDerivation $derivation;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->derivation = new FlowTriggerDerivation();

    }//end setUp()


    /**
     * Build a flow with the given nodes and column trigger.
     *
     * @param array       $nodes    The node list.
     * @param string|null $trigger  The column trigger.
     * @param string|null $register The column trigger register.
     * @param string|null $schema   The column trigger schema.
     *
     * @return Flow The flow.
     */
    private function flow(
        array $nodes=[],
        ?string $trigger=null,
        ?string $register=null,
        ?string $schema=null
    ): Flow {
        $flow = new Flow();
        $flow->setNodes($nodes);
        $flow->setTrigger($trigger);
        $flow->setTriggerRegister($register);
        $flow->setTriggerSchema($schema);

        return $flow;

    }//end flow()


    /**
     * An object trigger node yields exactly its event, register and schema.
     *
     * @return void
     */
    public function testAnObjectTriggerNodeIsDerived(): void
    {
        $flow = $this->flow(
            [
                ['id' => 'n1', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']],
                ['id' => 'n2', 'type' => 'openregister.log'],
            ]
        );

        $this->assertSame(
            [['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']],
            $this->derivation->objectTriggersOf($flow)
        );

    }//end testAnObjectTriggerNodeIsDerived()


    /**
     * SEVERAL trigger nodes yield several triggers — the shape the four
     * columns cannot hold at all.
     *
     * @return void
     */
    public function testSeveralObjectTriggersAreAllDerived(): void
    {
        $flow = $this->flow(
            [
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']],
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'register' => 'hydra', 'schema' => 'finding']],
            ]
        );

        $this->assertCount(2, $this->derivation->objectTriggersOf($flow));

    }//end testSeveralObjectTriggersAreAllDerived()


    /**
     * The same trigger authored twice is ONE subscription.
     *
     * Two identical trigger nodes are an authoring accident, and without this
     * the flow would be started twice by a single event.
     *
     * @return void
     */
    public function testDuplicateTriggerNodesCollapseToOne(): void
    {
        $flow = $this->flow(
            [
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']],
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']],
            ]
        );

        $this->assertCount(1, $this->derivation->objectTriggersOf($flow));

    }//end testDuplicateTriggerNodesCollapseToOne()


    /**
     * Schedule and manual triggers are NOT object triggers.
     *
     * They are trigger nodes — `triggerNodesOf()` sees them — but an object
     * event must never start them, so they are absent from the object set.
     *
     * @return void
     */
    public function testScheduleAndManualAreTriggerNodesButNotObjectTriggers(): void
    {
        $flow = $this->flow(
            [
                ['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 * * * *']],
                ['type' => 'openregister.trigger-manual', 'config' => []],
            ]
        );

        $this->assertCount(2, $this->derivation->triggerNodesOf($flow));
        $this->assertSame([], $this->derivation->objectTriggersOf($flow));

    }//end testScheduleAndManualAreTriggerNodesButNotObjectTriggers()


    /**
     * A half-authored trigger subscribes to NOTHING, never to everything.
     *
     * The failure this prevents is silent and instance-wide: reading a missing
     * register as "any register" would start the flow on every object event in
     * the system.
     *
     * @return void
     */
    public function testAnIncompleteObjectTriggerIsSkippedRatherThanWidened(): void
    {
        $flow = $this->flow(
            [
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => '', 'schema' => 'finding']],
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'schema' => 'finding']],
                ['type' => 'openregister.trigger-object', 'config' => ['event' => '', 'register' => 'hydra', 'schema' => 'finding']],
            ]
        );

        $this->assertSame([], $this->derivation->objectTriggersOf($flow));

    }//end testAnIncompleteObjectTriggerIsSkippedRatherThanWidened()


    /**
     * A node list that is absent or malformed derives nothing, and does not
     * raise — this runs on every flow in the instance, including old ones.
     *
     * @return void
     */
    public function testAMalformedNodeListIsSurvivable(): void
    {
        $flow = new Flow();
        $flow->setNodes(null);
        $this->assertSame([], $this->derivation->objectTriggersOf($flow));

        $flow->setNodes(['not-an-array', 42, ['type' => 'openregister.trigger-object']]);
        $this->assertSame([], $this->derivation->objectTriggersOf($flow));

    }//end testAMalformedNodeListIsSurvivable()


    /**
     * An empty column register/schema means ANY, and is reported as `*`.
     *
     * Smoothing it to a blank would make an unscoped trigger look like a
     * scoped one against an unnamed register — the difference the whole
     * cutover turns on.
     *
     * @return void
     */
    public function testAnUnscopedColumnTriggerIsReportedAsAny(): void
    {
        $flow = $this->flow([], 'object.updated', '', null);

        $this->assertSame(
            ['event' => 'object.updated', 'register' => '*', 'schema' => '*'],
            $this->derivation->columnTriggerOf($flow)
        );

    }//end testAnUnscopedColumnTriggerIsReportedAsAny()


    /**
     * A flow with no trigger at all has no column trigger.
     *
     * @return void
     */
    public function testNoColumnTriggerIsNull(): void
    {
        $this->assertNull($this->derivation->columnTriggerOf($this->flow([], '')));
        $this->assertNull($this->derivation->columnTriggerOf($this->flow([], null)));

    }//end testNoColumnTriggerIsNull()


    /**
     * A scoped column trigger with a matching node is equivalent.
     *
     * @return void
     */
    public function testAScopedColumnTriggerMatchingItsNodeIsEquivalent(): void
    {
        $flow = $this->flow(
            [['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']]],
            'object.created',
            'hydra',
            'finding'
        );

        $verdict = $this->derivation->compare($flow);
        $this->assertTrue($verdict['equivalent']);
        $this->assertSame('exact match', $verdict['reason']);

    }//end testAScopedColumnTriggerMatchingItsNodeIsEquivalent()


    /**
     * THE BLOCKING CASE: an unscoped column trigger is NOT equivalent to any
     * node, even one naming the same event.
     *
     * `TriggerObjectNode` requires a register and a schema by design, so an
     * "any register, any schema" subscription has no node form. Reporting this
     * as equivalent is how the cutover would silently unsubscribe a flow that
     * fires on every object in the instance.
     *
     * @return void
     */
    public function testAnUnscopedColumnTriggerIsNeverEquivalentToANode(): void
    {
        $flow = $this->flow(
            [['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'register' => 'hydra', 'schema' => 'finding']]],
            'object.updated',
            '',
            ''
        );

        $verdict = $this->derivation->compare($flow);
        $this->assertFalse($verdict['equivalent'], 'an unscoped trigger was reported as reproducible by a node');
        $this->assertStringContainsString('unscoped', $verdict['reason']);

    }//end testAnUnscopedColumnTriggerIsNeverEquivalentToANode()


    /**
     * A column trigger with no node at all means the flow WOULD STOP firing.
     *
     * This is the state every flow in a real instance is in before a backfill,
     * so it must be reported as a divergence rather than as "nothing to do".
     *
     * @return void
     */
    public function testAColumnTriggerWithNoNodeMeansTheFlowWouldStopFiring(): void
    {
        $flow = $this->flow([], 'object.created', 'hydra', 'finding');

        $verdict = $this->derivation->compare($flow);
        $this->assertFalse($verdict['equivalent']);
        $this->assertStringContainsString('WOULD STOP firing', $verdict['reason']);

    }//end testAColumnTriggerWithNoNodeMeansTheFlowWouldStopFiring()


    /**
     * A node trigger with no column means the flow does not fire TODAY.
     *
     * The cutover would START it — also a behaviour change, and also one an
     * operator has to be told about.
     *
     * @return void
     */
    public function testANodeTriggerWithNoColumnMeansTheFlowDoesNotFireToday(): void
    {
        $flow = $this->flow(
            [['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']]]
        );

        $verdict = $this->derivation->compare($flow);
        $this->assertFalse($verdict['equivalent']);
        $this->assertStringContainsString('does NOT fire today', $verdict['reason']);

    }//end testANodeTriggerWithNoColumnMeansTheFlowDoesNotFireToday()


    /**
     * Extra node triggers beyond the column one are equivalent-but-widening,
     * and say so.
     *
     * @return void
     */
    public function testExtraNodeTriggersAreReportedAsNew(): void
    {
        $flow = $this->flow(
            [
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.created', 'register' => 'hydra', 'schema' => 'finding']],
                ['type' => 'openregister.trigger-object', 'config' => ['event' => 'object.deleted', 'register' => 'hydra', 'schema' => 'finding']],
            ],
            'object.created',
            'hydra',
            'finding'
        );

        $verdict = $this->derivation->compare($flow);
        $this->assertTrue($verdict['equivalent']);
        $this->assertStringContainsString('are NEW', $verdict['reason']);

    }//end testExtraNodeTriggersAreReportedAsNew()


    /**
     * A flow with neither is equivalent, and is not reported as a change.
     *
     * @return void
     */
    public function testNeitherSideHavingATriggerIsEquivalent(): void
    {
        $verdict = $this->derivation->compare($this->flow());
        $this->assertTrue($verdict['equivalent']);

    }//end testNeitherSideHavingATriggerIsEquivalent()


}//end class
