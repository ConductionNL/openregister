<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use DateTime;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCA\OpenRegister\Service\Decision\DecisionOwnedException;
use OCA\OpenRegister\Service\Decision\DecisionOwnership;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The ownership rule on a decision object (REQ-ROD-001, REQ-DEC-002).
 *
 * @covers \OCA\OpenRegister\Service\Decision\DecisionOwnership
 * @covers \OCA\OpenRegister\Event\DecisionChangeRequestedEvent
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Db\Decision
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 * @uses \OCA\OpenRegister\Service\Decision\DecisionOwnedException
 */
class DecisionOwnershipTest extends TestCase {

	/**
	 * Group memberships by user.
	 *
	 * @var array<string, array<string>>
	 */
	private array $groups = ['coord' => ['woo-coordinator'], 'root' => ['admin']];

	/**
	 * What a listener does with the question, if anything.
	 *
	 * @var (callable(DecisionChangeRequestedEvent): void)|null
	 */
	private $listener = null;

	/**
	 * The events dispatched.
	 *
	 * @var array<int, DecisionChangeRequestedEvent>
	 */
	private array $dispatched = [];

	/**
	 * The rule under test, over a group manager and dispatcher that read the fields above.
	 *
	 * @return DecisionOwnership The rule.
	 */
	private function ownership(): DecisionOwnership {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array('admin', ($this->groups[$uid] ?? []), true));
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($gid, ($this->groups[$uid] ?? []), true));

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (DecisionChangeRequestedEvent $event): void {
				$this->dispatched[] = $event;
				if ($this->listener !== null) {
					($this->listener)($event);
				}
			}
		);

		return new DecisionOwnership(groupManager: $groups, eventDispatcher: $dispatcher, logger: $this->createMock(LoggerInterface::class));
	}//end ownership()

	/**
	 * A decision by j.devries.
	 *
	 * @return Decision The decision.
	 */
	private function decision(): Decision {
		$decision = new Decision();
		$decision->setUuid('d-1');
		$decision->setVerdict('redact');
		$decision->setDecidedBy('j.devries');
		$decision->setDecidedAt(new DateTime('2026-10-11T09:30:00+00:00'));

		return $decision;
	}//end decision()

	/**
	 * The decider-owned policy with the coordinator group.
	 *
	 * @return ReviewPolicy The policy.
	 */
	private function decider(): ReviewPolicy {
		return ReviewPolicy::fromDeclaration(declaration: ['ownership' => 'decider', 'supervisors' => ['woo-coordinator']]);
	}//end decider()

	/**
	 * A second reviewer is refused, told who decided and when, not the verdict.
	 *
	 * @return void
	 */
	public function testASecondReviewerIsRefused(): void {
		$refusal = $this->ownership()->refusal(decision: $this->decision(), actorId: 'p.bakker', policy: $this->decider(), subject: ['subjectType' => 'entity-relation', 'subjectId' => '7']);

		self::assertSame(
			['error' => 'decision-owned', 'decision' => 'd-1', 'decidedBy' => 'j.devries', 'decidedAt' => '2026-10-11T09:30:00+00:00'],
			$refusal
		);
		self::assertCount(1, $this->dispatched);
		self::assertSame(['subjectType' => 'entity-relation', 'subjectId' => '7'], $this->dispatched[0]->getSubject());
	}//end testASecondReviewerIsRefused()

	/**
	 * Under hideOthers the refusal names neither who decided nor when (REQ-ROD-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function testUnderHideOthersTheRefusalNamesNoDecider(): void {
		$policy  = ReviewPolicy::fromDeclaration(declaration: ['ownership' => 'decider', 'supervisors' => ['woo-coordinator'], 'hideOthers' => true]);
		$refusal = $this->ownership()->refusal(decision: $this->decision(), actorId: 'p.bakker', policy: $policy);

		self::assertSame(['error' => 'decision-owned', 'decision' => 'd-1'], $refusal);

		$exception = new DecisionOwnedException(refusal: (array)$refusal);
		self::assertStringNotContainsString('j.devries', $exception->getMessage());
		self::assertStringNotContainsString('j.devries', (string)json_encode($exception->getRefusal()));
	}//end testUnderHideOthersTheRefusalNamesNoDecider()

	/**
	 * The decider, a supervisor and an administrator may; open ownership lets anyone.
	 *
	 * @return void
	 */
	public function testTheDeciderASupervisorAnAdministratorAndOpenOwnershipMay(): void {
		$ownership = $this->ownership();

		self::assertNull($ownership->refusal(decision: $this->decision(), actorId: 'j.devries', policy: $this->decider()));
		self::assertNull($ownership->refusal(decision: $this->decision(), actorId: 'coord', policy: $this->decider()));
		self::assertNull($ownership->refusal(decision: $this->decision(), actorId: 'root', policy: $this->decider()));
		self::assertNull($ownership->refusal(decision: $this->decision(), actorId: 'p.bakker', policy: ReviewPolicy::fromDeclaration(declaration: null)));

		// The supervisor's answer went through the event; the other three never asked.
		self::assertCount(1, $this->dispatched);
		self::assertTrue($this->dispatched[0]->isAllowed());
	}//end testTheDeciderASupervisorAnAdministratorAndOpenOwnershipMay()

	/**
	 * The app that owns the subject may allow someone the policy does not name.
	 *
	 * @return void
	 */
	public function testAListenerMayAllow(): void {
		$this->listener = static function (DecisionChangeRequestedEvent $event): void {
			if ($event->getActorId() === 'case-supervisor') {
				$event->allow();
			}
		};

		self::assertNull($this->ownership()->refusal(decision: $this->decision(), actorId: 'case-supervisor', policy: $this->decider()));
	}//end testAListenerMayAllow()

	/**
	 * The app that owns the subject may refuse a supervisor the policy names.
	 *
	 * @return void
	 */
	public function testAListenerMayRefuse(): void {
		$this->listener = static function (DecisionChangeRequestedEvent $event): void {
			$event->refuse(reason: 'not this case');
		};

		$refusal = $this->ownership()->refusal(decision: $this->decision(), actorId: 'coord', policy: $this->decider());

		self::assertSame('decision-owned', $refusal['error'] ?? null);
		self::assertSame('not this case', $this->dispatched[0]->getReason());
	}//end testAListenerMayRefuse()

	/**
	 * A listener that fails refuses: the question fails closed.
	 *
	 * @return void
	 */
	public function testAFailingListenerRefuses(): void {
		$this->listener = static function (): void {
			throw new RuntimeException('listener down');
		};

		$refusal = $this->ownership()->refusal(decision: $this->decision(), actorId: 'coord', policy: $this->decider());

		self::assertSame('decision-owned', $refusal['error'] ?? null);
	}//end testAFailingListenerRefuses()

	/**
	 * A refusal is sticky: a later allow() cannot undo it, so listener order decides nothing.
	 *
	 * @return void
	 */
	public function testARefusalIsStickyWhateverTheListenerOrder(): void {
		$event = new DecisionChangeRequestedEvent(decision: $this->decision(), actorId: 'x', policy: $this->decider(), subject: [], allowed: false);
		$event->refuse(reason: 'case closed');
		$event->allow();

		self::assertFalse($event->isAllowed());
		self::assertSame('case closed', $event->getReason());

		// Allow first, then refuse: the same answer.
		$other = new DecisionChangeRequestedEvent(decision: $this->decision(), actorId: 'x', policy: $this->decider(), subject: [], allowed: false);
		$other->allow();
		self::assertTrue($other->isAllowed());
		$other->refuse(reason: 'case closed');
		self::assertFalse($other->isAllowed());

		self::assertSame('x', $event->getActorId());
		self::assertSame('d-1', $event->getDecision()->getUuid());
		self::assertTrue($event->getPolicy()->isDeciderOwned());
	}//end testARefusalIsStickyWhateverTheListenerOrder()
}//end class
