<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Listener\CaseItemTimerListener}.
 *
 * Pins the exact timer and plan calls for every item state a transition can
 * land in, for a fired timer that does and does not name an item, and for a
 * foreign event. Written against the code before its refactor and left
 * unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Listener\CaseItemTimerListener;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class CaseItemTimerListenerGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Dispatch one event and return the calls it caused.
	 *
	 * @param Event       $event    The event.
	 * @param string|null $itemUuid What itemToTerminate() answers.
	 *
	 * @return list<string>
	 */
	private function dispatch(Event $event, ?string $itemUuid = null): array {
		$timers = $this->createMock(CasePlanItemTimers::class);
		$timers->method('onEntered')->willReturnCallback(
			function (CaseItem $item, \OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct $act): int {
				$this->calls[] = 'onEntered:' . $item->getState() . ':' . var_export($act->actor, true);
				return 1;
			}
		);
		$timers->method('onTerminal')->willReturnCallback(
			function (CaseItem $item): int {
				$this->calls[] = 'onTerminal:' . $item->getState();
				return 1;
			}
		);
		$timers->method('itemToTerminate')->willReturnCallback(
			function () use ($itemUuid): ?string {
				$this->calls[] = 'itemToTerminate';
				return $itemUuid;
			}
		);
		$plans = $this->createMock(CasePlanService::class);
		$plans->method('onTimerExpired')->willReturnCallback(
			function (string $item, string $timer): bool {
				$this->calls[] = 'onTimerExpired:' . $item . ':' . $timer;
				return true;
			}
		);

		(new CaseItemTimerListener($timers, $plans))->handle($event);

		return $this->calls;
	}//end dispatch()

	/**
	 * A transition into the given state.
	 *
	 * @param string $state The state the item now holds.
	 *
	 * @return CaseItemTransitionedEvent
	 */
	private static function transition(string $state): CaseItemTransitionedEvent {
		$item = new CaseItem();
		$item->setState($state);

		return new CaseItemTransitionedEvent($item, CaseItem::STATE_ENABLED);
	}//end transition()

	/**
	 * A fired timer carrying the given uuid.
	 *
	 * @param string|null $uuid The timer uuid.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private static function fired(?string $uuid): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);

		return new FlowTimerFiredEvent(timer: $timer, firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_EXPIRY, transition: 'expiry'));
	}//end fired()

	/**
	 * @return void
	 */
	public function testActiveItemArmsWithNullActor(): void {
		$this->assertSame(['onEntered:active:NULL'], $this->dispatch(self::transition(CaseItem::STATE_ACTIVE)));
	}//end testActiveItemArmsWithNullActor()

	/**
	 * @return void
	 */
	public function testEachTerminalStateDisarms(): void {
		$this->assertSame(['onTerminal:completed'], $this->dispatch(self::transition(CaseItem::STATE_COMPLETED)));
		$this->calls = [];
		$this->assertSame(['onTerminal:terminated'], $this->dispatch(self::transition(CaseItem::STATE_TERMINATED)));
		$this->calls = [];
		$this->assertSame(['onTerminal:disabled'], $this->dispatch(self::transition(CaseItem::STATE_DISABLED)));
	}//end testEachTerminalStateDisarms()

	/**
	 * @return void
	 */
	public function testNonActiveNonTerminalStatesDoNothing(): void {
		$this->assertSame([], $this->dispatch(self::transition(CaseItem::STATE_ENABLED)));
		$this->assertSame([], $this->dispatch(self::transition(CaseItem::STATE_AVAILABLE)));
	}//end testNonActiveNonTerminalStatesDoNothing()

	/**
	 * @return void
	 */
	public function testFiredTimerNamingAnItemExpiresIt(): void {
		$this->assertSame(
			['itemToTerminate', 'onTimerExpired:item-1:timer-9'],
			$this->dispatch(self::fired('timer-9'), 'item-1')
		);
	}//end testFiredTimerNamingAnItemExpiresIt()

	/**
	 * @return void
	 */
	public function testNullTimerUuidIsCastToEmptyString(): void {
		$this->assertSame(['itemToTerminate', 'onTimerExpired:item-1:'], $this->dispatch(self::fired(null), 'item-1'));
	}//end testNullTimerUuidIsCastToEmptyString()

	/**
	 * @return void
	 */
	public function testFiredTimerNamingNoItemDoesNothingMore(): void {
		$this->assertSame(['itemToTerminate'], $this->dispatch(self::fired('timer-9'), null));
	}//end testFiredTimerNamingNoItemDoesNothingMore()

	/**
	 * @return void
	 */
	public function testForeignEventDoesNothing(): void {
		$this->assertSame([], $this->dispatch(new Event()));
	}//end testForeignEventDoesNothing()
}//end class
