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
        self::assertSame(expected: ['1' => 'issue-42'], actual: $state->get(key: 'slots'));

    }//end testClaimTakesTheFirstFreeSlot()

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
        self::assertSame(expected: ['1' => 'issue-1', '2' => 'issue-2'], actual: $state->get(key: 'slots'));

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
        self::assertSame(expected: ['1' => 'a', '2' => 'b'], actual: $state->get(key: 'slots'));

    }//end testAtCapacityTheClaimIsRefused()

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
