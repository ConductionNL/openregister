<?php
/**
 * A step whose type is right and whose config dialect is wrong.
 *
 * This is the harder half of the same defect. Resolving `edges[].type` proves an
 * app provides the step; it says nothing about whether the node can READ the
 * config the edge hands it. A node reads the keys it implements and silently
 * ignores the rest, so an edge written in another node's dialect resolves,
 * runs, returns its input untouched, and reports COMPLETED.
 *
 * Measured across the ten hydra flows: four are inert exactly this way.
 * `hydra-analyze-verdicts` declares `routes[].when` / `routes[].to` where
 * RouterNode reads `rules[].output`, and `fields` where SetFieldsNode reads
 * `set` / `compute`. That flow cannot run at all — and
 * `scripts/test-flow-definitions.sh` passes on it, because it validates graph
 * STRUCTURE and is blind to dialect.
 *
 * Every node already implements `validateConfig()`; the contract is on
 * IFlowNode. It was simply never called when a flow was saved. SetFieldsNode's
 * own docblock says the check exists so a malformed expression is "caught HERE,
 * when the flow is saved, rather than evaluating to null on every item at
 * 03:00" — which is precisely what it did not do.
 *
 * These tests use the REAL RouterNode and SetFieldsNode with the REAL config
 * from hydra-analyze-verdicts.
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

use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 */
class FlowNodeConfigDialectTest extends TestCase
{

    /**
     * A preflight over the REAL router and set-fields nodes.
     *
     * @return FlowNodePreflight
     */
    private function preflight(): FlowNodePreflight
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $t, array $p=[]): string => vsprintf(str_replace('%s', '%s', $t), $p)
        );
        $urls = $this->createMock(IURLGenerator::class);

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            static function (Event $event) use ($l10n, $urls): void {
                if (($event instanceof RegisterFlowNodesEvent) === false) {
                    return;
                }

                $event->registerNode(new RouterNode($l10n, $urls));
                $event->registerNode(new SetFieldsNode($l10n, $urls));
            }
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(true);

        return new FlowNodePreflight(
            new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class)),
            $appManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * THE REGRESSION — hydra-analyze-verdicts, verbatim.
     *
     * `routes[].when`/`.to` where RouterNode reads `rules[].output`, and
     * `fields` where SetFieldsNode reads `set`/`compute`. Both types resolve.
     *
     * @return void
     */
    public function testTheWrongDialectIsRefusedEvenThoughTheTypeResolves(): void
    {
        $flow = [
            'name'  => 'hydra-analyze-verdicts',
            'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            'edges' => [
                [
                    'id'     => 'derive',
                    'from'   => 'a',
                    'to'     => 'b',
                    'type'   => 'openregister.set-fields',
                    'config' => [
                        'fields' => ['codePass' => ['var' => 'labels']],
                    ],
                ],
                [
                    'id'     => 'code-contradiction',
                    'from'   => 'b',
                    'to'     => 'c',
                    'type'   => 'openregister.route',
                    'config' => [
                        'routes'  => [
                            ['when' => ['var' => 'codePass'], 'to' => 'drop-code-pass'],
                        ],
                        'default' => 'skip-code-drop',
                    ],
                ],
            ],
        ];

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertSame([], $report['warnings']);
        $this->assertCount(2, $report['blocking'], 'Both dialect mismatches must be caught.');

        foreach ($report['blocking'] as $finding) {
            $this->assertSame(FlowNodePreflight::REASON_CONFIG_REJECTED, $finding['reason']);
            $this->assertSame('openregister', $finding['app']);
            $this->assertNotSame('', ($finding['detail'] ?? ''));
        }

        $edges = array_column($report['blocking'], 'edge');
        sort($edges);
        $this->assertSame(['code-contradiction', 'derive'], $edges);
    }

    /**
     * ...and the save is refused, naming both edges.
     *
     * @return void
     */
    public function testTheSaveIsRefusedNamingBothEdges(): void
    {
        $flow = [
            'name'  => 'hydra-analyze-verdicts',
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [
                [
                    'id'     => 'derive',
                    'from'   => 'a',
                    'to'     => 'b',
                    'type'   => 'openregister.set-fields',
                    'config' => ['fields' => ['x' => 1]],
                ],
            ],
        ];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/derive/');
        $this->preflight()->assertRunnable(flow: $flow);
    }

    /**
     * POSITIVE CONTROL — the SAME edges in the dialect the nodes actually read.
     *
     * Without this, the tests above are satisfied by a preflight that refuses
     * every config it is shown.
     *
     * @return void
     */
    public function testTheCorrectDialectPasses(): void
    {
        $flow = [
            'name'  => 'hydra-analyze-verdicts (corrected)',
            'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            'edges' => [
                [
                    'id'     => 'derive',
                    'from'   => 'a',
                    'to'     => 'b',
                    'type'   => 'openregister.set-fields',
                    'config' => [
                        'compute' => ['codePass' => ['var' => 'labels']],
                    ],
                ],
                [
                    'id'     => 'code-contradiction',
                    'from'   => 'b',
                    'to'     => 'c',
                    'type'   => 'openregister.route',
                    'config' => [
                        'rules'   => [
                            ['condition' => ['var' => 'codePass'], 'output' => 'drop-code-pass'],
                        ],
                        'default' => 'skip-code-drop',
                    ],
                ],
            ],
        ];

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertSame(['blocking' => [], 'warnings' => []], $report);
    }

    /**
     * A node whose app is absent is still only WARNED about.
     *
     * Config cannot be checked for a node that is not here, and an absent
     * optional app must not block an import. This pins that the config check
     * did not accidentally turn absence into a refusal.
     *
     * @return void
     */
    public function testAnAbsentAppIsStillOnlyAWarning(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $urls = $this->createMock(IURLGenerator::class);

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            static function (Event $event) use ($l10n, $urls): void {
                if ($event instanceof RegisterFlowNodesEvent) {
                    $event->registerNode(new RouterNode($l10n, $urls));
                }
            }
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturnCallback(
            static fn (string $app): bool => ($app === 'openregister')
        );

        $preflight = new FlowNodePreflight(
            new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class)),
            $appManager,
            $this->createMock(LoggerInterface::class)
        );

        $report = $preflight->inspect(
            flow: [
                'name'  => 'partial',
                'nodes' => [['id' => 'a'], ['id' => 'b']],
                'edges' => [
                    [
                        'id'     => 'call',
                        'from'   => 'a',
                        'to'     => 'b',
                        'type'   => 'openconnector.source-call',
                        'config' => ['method' => 'GET'],
                    ],
                ],
            ]
        );

        $this->assertSame([], $report['blocking']);
        $this->assertCount(1, $report['warnings']);
    }
}
