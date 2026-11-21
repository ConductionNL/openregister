<?php

/**
 * The plan-item, decision and timer events carry Kernel read contracts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Kernel\Contracts\CaseItemInterface;
use OCA\OpenRegister\Kernel\Contracts\DecisionInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerInterface;
use OCA\OpenRegister\Listener\CaseItemTimerListener;
use OCA\OpenRegister\Service\Case\CasePlanItemTimersInterface;
use OCA\OpenRegister\Service\Case\CasePlanServiceInterface;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use PHPUnit\Framework\TestCase;

/**
 * Pins that each event accepts and returns its Kernel contract, and that the
 * entity rows implement those contracts, so the events name no Processes class.
 *
 * @covers \OCA\OpenRegister\Event\CaseItemTransitionedEvent
 * @covers \OCA\OpenRegister\Event\DecisionChangeRequestedEvent
 * @covers \OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent
 * @covers \OCA\OpenRegister\Event\FlowTimerFiredEvent
 * @covers \OCA\OpenRegister\Listener\CaseItemTimerListener
 */
final class EventPayloadContractsTest extends TestCase {

	/**
	 * The entity rows implement the Kernel read contracts.
	 *
	 * @return void
	 */
	public function testTheRowsImplementTheKernelContracts(): void {
		self::assertInstanceOf(CaseItemInterface::class, new CaseItem());
		self::assertInstanceOf(DecisionInterface::class, new Decision());
		self::assertInstanceOf(FlowTimerInterface::class, new FlowTimer());
	}//end testTheRowsImplementTheKernelContracts()

	/**
	 * A terminal item names its catalog trigger; a non-terminal or stateless one names none.
	 *
	 * @return void
	 */
	public function testTheCatalogTriggerFollowsTheTerminalStates(): void {
		foreach ([CaseItem::STATE_COMPLETED, CaseItem::STATE_TERMINATED, CaseItem::STATE_DISABLED] as $state) {
			$item = new CaseItem();
			$item->setState($state);
			$event = new CaseItemTransitionedEvent(item: $item, fromState: CaseItem::STATE_ACTIVE);

			self::assertSame('case.item.' . $state, $event->getCatalogTrigger());
			self::assertSame($item, $event->getItem());
		}

		foreach ([CaseItem::STATE_AVAILABLE, CaseItem::STATE_ENABLED, CaseItem::STATE_ACTIVE] as $state) {
			$item = new CaseItem();
			$item->setState($state);

			self::assertNull((new CaseItemTransitionedEvent(item: $item, fromState: ''))->getCatalogTrigger());
		}

		self::assertNull((new CaseItemTransitionedEvent(item: new CaseItem(), fromState: ''))->getCatalogTrigger());
	}//end testTheCatalogTriggerFollowsTheTerminalStates()

	/**
	 * The event takes any implementation of the contract.
	 *
	 * @return void
	 */
	public function testTheItemEventCarriesAnyContractImplementation(): void {
		$item = new class implements CaseItemInterface {
			/**
			 * The state the item is in.
			 *
			 * @return string The state.
			 */
			public function getState(): string {
				return 'completed';
			}//end getState()

			/**
			 * Terminal, like a completed row.
			 *
			 * @return boolean Always true.
			 */
			public function isInTerminalState(): bool {
				return true;
			}//end isInTerminalState()

			/**
			 * Serialise.
			 *
			 * @return array<string, mixed> Nothing.
			 */
			public function jsonSerialize(): array {
				return [];
			}//end jsonSerialize()
		};

		$event = new CaseItemTransitionedEvent(item: $item, fromState: 'active');

		self::assertSame($item, $event->getItem());
		self::assertSame('active', $event->getFromState());
		self::assertSame('case.item.completed', $event->getCatalogTrigger());
	}//end testTheItemEventCarriesAnyContractImplementation()

	/**
	 * Both decision events carry the decision contract unchanged.
	 *
	 * @return void
	 */
	public function testTheDecisionEventsCarryTheContract(): void {
		$decision = $this->createMock(DecisionInterface::class);
		$policy   = ReviewPolicy::fromDeclaration(null);
		$change   = new DecisionChangeRequestedEvent(decision: $decision, actorId: 'alice', policy: $policy, subject: [], allowed: false);
		$view     = new DecisionVisibilityRequestedEvent(decision: $decision, actorId: 'bob', policy: $policy, subject: []);

		self::assertSame($decision, $change->getDecision());
		self::assertSame($decision, $view->getDecision());
		self::assertNull((new DecisionVisibilityRequestedEvent(decision: null, actorId: 'bob', policy: $policy, subject: []))->getDecision());
	}//end testTheDecisionEventsCarryTheContract()

	/**
	 * The timer event carries the timer contract unchanged.
	 *
	 * @return void
	 */
	public function testTheTimerEventCarriesTheContract(): void {
		$timer = $this->createMock(FlowTimerInterface::class);
		$event = new FlowTimerFiredEvent(timer: $timer, firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'remind', rungKey: 'r1'));

		self::assertSame($timer, $event->getTimer());
		self::assertSame('rung', $event->getKind());
		self::assertSame('r1', $event->getRungKey());
	}//end testTheTimerEventCarriesTheContract()

	/**
	 * The timer listener arms only on the plan engine's own CaseItem row.
	 *
	 * @return void
	 */
	public function testTheTimerListenerLeavesAForeignItemAlone(): void {
		$timers = $this->createMock(CasePlanItemTimersInterface::class);
		$timers->expects(self::never())->method('onEntered');
		$timers->expects(self::never())->method('onTerminal');
		$listener = new CaseItemTimerListener(timers: $timers, plans: $this->createMock(CasePlanServiceInterface::class));

		$listener->handle(new CaseItemTransitionedEvent(item: $this->createMock(CaseItemInterface::class), fromState: 'enabled'));
	}//end testTheTimerListenerLeavesAForeignItemAlone()
}//end class
