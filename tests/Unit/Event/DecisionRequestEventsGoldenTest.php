<?php

/**
 * Pins the two decision request events: what they carry and how a verdict sticks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Event\AbstractDecisionRequestEvent
 * @covers \OCA\OpenRegister\Event\DecisionChangeRequestedEvent
 * @covers \OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent
 * @uses   \OCA\OpenRegister\Db\Decision
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 */
class DecisionRequestEventsGoldenTest extends TestCase {
	private function change(bool $allowed): DecisionChangeRequestedEvent {
		return new DecisionChangeRequestedEvent(
			decision: new Decision(),
			actorId: 'alice',
			policy: ReviewPolicy::fromDeclaration(null),
			subject: ['file' => 1],
			allowed: $allowed
		);
	}//end change()

	/**
	 * Both events carry what they were built with, under named arguments, and are dispatchable events.
	 */
	public function testTheyCarryWhatTheyWereBuiltWith(): void {
		$decision = new Decision();
		$policy   = ReviewPolicy::fromDeclaration(null);
		$change   = new DecisionChangeRequestedEvent(decision: $decision, actorId: 'alice', policy: $policy, subject: ['a' => 1], allowed: true);
		$view     = new DecisionVisibilityRequestedEvent(decision: null, actorId: 'bob', policy: $policy, subject: ['b' => 2]);

		$this->assertInstanceOf(Event::class, $change);
		$this->assertInstanceOf(Event::class, $view);
		$this->assertSame($decision, $change->getDecision());
		$this->assertSame('alice', $change->getActorId());
		$this->assertSame($policy, $change->getPolicy());
		$this->assertSame(['a' => 1], $change->getSubject());
		$this->assertNull($view->getDecision());
		$this->assertSame('bob', $view->getActorId());
		$this->assertSame($policy, $view->getPolicy());
		$this->assertSame(['b' => 2], $view->getSubject());
	}//end testTheyCarryWhatTheyWereBuiltWith()

	/**
	 * A view stays closed until a listener allows it.
	 */
	public function testAViewIsClosedUntilAllowed(): void {
		$view = new DecisionVisibilityRequestedEvent(decision: null, actorId: 'bob', policy: ReviewPolicy::fromDeclaration(null), subject: []);

		$this->assertFalse($view->isAllowed());
		$view->allow();
		$this->assertTrue($view->isAllowed());
	}//end testAViewIsClosedUntilAllowed()

	/**
	 * A change starts from the given verdict, and allow() clears nothing but opens it.
	 */
	public function testAChangeStartsFromItsVerdict(): void {
		$this->assertTrue($this->change(allowed: true)->isAllowed());
		$closed = $this->change(allowed: false);
		$this->assertFalse($closed->isAllowed());
		$this->assertNull($closed->getReason());

		$closed->allow();
		$this->assertTrue($closed->isAllowed());
	}//end testAChangeStartsFromItsVerdict()

	/**
	 * A refusal sticks: a later allow() does not lift it, and the reason stays.
	 */
	public function testARefusalSticks(): void {
		$event = $this->change(allowed: true);

		$event->refuse('Not yours.');
		$event->allow();

		$this->assertFalse($event->isAllowed());
		$this->assertSame('Not yours.', $event->getReason());
	}//end testARefusalSticks()
}//end class
