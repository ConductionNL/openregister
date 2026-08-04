<?php

/**
 * Tests for the declared loop region.
 *
 * The cases that matter are the ones about STOPPING: a loop that never
 * terminates costs side effects, and a loop that terminates early silently
 * discards work. Both are asserted here, and both were observed failing against
 * a deliberately broken implementation before being trusted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * IterateNode behaviour.
 */
class IterateNodeTest extends TestCase
{

    /**
     * The node under test.
     *
     * @var IterateNode
     */
    private IterateNode $node;

    /**
     * The dispatcher the node drives its source and body through.
     *
     * @var FlowStepDispatcher|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dispatcher;

    /**
     * Build the node with a mocked dispatcher.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/icon.svg');

        $this->dispatcher = $this->createMock(FlowStepDispatcher::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->dispatcher);

        $this->node = new IterateNode(l10n: $l10n, urls: $urls, container: $container);

    }//end setUp()

    /**
     * A three-page source runs the body three times and returns every item.
     *
     * @return void
     */
    public function testItRunsTheBodyOncePerBatchAndAccumulates(): void
    {
        $pages = [
            [FlowItems::item(json: ['p' => 1])],
            [FlowItems::item(json: ['p' => 2])],
            [FlowItems::item(json: ['p' => 3])],
            [],
        ];

        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (array $step, array $items, array $context) use (&$pages): array {
                if ($step['type'] === 'src') {
                    return array_shift($pages);
                }

                return $items;
            }
        );

        $out = $this->node->execute(
            [],
            [
                'source' => ['type' => 'src'],
                'body'   => [['type' => 'body']],
            ],
            []
        );

        $this->assertCount(3, $out, 'every page must survive, not just the last');

    }//end testItRunsTheBodyOncePerBatchAndAccumulates()

    /**
     * An empty first batch means the body never runs.
     *
     * @return void
     */
    public function testAnEmptySourceRunsTheBodyNotAtAll(): void
    {
        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (array $step, array $items, array $context): array {
                if ($step['type'] === 'src') {
                    return [];
                }

                throw new \LogicException('the body must not run when the source is empty');
            }
        );

        $out = $this->node->execute([], ['source' => ['type' => 'src'], 'body' => [['type' => 'body']]], []);
        $this->assertSame([], $out);

    }//end testAnEmptySourceRunsTheBodyNotAtAll()

    /**
     * The source is told which iteration it is on, so it can page.
     *
     * @return void
     */
    public function testTheSourceSeesItsIterationIndex(): void
    {
        $seen = [];

        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (array $step, array $items, array $context) use (&$seen): array {
                if ($step['type'] !== 'src') {
                    return $items;
                }

                $seen[] = $context['iteration']['index'];

                return count($seen) < 3 ? [FlowItems::item(json: [])] : [];
            }
        );

        $this->node->execute([], ['source' => ['type' => 'src'], 'body' => [['type' => 'b']]], []);

        $this->assertSame([0, 1, 2], $seen, 'the source must be able to ask for the right page');

    }//end testTheSourceSeesItsIterationIndex()

    /**
     * A source that never runs out FAILS rather than looping forever.
     *
     * @return void
     */
    public function testANonConvergingLoopFails(): void
    {
        $this->dispatcher->method('dispatch')->willReturnCallback(
            static fn (array $step, array $items, array $context): array => [FlowItems::item(json: [])]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not finish within 5 iterations/');

        $this->node->execute(
            [],
            [
                'source'        => ['type' => 'src'],
                'body'          => [['type' => 'b']],
                'maxIterations' => 5,
            ],
            []
        );

    }//end testANonConvergingLoopFails()

    /**
     * onLimit=stop keeps what it gathered instead of failing.
     *
     * @return void
     */
    public function testOnLimitStopKeepsWhatItGathered(): void
    {
        $this->dispatcher->method('dispatch')->willReturnCallback(
            static fn (array $step, array $items, array $context): array => [FlowItems::item(json: [])]
        );

        $out = $this->node->execute(
            [],
            [
                'source'        => ['type' => 'src'],
                'body'          => [['type' => 'b']],
                'maxIterations' => 4,
                'onLimit'       => IterateNode::ON_LIMIT_STOP,
            ],
            []
        );

        $this->assertCount(4, $out);

    }//end testOnLimitStopKeepsWhatItGathered()

    /**
     * Every body step runs, in the declared order.
     *
     * @return void
     */
    public function testTheWholeBodyChainRunsInOrder(): void
    {
        $order = [];
        $pages = [[FlowItems::item(json: [])], []];

        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (array $step, array $items, array $context) use (&$order, &$pages): array {
                if ($step['type'] === 'src') {
                    return array_shift($pages);
                }

                $order[] = $step['type'];

                return $items;
            }
        );

        $this->node->execute(
            [],
            [
                'source' => ['type' => 'src'],
                'body'   => [['type' => 'one'], ['type' => 'two'], ['type' => 'three']],
            ],
            []
        );

        $this->assertSame(['one', 'two', 'three'], $order);

    }//end testTheWholeBodyChainRunsInOrder()

    /**
     * A loop with no source is refused at save time.
     *
     * @return void
     */
    public function testALoopWithoutASourceIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['body' => [['type' => 'b']]]);

    }//end testALoopWithoutASourceIsRefused()

    /**
     * A loop with an empty body is refused — it would spin doing nothing.
     *
     * @return void
     */
    public function testALoopWithAnEmptyBodyIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['source' => ['type' => 'src'], 'body' => []]);

    }//end testALoopWithAnEmptyBodyIsRefused()

    /**
     * A body step with no type is refused, naming its position.
     *
     * @return void
     */
    public function testATypelessBodyStepIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['source' => ['type' => 'src'], 'body' => [['config' => []]]]);

    }//end testATypelessBodyStepIsRefused()

    /**
     * A non-positive limit is refused: zero iterations is not a loop.
     *
     * @return void
     */
    public function testANonPositiveLimitIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(
            ['source' => ['type' => 'src'], 'body' => [['type' => 'b']], 'maxIterations' => 0]
        );

    }//end testANonPositiveLimitIsRefused()

    /**
     * The catalogue id stored flow definitions reference.
     *
     * @return void
     */
    public function testItsCatalogueIdIsStable(): void
    {
        $this->assertSame('openregister.iterate', $this->node->getId());

    }//end testItsCatalogueIdIsStable()
}//end class
