<?php

/**
 * AwaitSignalNode: its palette entry, the business key rendered from the
 * first usable item, a non-item entry passing an answer untouched, and the
 * heartbeat floor.
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
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowSuspension
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 */
class AwaitSignalNodeBehaviourTest extends TestCase {

	private AwaitSignalNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/' . $app . '/img/' . $image);
		$this->node = new AwaitSignalNode($l10n, $urls);
	}//end setUp()

	/**
	 * Run the node until it suspends and return the slot it stamped.
	 *
	 * @param array<int, mixed> $items
	 * @param array<string, mixed> $config
	 *
	 * @return array{0: FlowSuspension, 1: FlowNodeResumeState}
	 */
	private function suspend(array $items, array $config): array {
		$state = (new FlowResumeState())->forNode(nodeId: 'approval');
		try {
			$this->node->execute(new FlowNodeCall(items: $items, config: array_merge(['question' => 'Close the vote?'], $config), context: [FlowNodeResumeState::CONTEXT_KEY => $state]));
		} catch (FlowSuspension $suspension) {
			return [$suspension, $state];
		}

		self::fail('the node must suspend while nothing answered');
	}//end suspend()

	public function testThePaletteEntry(): void {
		self::assertSame('openregister.await-signal', $this->node->getId());
		self::assertSame('Wait for an answer', $this->node->getDisplayName());
		self::assertStringContainsString('another system reports back', $this->node->getDescription());
		self::assertSame('/core/img/actions/confirm.svg', $this->node->getIcon());
		self::assertSame('receiveTask', $this->node->getKind());
		self::assertSame('human', $this->node->getCategory());
		self::assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		self::assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		self::assertFalse($this->node->isAvailableForScope(7));
	}//end testThePaletteEntry()

	public function testTheKeyIsRenderedFromTheFirstArrayEntrySkippingScalars(): void {
		[, $slot] = $this->suspend(
			['not-an-item', FlowItems::item(json: ['proposalId' => '42']), FlowItems::item(json: ['proposalId' => '99'])],
			['correlationKey' => ' vote:{{ proposalId }} ']
		);

		self::assertSame('vote:42', $slot->get(key: 'correlationKey'));
	}//end testTheKeyIsRenderedFromTheFirstArrayEntrySkippingScalars()

	public function testABareArrayEntryIsRenderedAsItsOwnPayload(): void {
		[, $slot] = $this->suspend([['proposalId' => '7']], ['correlationKey' => 'vote:{{ proposalId }}']);

		self::assertSame('vote:7', $slot->get(key: 'correlationKey'));
	}//end testABareArrayEntryIsRenderedAsItsOwnPayload()

	public function testAnEntryWhosePayloadIsNotAnArrayRendersAgainstNothing(): void {
		[, $slot] = $this->suspend([[FlowItems::JSON => 'scalar']], ['correlationKey' => 'vote:{{ proposalId }}']);

		self::assertSame('vote:', $slot->get(key: 'correlationKey'));
	}//end testAnEntryWhosePayloadIsNotAnArrayRendersAgainstNothing()

	public function testTheHeartbeatIsNeverSoonerThanFiveMinutes(): void {
		$before = time();
		[$suspension] = $this->suspend([FlowItems::item(json: [])], ['heartbeatMinutes' => 0]);
		$after = time();

		self::assertGreaterThanOrEqual($before + 300, $suspension->getResumeAt()->getTimestamp());
		self::assertLessThanOrEqual($after + 300, $suspension->getResumeAt()->getTimestamp());
	}//end testTheHeartbeatIsNeverSoonerThanFiveMinutes()

	public function testAnAnswerLeavesANonItemEntryAsItIs(): void {
		$out = $this->node->execute(new FlowNodeCall(
			items: ['marker', FlowItems::item(json: ['id' => 1])],
			config: ['question' => 'Close the vote?'],
			context: [FlowRunServiceInterface::SIGNAL_CONTEXT_KEY => ['decision' => 'approve']]
		));

		self::assertSame('marker', $out[0]);
		self::assertSame(['decision' => 'approve'], $out[1][FlowItems::JSON]['signal']);
	}//end testAnAnswerLeavesANonItemEntryAsItIs()
}//end class
