<?php

/**
 * Unit tests for FlowStateNode.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class FlowStateNodeTest extends TestCase
{

    /**
     * The node under test.
     *
     * @var FlowStateNode
     */
    private FlowStateNode $node;

    /**
     * Build the node with stub collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $l10n = $this->createMock(originalClassName: IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->node = new FlowStateNode($l10n, $this->createMock(originalClassName: IURLGenerator::class));

    }//end setUp()

    /**
     * Wrap a handle in the context shape the engine passes.
     *
     * @param FlowStateHandle $handle The handle.
     *
     * @return array The context.
     */
    private function ctx(FlowStateHandle $handle): array
    {
        return [FlowStateHandle::CONTEXT_KEY => $handle];

    }//end ctx()

    /**
     * One item, with the given json.
     *
     * @param array $json The item's json.
     *
     * @return array The items array.
     */
    private function items(array $json=[]): array
    {
        return [['json' => $json]];

    }//end items()

    /**
     * A claim takes the first free slot and reports which.
     *
     * @return void
     */
    public function testClaimTakesTheFirstFreeSlot(): void
    {
        $state = new FlowStateHandle();

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'issue-42']),
            config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 3],
            context: $this->ctx(handle: $state)
        );

        self::assertTrue(condition: $out[0]['json']['claimed']);
        self::assertSame(expected: 1, actual: $out[0]['json']['slot']);

        // Every slot up to capacity is present, so a reader can say "1 of 3
        // taken" without being told the capacity separately.
        $slots = $state->get(key: 'slots');
        self::assertSame(expected: [1, 2, 3], actual: array_keys($slots));
        self::assertSame(expected: 'issue-42', actual: $slots['1']['holder']);
        self::assertNotEmpty(actual: $slots['1']['since']);
        self::assertNull(actual: $slots['2']);
        self::assertNull(actual: $slots['3']);

    }//end testClaimTakesTheFirstFreeSlot()


    /**
     * `record` copies named item fields onto the slot, so "what is running"
     * is answerable from the state alone.
     *
     * A named field the item does not carry is recorded as null rather than
     * omitted, so every slot has the same shape and a table renders evenly.
     *
     * @return void
     */
    public function testClaimRecordsTheNamedItemFields(): void
    {
        $state = new FlowStateHandle();

        $this->node->execute(
            items: $this->items(json: ['holder' => 'issue-42', 'stage' => 'builder', 'repo' => 'ConductionNL/hydra']),
            config: [
                'operation' => 'claim',
                'slots'     => 'slots',
                'capacity'  => 2,
                'record'    => ['stage', 'repo', 'absent'],
            ],
            context: $this->ctx(handle: $state)
        );

        $slot = $state->get(key: 'slots')['1'];

        self::assertSame(expected: 'builder', actual: $slot['stage']);
        self::assertSame(expected: 'ConductionNL/hydra', actual: $slot['repo']);
        self::assertArrayHasKey(key: 'absent', array: $slot);
        self::assertNull(actual: $slot['absent']);

    }//end testClaimRecordsTheNamedItemFields()

    /**
     * A second claim takes the NEXT slot, not the same one.
     *
     * @return void
     */
    public function testASecondClaimTakesTheNextSlot(): void
    {
        $state = new FlowStateHandle(values: ['slots' => ['1' => 'issue-1']]);

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'issue-2']),
            config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 3],
            context: $this->ctx(handle: $state)
        );

        self::assertSame(expected: 2, actual: $out[0]['json']['slot']);

        // Slot 1 was written by an older revision as a bare holder string. It
        // is carried forward into the record shape rather than dropped, so a
        // flow mid-run when this changed does not lose what it was holding —
        // with a null `since`, because that moment was never recorded.
        $slots = $state->get(key: 'slots');
        self::assertSame(expected: 'issue-1', actual: $slots['1']['holder']);
        self::assertNull(actual: $slots['1']['since']);
        self::assertSame(expected: 'issue-2', actual: $slots['2']['holder']);
        self::assertNull(actual: $slots['3']);

    }//end testASecondClaimTakesTheNextSlot()

    /**
     * AT CAPACITY, a claim reports failure rather than overrunning.
     *
     * This is the whole point of the cap. A node that silently handed out an
     * eleventh slot would make "at most ten pipelines" a comment rather than a
     * limit.
     *
     * @return void
     */
    public function testAtCapacityTheClaimIsRefused(): void
    {
        $state = new FlowStateHandle(values: ['slots' => ['1' => 'a', '2' => 'b']]);

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'c']),
            config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 2],
            context: $this->ctx(handle: $state)
        );

        self::assertFalse(condition: $out[0]['json']['claimed']);
        self::assertNull(actual: $out[0]['json']['slot']);

        // Nobody was evicted. Both slots still name their holder — normalised
        // into the record shape, which is the only change a refused claim makes.
        $slots = $state->get(key: 'slots');
        self::assertSame(expected: [1, 2], actual: array_keys($slots));
        self::assertSame(expected: 'a', actual: $slots['1']['holder']);
        self::assertSame(expected: 'b', actual: $slots['2']['holder']);

    }//end testAtCapacityTheClaimIsRefused()


    /**
     * A slot occupied ABOVE a capacity the operator has just lowered survives.
     *
     * Dropping it would free a slot somebody is still holding, letting the flow
     * exceed the new cap immediately — the opposite of what lowering it means.
     * It disappears when its holder releases it.
     *
     * @return void
     */
    public function testASlotAboveALoweredCapacityIsKeptUntilReleased(): void
    {
        $state = new FlowStateHandle(values: ['slots' => ['1' => 'a', '2' => 'b', '3' => 'c']]);

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'd']),
            config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 2],
            context: $this->ctx(handle: $state)
        );

        self::assertFalse(condition: $out[0]['json']['claimed']);

        $slots = $state->get(key: 'slots');
        self::assertArrayHasKey(key: '3', array: $slots);
        self::assertSame(expected: 'c', actual: $slots['3']['holder']);

    }//end testASlotAboveALoweredCapacityIsKeptUntilReleased()

    /**
     * A released slot becomes claimable again.
     *
     * @return void
     */
    public function testReleaseFreesTheSlotForTheNextClaim(): void
    {
        $state = new FlowStateHandle(values: ['slots' => ['1' => 'a', '2' => 'b']]);

        $this->node->execute(
            items: $this->items(json: ['slot' => 1]),
            config: ['operation' => 'release', 'slots' => 'slots'],
            context: $this->ctx(handle: $state)
        );

        self::assertSame(expected: ['1' => null, '2' => 'b'], actual: $state->get(key: 'slots'));

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'c']),
            config: ['operation' => 'claim', 'slots' => 'slots', 'capacity' => 2],
            context: $this->ctx(handle: $state)
        );

        self::assertSame(expected: 1, actual: $out[0]['json']['slot']);

    }//end testReleaseFreesTheSlotForTheNextClaim()

    /**
     * A slot can be released by holder when the number is unknown.
     *
     * A stage that crashed knows who it was, not which slot it got.
     *
     * @return void
     */
    public function testReleaseByHolderWhenTheSlotNumberIsUnknown(): void
    {
        $state = new FlowStateHandle(values: ['slots' => ['1' => 'a', '2' => 'b']]);

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'b']),
            config: ['operation' => 'release', 'slots' => 'slots'],
            context: $this->ctx(handle: $state)
        );

        self::assertTrue(condition: $out[0]['json']['released']);
        self::assertSame(expected: ['1' => 'a', '2' => null], actual: $state->get(key: 'slots'));

    }//end testReleaseByHolderWhenTheSlotNumberIsUnknown()

    /**
     * Releasing something nobody holds reports false and changes nothing.
     *
     * @return void
     */
    public function testReleasingAnUnheldSlotReportsFalse(): void
    {
        $state = new FlowStateHandle(values: ['slots' => ['1' => 'a']]);

        $out = $this->node->execute(
            items: $this->items(json: ['holder' => 'zzz']),
            config: ['operation' => 'release', 'slots' => 'slots'],
            context: $this->ctx(handle: $state)
        );

        self::assertFalse(condition: $out[0]['json']['released']);
        self::assertFalse(condition: $state->isDirty());

    }//end testReleasingAnUnheldSlotReportsFalse()

    /**
     * Get reads a key onto the item, with a default on the first tick.
     *
     * @return void
     */
    public function testGetReadsAKeyWithADefault(): void
    {
        $state = new FlowStateHandle(values: ['cursor' => 7]);

        $out = $this->node->execute(
            items: $this->items(json: []),
            config: ['operation' => 'get', 'key' => 'cursor'],
            context: $this->ctx(handle: $state)
        );
        self::assertSame(expected: 7, actual: $out[0]['json']['cursor']);

        $fresh = $this->node->execute(
            items: $this->items(json: []),
            config: ['operation' => 'get', 'key' => 'cursor', 'default' => 0],
            context: $this->ctx(handle: new FlowStateHandle())
        );
        self::assertSame(expected: 0, actual: $fresh[0]['json']['cursor']);

    }//end testGetReadsAKeyWithADefault()

    /**
     * Set can store a literal or a value read off the item.
     *
     * @return void
     */
    public function testSetStoresALiteralOrAValueFromTheItem(): void
    {
        $state = new FlowStateHandle();

        $this->node->execute(
            items: $this->items(json: []),
            config: ['operation' => 'set', 'key' => 'cursor', 'value' => 3],
            context: $this->ctx(handle: $state)
        );
        self::assertSame(expected: 3, actual: $state->get(key: 'cursor'));

        $this->node->execute(
            items: $this->items(json: ['lastId' => 99]),
            config: ['operation' => 'set', 'key' => 'cursor', 'from' => 'lastId'],
            context: $this->ctx(handle: $state)
        );
        self::assertSame(expected: 99, actual: $state->get(key: 'cursor'));

    }//end testSetStoresALiteralOrAValueFromTheItem()

    /**
     * A run with no flow state fails loudly rather than waving work through.
     *
     * Treating absent state as "everything is empty" would make a capacity cap
     * pass every claim.
     *
     * @return void
     */
    public function testARunWithoutFlowStateRefuses(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);

        $this->node->execute(
            items: $this->items(json: []),
            config: ['operation' => 'get', 'key' => 'cursor'],
            context: []
        );

    }//end testARunWithoutFlowStateRefuses()

    /**
     * Configuration is validated before a flow can be saved with it.
     *
     * @return void
     */
    public function testInvalidConfigurationIsRejected(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->node->validateConfig(config: ['operation' => 'nonsense']);

    }//end testInvalidConfigurationIsRejected()

    /**
     * A claim without a capacity cannot cap anything.
     *
     * @return void
     */
    public function testClaimWithoutCapacityIsRejected(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->node->validateConfig(config: ['operation' => 'claim', 'slots' => 'slots']);

    }//end testClaimWithoutCapacityIsRejected()
}//end class
