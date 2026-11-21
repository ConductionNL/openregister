<?php

/**
 * Unit test for FlowNodePreflight's less-travelled branches: what reads as a
 * flow, a step left on an edge, unresolved and unknown-owner types, configs the
 * node rejects or fails on, and how assertRunnable logs warnings and refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 * @uses   \OCA\OpenRegister\Service\Flow\FlowPreflightStep
 * @uses   \OCA\OpenRegister\Service\Flow\FlowConnectivity
 * @uses   \OCA\OpenRegister\Service\Flow\FlowGraph
 */
class FlowNodePreflightBranchesTest extends TestCase {
	use FiltersFlowLevelFindings;

	/**
	 * The node types the registry knows, by id.
	 *
	 * @var array<string, IFlowNode>
	 */
	private array $known = [];

	/**
	 * The app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $apps;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mocked app manager and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->apps   = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The preflight over the known node types.
	 *
	 * @return FlowNodePreflight
	 */
	private function preflight(): FlowNodePreflight {
		$registry = $this->createMock(FlowNodeCatalogueInterface::class);
		$registry->method('get')->willReturnCallback(
			function (string $type): IFlowNode {
				if (isset($this->known[$type]) === false) {
					throw new UnexpectedValueException('No app provides "'.$type.'"');
				}

				return $this->known[$type];
			}
		);
		return new FlowNodePreflight($registry, $this->apps, $this->logger);
	}//end preflight()

	/**
	 * A flow with one typed node.
	 *
	 * @param array<string, mixed> $node The typed node.
	 *
	 * @return array<string, mixed>
	 */
	private function flowWith(array $node): array {
		return [
			'name'  => 'demo',
			'nodes' => [['id' => 'a'] + $node, ['id' => 'b']],
			'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
		];
	}//end flowWith()

	/**
	 * Shapes that do not read as a flow, and two that do.
	 *
	 * @return void
	 */
	public function testWhatLooksLikeAFlow(): void {
		$preflight = $this->preflight();
		$nodes     = [['id' => 'a'], ['id' => 'b']];
		$this->assertFalse($preflight->looksLikeFlow(data: ['nodes' => $nodes]));
		$this->assertFalse($preflight->looksLikeFlow(data: ['nodes' => [], 'edges' => [['from' => 'a', 'to' => 'b']]]));
		$this->assertFalse($preflight->looksLikeFlow(data: ['nodes' => [['name' => 'x']], 'edges' => [['from' => 'a', 'to' => 'b']]]));
		$this->assertFalse($preflight->looksLikeFlow(data: ['nodes' => $nodes, 'edges' => []]));
		$this->assertFalse($preflight->looksLikeFlow(data: ['nodes' => $nodes, 'edges' => ['a->b']]));
		$this->assertFalse($preflight->looksLikeFlow(data: ['nodes' => $nodes, 'edges' => [['from' => 'a']]]));
		$this->assertTrue($preflight->looksLikeFlow(data: ['nodes' => $nodes, 'edges' => [['from' => 'a', 'to' => 'b']]]));
		$this->assertTrue($preflight->looksLikeFlow(data: ['nodes' => $nodes, 'edges' => [['source' => 'a', 'target' => 'b']]]));
	}//end testWhatLooksLikeAFlow()

	/**
	 * A step on an edge blocks as the pre-inversion shape and nothing else is inspected.
	 *
	 * @return void
	 */
	public function testAStepOnAnEdgeBlocksAlone(): void {
		$report = $this->preflight()->inspect(flow: [
			'nodes' => [['id' => 'a', 'type' => 'nope'], ['id' => 'b']],
			'edges' => [['name' => 'mail', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.send-email'], 'junk', ['from' => 'a', 'to' => 'b']],
		]);

		$this->assertSame(['blocking' => [[
			'type'   => 'openregister.send-email',
			'app'    => 'openregister',
			'step'   => 'mail',
			'reason' => FlowNodePreflight::REASON_PRE_INVERSION_SHAPE,
			'detail' => 'the step is on an edge; a node is the action that runs',
		]], 'warnings' => []], $report);
	}//end testAStepOnAnEdgeBlocksAlone()

	/**
	 * An untyped or non-map node yields nothing; an un-namespaced unknown type blocks.
	 *
	 * @return void
	 */
	public function testUntypedNodesPassAndUnNamespacedTypesBlock(): void {
		$report = $this->preflight()->inspect(flow: [
			'nodes' => ['junk', ['id' => 'a', 'type' => '  '], ['id' => 'c', 'type' => 'sendmail'], ['id' => 'b']],
			'edges' => [['from' => 'a', 'to' => 'b']],
		]);

		$this->assertSame([['type' => 'sendmail', 'app' => '', 'step' => 'c', 'reason' => FlowNodePreflight::REASON_NOT_NAMESPACED]], $report['blocking']);
	}//end testUntypedNodesPassAndUnNamespacedTypesBlock()

	/**
	 * A type whose owner is enabled but lacks it blocks; one whose owner is not enabled, or cannot be asked, warns.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeBlocksOnlyWhenItsOwnerIsEnabled(): void {
		$this->apps->method('isEnabledForUser')->willReturnCallback(
			function (string $app): bool {
				if ($app === 'broken') {
					throw new RuntimeException('appstore down');
				}

				return $app === 'openregister';
			}
		);
		$this->logger->expects($this->once())->method('warning')->with('[FlowNodePreflight] Could not determine whether app "broken" is enabled: appstore down');
		$preflight = $this->preflight();

		$missing = $preflight->inspect(flow: $this->flowWith(node: ['type' => 'openregister.explode']));
		$this->assertSame([FlowNodePreflight::REASON_MISSING_FROM_OWNER], array_column($missing['blocking'], 'reason'));

		$absent = $preflight->inspect(flow: $this->flowWith(node: ['type' => 'hermiq.ask']));
		$this->assertSame([], $absent['blocking']);
		$this->assertSame([FlowNodePreflight::REASON_OWNER_NOT_ENABLED], array_column($this->nodeWarnings(report: $absent), 'reason'));

		$unknown = $preflight->inspect(flow: $this->flowWith(node: ['type' => 'broken.step']));
		$this->assertSame([], $unknown['blocking']);
		$this->assertSame([FlowNodePreflight::REASON_OWNER_NOT_ENABLED], array_column($this->nodeWarnings(report: $unknown), 'reason'));
	}//end testAnUnknownTypeBlocksOnlyWhenItsOwnerIsEnabled()

	/**
	 * A config that is not an object, and one the node refuses, block with the reason.
	 *
	 * @return void
	 */
	public function testARejectedConfigBlocks(): void {
		$node = $this->createMock(IFlowNode::class);
		$node->method('validateConfig')->willReturnCallback(
			function (array $config): void {
				if (($config['to'] ?? '') === '') {
					throw new InvalidArgumentException('to is required');
				}
			}
		);
		$this->known = ['openregister.send-email' => $node];
		$preflight   = $this->preflight();

		$scalar = $preflight->inspect(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => 'to=a@b.nl']));
		$this->assertSame([['type' => 'openregister.send-email', 'app' => 'openregister', 'step' => 'a', 'reason' => FlowNodePreflight::REASON_CONFIG_REJECTED, 'detail' => 'Step config must be an object.']], $scalar['blocking']);

		$empty = $preflight->inspect(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => []]));
		$this->assertSame('to is required', $empty['blocking'][0]['detail']);

		$fine = $preflight->inspect(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => ['to' => 'a@b.nl']]));
		$this->assertSame([], $fine['blocking']);
	}//end testARejectedConfigBlocks()

	/**
	 * A validateConfig or configKeys that fails for its own reasons is logged and does not block.
	 *
	 * @return void
	 */
	public function testANodeFailingForItsOwnReasonsDoesNotBlock(): void {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('validateConfig')->willThrowException(new RuntimeException('db gone'));
		$node->method('configKeys')->willThrowException(new RuntimeException('keys gone'));
		$this->known = ['openregister.send-email' => $node];
		$messages    = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$report = $this->preflight()->inspect(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => ['colour' => 'x']]));

		$this->assertSame([], $report['blocking']);
		$this->assertSame(
			[
				'[FlowNodePreflight] A node\'s validateConfig() failed for its own reasons, not blocking: db gone',
				'[FlowNodePreflight] A node\'s configKeys() failed, skipping the dialect check: keys gone',
			],
			$messages
		);
	}//end testANodeFailingForItsOwnReasonsDoesNotBlock()

	/**
	 * A declaring node with an empty config gets no dialect check; an unread key blocks.
	 *
	 * @return void
	 */
	public function testTheDialectCheckRunsOnlyOnAFilledConfig(): void {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('configKeys')->willReturn([0 => 'to']);
		$this->known = ['openregister.send-email' => $node];
		$preflight   = $this->preflight();

		$this->assertSame([], $preflight->inspect(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => []]))['blocking']);
		$this->assertSame(
			[FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY],
			array_column($preflight->inspect(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => ['cc' => 'x']]))['blocking'], 'reason')
		);
	}//end testTheDialectCheckRunsOnlyOnAFilledConfig()

	/**
	 * A misplaced default onError is logged as a warning with its own message and no app in the context.
	 *
	 * @return void
	 */
	public function testAssertRunnableLogsAMisplacedOnError(): void {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('configKeys')->willReturn(['to']);
		$this->known = ['openregister.send-email' => $node];
		$logged      = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$this->preflight()->assertRunnable(flow: $this->flowWith(node: ['type' => 'openregister.send-email', 'config' => ['to' => 'x', 'onError' => 'stop']]), label: 'Bezwaar');

		$onError = array_values(array_filter($logged, fn (array $l): bool => $l[1]['reason'] === FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED));
		$this->assertCount(1, $onError);
		$this->assertStringStartsWith('[FlowNodePreflight] Flow "Bezwaar", step "a" (openregister.send-email) sets config.onError="stop". ', $onError[0][0]);
		$this->assertArrayNotHasKey('app', $onError[0][1]);
		$this->assertSame('Bezwaar', $onError[0][1]['flow']);
	}//end testAssertRunnableLogsAMisplacedOnError()

	/**
	 * A refusal of an unread key on a node that reads nothing says so.
	 *
	 * @return void
	 */
	public function testARefusalNamesWhatTheNodeReads(): void {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('configKeys')->willReturn([]);
		$this->known = ['openregister.switch' => $node];

		try {
			$this->preflight()->assertRunnable(flow: $this->flowWith(node: ['type' => 'openregister.switch', 'config' => ['cases' => []]]));
			$this->fail('a step with an unread key must be refused');
		} catch (UnexpectedValueException $e) {
			$this->assertStringStartsWith('Flow "demo" has 1 step(s) this instance cannot run as written: ', $e->getMessage());
			$this->assertStringContainsString('"openregister.switch" (step a)', $e->getMessage());
			$this->assertStringContainsString('no config at all', $e->getMessage());
		}
	}//end testARefusalNamesWhatTheNodeReads()
}//end class
