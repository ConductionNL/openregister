<?php
/**
 * A step whose config carries keys the node will never read.
 *
 * This is the half `validateConfig()` structurally cannot reach, and it is the
 * half that shipped four inert flows.
 *
 * `validateConfig()` answers ONE question: is anything REQUIRED missing. A node
 * only examines the keys it looks for, so a key it does not look for is
 * invisible to it by construction — however carefully the method is written.
 * Where a node requires nothing the method is a no-op no matter what:
 * `StopNode::validateConfig()` has a literally empty body, and on its own terms
 * that is correct, because a stop with no config is a perfectly good "end this
 * branch here".
 *
 * Which is exactly why StopNode was the node that let this through. Measured in
 * hydra#489:
 *
 *   config.status / .reason   StopNode reads error / message    → run stopped
 *                                                                 with the
 *                                                                 generic
 *                                                                 "Flow stopped"
 *                                                                 and
 *                                                                 isError=false
 *   config.input / .output    SubFlowNode reads flow/flowId/wait → child got
 *                                                                 nothing
 *
 * Both satisfied every required key. Both resolved, dispatched, and reported
 * COMPLETED. or#2254's preflight passed both, because it delegates entirely to
 * `validateConfig()` and neither step is missing anything required.
 *
 * The fix is {@see IFlowNodeConfigKeys}: a node states its WHOLE vocabulary, not
 * just its mandatory part, and the preflight compares.
 *
 * BOTH DIRECTIONS ARE TESTED HERE, deliberately. A validator that refuses valid
 * flows is worse than none, and the fleet's real flow documents carry `$why` and
 * `$comment` annotations throughout — a check without the annotation exemption
 * would refuse nearly every one of them.
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
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\Nodes\StopNode;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRegistry
 */
class FlowNodeConfigVocabularyTest extends TestCase
{

    /**
     * A preflight over the REAL nodes, with only openregister enabled.
     *
     * @return FlowNodePreflight
     */
    private function preflight(): FlowNodePreflight
    {
        return new FlowNodePreflight(
            $this->registry(),
            $this->appManager(),
            $this->createMock(LoggerInterface::class)
        );

    }//end preflight()

    /**
     * A registry carrying every node OpenRegister itself ships.
     *
     * @return FlowNodeRegistry
     */
    private function registry(): FlowNodeRegistry
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params=[]): string {
                return vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $params);
            }
        );
        $urls = $this->createMock(IURLGenerator::class);

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            function (Event $event) use ($l10n, $urls): void {
                if (($event instanceof RegisterFlowNodesEvent) === false) {
                    return;
                }

                $event->registerNode(new RouterNode($l10n, $urls));
                $event->registerNode(new SwitchNode($l10n, $urls));
                $event->registerNode(new SetFieldsNode($l10n, $urls));
                $event->registerNode(new FilterNode($l10n, $urls));
                $event->registerNode(new MergeNode($l10n, $urls));
                $event->registerNode(new LoopNode($l10n, $urls));
                $event->registerNode(new ExplodeNode($l10n, $urls));
                $event->registerNode(new WaitNode($l10n, $urls));
                $event->registerNode(new StopNode($l10n, $urls));

                // These three take collaborators (mappers, object service) that
                // nothing here exercises: only getId(), configKeys() and
                // validateConfig() are reached. The REAL class is used, with its
                // constructor bypassed, so the vocabulary under test is the
                // shipped one and not a restatement of it.
                foreach ([SubFlowNode::class, ObjectReadNode::class, ObjectWriteNode::class] as $class) {
                    $event->registerNode(
                        $this->getMockBuilder($class)
                            ->disableOriginalConstructor()
                            ->onlyMethods(['execute'])
                            ->getMock()
                    );
                }
            }
        );

        return new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));

    }//end registry()

    /**
     * An app manager in which only openregister is enabled.
     *
     * @return IAppManager
     */
    private function appManager(): IAppManager
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturnCallback(
            static function (string $app): bool {
                return ($app === 'openregister');
            }
        );

        return $appManager;

    }//end appManager()

    /**
     * Wrap one edge in the smallest document `looksLikeFlow()` accepts.
     *
     * @param array  $edge The edge under test.
     * @param string $name The flow's name.
     *
     * @return array The flow document.
     */
    private function flowWith(array $edge, string $name='test-flow'): array
    {
        // The step is the NODE now (or-flow-action-nodes), so what these tests
        // call an "edge" is a node. The parameter name is kept because every
        // caller passes a step-shaped array and the subject of the tests —
        // which config keys a node actually reads — is unchanged.
        return [
            'name'  => $name,
            // `$edge` FIRST: `+` keeps the left operand's keys, so putting the
            // default id on the left would discard the caller's own id and
            // every "names the offending step" assertion would look for a step
            // that no longer exists by that name.
            // `exit` so a one-node fixture is a COMPLETE document, not a dead
            // end. This suite is about a node's config vocabulary; without the
            // flag every fixture would also collect a connectivity warning and
            // each "exactly one finding" assertion would be counting two
            // unrelated things.
            'nodes' => [($edge + ['id' => 'a', 'exit' => true])],
            'edges' => [],
        ];

    }//end flowWith()

    /**
     * THE REGRESSION — a stop step in another node's dialect.
     *
     * Verbatim from the hydra flow that shipped it. or#2254's preflight passes
     * this document: `StopNode::validateConfig()` requires nothing, so there is
     * nothing for it to object to.
     *
     * @return void
     */
    public function testAStopStepInTheWrongDialectIsRefused(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'give-up',
                'type'   => 'openregister.stop',
                'config' => [
                    'status' => 'failed',
                    'reason' => 'no work left',
                ],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertCount(1, $report['blocking'], 'One step, one finding.');
        $this->assertSame(
            FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY,
            $report['blocking'][0]['reason']
        );
        $this->assertSame('give-up', $report['blocking'][0]['step']);
        $this->assertStringContainsString('status', $report['blocking'][0]['detail']);
        $this->assertStringContainsString('reason', $report['blocking'][0]['detail']);
        // The message must say what the node DOES read, or the author is left
        // to go and find the source.
        $this->assertStringContainsString('error', $report['blocking'][0]['reads']);
        $this->assertStringContainsString('message', $report['blocking'][0]['reads']);

    }//end testAStopStepInTheWrongDialectIsRefused()

    /**
     * ...and the SAVE is refused, not merely reported.
     *
     * @return void
     */
    public function testTheSaveIsRefusedForABogusStopConfig(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'give-up',
                'type'   => 'openregister.stop',
                'config' => ['status' => 'failed'],
            ]
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/give-up/');
        $this->preflight()->assertRunnable(flow: $flow);

    }//end testTheSaveIsRefusedForABogusStopConfig()

    /**
     * POSITIVE CONTROL — the same step in the dialect StopNode reads.
     *
     * Without this the test above is satisfied by a preflight that refuses every
     * stop step it is shown.
     *
     * @return void
     */
    public function testTheCorrectStopDialectPasses(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'give-up',
                'type'   => 'openregister.stop',
                'config' => [
                    'error'   => true,
                    'message' => 'no work left',
                ],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertSame([], $report['blocking']);
        $this->assertSame([], $report['warnings']);

    }//end testTheCorrectStopDialectPasses()

    /**
     * SECOND CONTROL — a stop step with NO config at all.
     *
     * "End this branch here" is the ordinary use of this node and must not
     * become a validation error just because the node now has a vocabulary.
     *
     * @return void
     */
    public function testAStopStepWithNoConfigPasses(): void
    {
        $flow = $this->flowWith(['id' => 'done', 'type' => 'openregister.stop']);

        $this->assertSame([], $this->preflight()->inspect(flow: $flow)['blocking']);

    }//end testAStopStepWithNoConfigPasses()

    /**
     * THE SECOND REGRESSION — a sub-flow step declaring input/output.
     *
     * `SubFlowNode` requires only `flow`, so `validateConfig()` accepts this and
     * the child receives nothing the author meant to hand it.
     *
     * @return void
     */
    public function testASubFlowStepDeclaringInputAndOutputIsRefused(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'delegate',
                'type'   => 'openregister.sub-flow',
                'config' => [
                    'flow'   => 'a1b2c3d4-0000-0000-0000-000000000000',
                    'input'  => ['issue' => '{{issue}}'],
                    'output' => 'result',
                ],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertCount(1, $report['blocking']);
        $this->assertSame(
            FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY,
            $report['blocking'][0]['reason']
        );
        $this->assertStringContainsString('input', $report['blocking'][0]['detail']);
        $this->assertStringContainsString('output', $report['blocking'][0]['detail']);

    }//end testASubFlowStepDeclaringInputAndOutputIsRefused()

    /**
     * POSITIVE CONTROL — the same sub-flow step without the invented keys.
     *
     * @return void
     */
    public function testACorrectSubFlowStepPasses(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'delegate',
                'type'   => 'openregister.sub-flow',
                'config' => [
                    'flow' => 'a1b2c3d4-0000-0000-0000-000000000000',
                    'wait' => true,
                ],
            ]
        );

        $this->assertSame([], $this->preflight()->inspect(flow: $flow)['blocking']);

    }//end testACorrectSubFlowStepPasses()

    /**
     * A switch declaring config is refused — it reads none.
     *
     * An empty vocabulary is a real statement, not a missing one. An author who
     * writes `rules` here has drawn a switch and configured a route; the flow
     * would send every item down the first edge without complaint.
     *
     * @return void
     */
    public function testASwitchDeclaringAnyConfigIsRefused(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'branch',
                'type'   => 'openregister.switch',
                'config' => ['rules' => [['condition' => true, 'output' => 'x']]],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertCount(1, $report['blocking']);
        $this->assertSame(
            FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY,
            $report['blocking'][0]['reason']
        );
        $this->assertSame('', $report['blocking'][0]['reads']);

    }//end testASwitchDeclaringAnyConfigIsRefused()

    /**
     * ...and a switch with no config at all still passes.
     *
     * @return void
     */
    public function testABareSwitchPasses(): void
    {
        $flow = $this->flowWith(['id' => 'branch', 'type' => 'openregister.switch']);

        $this->assertSame([], $this->preflight()->inspect(flow: $flow)['blocking']);

    }//end testABareSwitchPasses()

    /**
     * THE RULE THAT KEEPS THE CHECK USABLE — `$`-prefixed keys are annotations.
     *
     * `$why` and `$comment` appear throughout the fleet's flow documents. The
     * engine has never read them. Refusing them would refuse nearly every real
     * flow, which is a worse outcome than no check at all.
     *
     * @return void
     */
    public function testAnnotationKeysAreTolerated(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'derive',
                'type'   => 'openregister.set-fields',
                'config' => [
                    '$why'     => 'the verdict labels are what the router reads',
                    '$comment' => 'kept flat on purpose',
                    'compute'  => ['codePass' => ['var' => 'labels']],
                ],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertSame([], $report['blocking']);
        $this->assertSame([], $report['warnings']);

    }//end testAnnotationKeysAreTolerated()

    /**
     * A misplaced `onError` gets its own diagnosis, not "unknown key".
     *
     * `FlowEngine::policyFor()` reads `$step['onError']` — the EDGE, one level up
     * from `config`. An author who buries it there did not invent a key; they
     * put a real option in the wrong place, and saying so is worth more than
     * saying the key is unrecognised.
     *
     * Blocking when the buried policy differs from the engine default, because
     * that is when it changes what the run does.
     *
     * @return void
     */
    public function testABuriedNonDefaultOnErrorPolicyIsRefused(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'read',
                'type'   => 'openregister.object-read',
                'config' => [
                    'register' => 'hydra',
                    'schema'   => 'lock',
                    'onError'  => 'continue',
                ],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertCount(1, $report['blocking']);
        $this->assertSame(
            FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED,
            $report['blocking'][0]['reason']
        );
        $this->assertStringContainsString(
            'beside "type"',
            $this->preflight()->describe(flow: 'test-flow', blocking: $report['blocking'])
        );

    }//end testABuriedNonDefaultOnErrorPolicyIsRefused()

    /**
     * ...and a buried `"stop"` WARNS rather than refusing.
     *
     * It is still wrong, but it is wrong in the direction the engine was already
     * going — the run behaves identically. Three edges across the fleet's live
     * flow documents are in exactly this state, and refusing a fleet's worth of
     * working flows over a no-op key is the "worse than none" failure.
     *
     * The threshold is deliberately the same one
     * `hydra/scripts/test-flow-definitions.sh` applies, so two guards cannot
     * disagree about one document.
     *
     * @return void
     */
    public function testABuriedDefaultOnErrorPolicyOnlyWarns(): void
    {
        $flow = $this->flowWith(
            [
                'id'     => 'read',
                'type'   => 'openregister.object-read',
                'config' => [
                    'register' => 'hydra',
                    'schema'   => 'lock',
                    'onError'  => 'stop',
                ],
            ]
        );

        $report = $this->preflight()->inspect(flow: $flow);

        $this->assertSame([], $report['blocking']);
        $this->assertCount(1, $report['warnings']);
        $this->assertSame(
            FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED,
            $report['warnings'][0]['reason']
        );

    }//end testABuriedDefaultOnErrorPolicyOnlyWarns()

    /**
     * A node that declares NO vocabulary is not vocabulary-checked.
     *
     * openconnector and hermiq contribute nodes from their own repositories and
     * predate this contract. Guessing at their keys would refuse correct flows,
     * so they are skipped — today's behaviour preserved exactly.
     *
     * @return void
     */
    public function testANodeThatDeclaresNoVocabularyIsSkipped(): void
    {
        $node = new class implements IFlowNode {
            public function getId(): string
            {
                return 'openregister.legacy-thing';
            }

            public function getDisplayName(): string
            {
                return 'Legacy';
            }

            public function getDescription(): string
            {
                return 'Predates IFlowNodeConfigKeys.';
            }

            public function getIcon(): string
            {
                return '';
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
                return $items;
            }
        };

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            static function (Event $event) use ($node): void {
                if ($event instanceof RegisterFlowNodesEvent) {
                    $event->registerNode($node);
                }
            }
        );

        $preflight = new FlowNodePreflight(
            new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class)),
            $this->appManager(),
            $this->createMock(LoggerInterface::class)
        );

        $flow = $this->flowWith(
            [
                'id'     => 'legacy',
                'type'   => 'openregister.legacy-thing',
                'config' => ['anythingAtAll' => true, 'andThis' => 'too'],
            ]
        );

        $this->assertSame([], $preflight->inspect(flow: $flow)['blocking']);

    }//end testANodeThatDeclaresNoVocabularyIsSkipped()

    /**
     * Every node OpenRegister ships declares its vocabulary.
     *
     * The contract is opt-in so that out-of-tree nodes do not fatal on upgrade.
     * That leniency must not become a quiet way for an OpenRegister node to
     * escape the check — a ratchet, not a suggestion.
     *
     * @return void
     */
    public function testEveryInTreeNodeDeclaresItsVocabulary(): void
    {
        $missing = [];
        foreach ($this->registry()->all() as $id => $node) {
            if (($node instanceof IFlowNodeConfigKeys) === false) {
                $missing[] = $id;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These OpenRegister nodes do not declare configKeys(), so any config key at all passes on them.'
        );

    }//end testEveryInTreeNodeDeclaresItsVocabulary()

    /**
     * A declared key must be one the node's source actually reads.
     *
     * A vocabulary nobody checks is a second hand-maintained table, and the
     * whole point of the contract is to have exactly one. This reads the node's
     * own source and asserts every key it declares appears in it.
     *
     * @return void
     */
    public function testEveryDeclaredKeyAppearsInTheNodeSource(): void
    {
        $problems = [];
        foreach ($this->registry()->all() as $id => $node) {
            if (($node instanceof IFlowNodeConfigKeys) === false) {
                continue;
            }

            // A mocked node's reflection points at the mock, so climb to the
            // class it was built from.
            $class = new \ReflectionClass($node);
            while ($class->isAnonymous() === true || str_contains($class->getName(), 'MockObject') === true) {
                $parent = $class->getParentClass();
                if ($parent === false) {
                    continue 2;
                }

                $class = $parent;
            }

            $source = (string) file_get_contents((string) $class->getFileName());
            foreach ($node->configKeys() as $key) {
                if (str_contains($source, "'".$key."'") === false) {
                    $problems[] = $id.': '.$key;
                }
            }
        }

        $this->assertSame([], $problems, 'Declared config keys that the node source never mentions.');

    }//end testEveryDeclaredKeyAppearsInTheNodeSource()

    /**
     * The node catalogue serves the vocabulary, so nothing has to restate it.
     *
     * `hydra/scripts/test-flow-definitions.sh` keeps its own table of accepted
     * keys per node type. Two hand-maintained tables in two repositories drift;
     * this is the surface that lets the second one be deleted.
     *
     * @return void
     */
    public function testThePaletteServesTheVocabulary(): void
    {
        $palette = $this->registry()->palette(scope: IManager::SCOPE_ADMIN);
        $byId    = array_column($palette, null, 'id');

        $this->assertArrayHasKey('openregister.stop', $byId);
        $this->assertSame(['error', 'message'], $byId['openregister.stop']['configKeys']);

        // An empty declaration must survive as `[]`, not vanish — "reads no
        // config" and "did not say" are different answers.
        $this->assertArrayHasKey('configKeys', $byId['openregister.switch']);
        $this->assertSame([], $byId['openregister.switch']['configKeys']);

    }//end testThePaletteServesTheVocabulary()
}//end class
