<?php

/**
 * The wait step: its metadata, the config it refuses, and its run, which
 * suspends the flow until a moment (absolute, a number of seconds, or a
 * relative duration), records that moment on the node's resume slot, and lets
 * the items through when resuming, when there are none, or when no moment
 * can be read.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * WaitNode.
 */
class WaitNodeBehaviourTest extends TestCase {

	private function node(): WaitNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/'.$app.'/img/'.$image);
		return new WaitNode($l10n, $urls);
	}//end node()

	public function testItDescribesItself(): void {
		$node = $this->node();

		$this->assertSame(
			['openregister.wait', 'Wait', 'Pause the flow for a while, then carry on where it left off.', '/core/img/actions/history.svg', ['for', 'until'], 'event'],
			[$node->getId(), $node->getDisplayName(), $node->getDescription(), $node->getIcon(), $node->configKeys(), $node->getKind()]
		);
	}//end testItDescribesItself()

	public function testAConfigWithoutDurationOrMomentIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('A wait needs a duration or a moment to wait until.');

		$this->node()->validateConfig(['for' => '  ', 'until' => '']);
	}//end testAConfigWithoutDurationOrMomentIsRefused()

	public function testAnUnreadableMomentIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('That is not a time this flow can wait until.');

		$this->node()->validateConfig(['until' => 'the twelfth of never']);
	}//end testAnUnreadableMomentIsRefused()

	public function testAnUnreadableDurationIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node()->validateConfig(['for' => 'a while']);
	}//end testAnUnreadableDurationIsRefused()

	public function testReadableConfigsPass(): void {
		$node = $this->node();

		$node->validateConfig(['until' => '2030-01-01T00:00:00+00:00']);
		$node->validateConfig(['for' => '3600']);
		$node->validateConfig(['for' => '+2 days']);
		$node->validateConfig(['for' => '1 hour']);

		$this->expectException(UnexpectedValueException::class);
		$node->validateConfig(['for' => '']);
	}//end testReadableConfigsPass()

	public function testItSuspendsUntilTheMomentAndRecordsItOnTheResumeSlot(): void {
		$state = new FlowResumeState();
		$context = ['resume' => $state->forNode('wait-1')];

		try {
			$this->node()->execute(new FlowNodeCall(items: [['id' => 1]], config: ['until' => '2030-01-02T03:04:05+00:00', 'for' => '60'], context: $context));
			$this->fail('The wait did not suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertSame(1893553445, $suspension->getResumeAt()?->getTimestamp());
			$this->assertStringStartsWith('waiting until ', $suspension->getMessage());
		}

		$this->assertSame(['wait-1' => ['waitingUntil' => $suspension->getResumeAt()->format('c')]], $state->all());
	}//end testItSuspendsUntilTheMomentAndRecordsItOnTheResumeSlot()

	public function testADurationInSecondsOrWordsSuspendsThatFarAhead(): void {
		foreach (['90' => 90, '+2 hours' => 7200, '2 hours' => 7200] as $for => $seconds) {
			$before = time();
			try {
				$this->node()->execute(new FlowNodeCall(items: [['id' => 1]], config: ['for' => (string)$for], context: []));
				$this->fail('The wait did not suspend.');
			} catch (FlowSuspension $suspension) {
				$at = $suspension->getResumeAt()?->getTimestamp();
				$this->assertGreaterThanOrEqual($before + $seconds, $at);
				$this->assertLessThanOrEqual(time() + $seconds, $at);
			}
		}
	}//end testADurationInSecondsOrWordsSuspendsThatFarAhead()

	public function testAResumingRunLetsTheItemsThrough(): void {
		$items = [['id' => 1]];
		$resuming = (new FlowResumeState(['wait-1' => ['waitingUntil' => 'x']]))->forNode('wait-1');

		$this->assertSame($items, $this->node()->execute(new FlowNodeCall(items: $items, config: ['for' => '60'], context: ['resume' => $resuming])));
		$this->assertSame($items, $this->node()->execute(new FlowNodeCall(items: $items, config: ['for' => '60'], context: ['resuming' => true])));
	}//end testAResumingRunLetsTheItemsThrough()

	public function testNoItemsOrNoReadableMomentLetsTheRunContinue(): void {
		$this->assertSame([], $this->node()->execute(new FlowNodeCall(items: [], config: ['for' => '60'], context: [])));
		$this->assertSame([['id' => 1]], $this->node()->execute(new FlowNodeCall(items: [['id' => 1]], config: ['until' => 'never ever'], context: [])));
		$this->assertSame([['id' => 1]], $this->node()->execute(new FlowNodeCall(items: [['id' => 1]], config: [], context: ['resuming' => 'yes'])));
	}//end testNoItemsOrNoReadableMomentLetsTheRunContinue()

	public function testANonResumingSlotOrAnAbsentSlotStillSuspends(): void {
		$this->expectException(FlowSuspension::class);

		$this->node()->execute(new FlowNodeCall(items: [['id' => 1]], config: ['for' => '5'], context: ['resume' => (new FlowResumeState())->forNode('wait-1'), 'resuming' => false]));
	}//end testANonResumingSlotOrAnAbsentSlotStillSuspends()
}//end class
