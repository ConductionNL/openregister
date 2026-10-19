<?php

/**
 * Tests for DecisionVisibility (REQ-ROD-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A blind second review: another reviewer's decision is listed but not shown.
 *
 * @covers \OCA\OpenRegister\Service\Decision\DecisionVisibility
 * @uses \OCA\OpenRegister\Db\Decision
 * @uses \OCA\OpenRegister\Db\EntityRelation
 * @uses \OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent
 * @uses \OCA\OpenRegister\Service\Decision\ReviewPolicy
 */
class HiddenDecisionTest extends TestCase {

	private const DECISION = 'dec00000-0000-4000-8000-000000000001';

	/**
	 * What a listener does with the question, if anything.
	 *
	 * @var (callable(DecisionVisibilityRequestedEvent): void)|null
	 */
	private $listener = null;

	/**
	 * The events dispatched.
	 *
	 * @var array<int, DecisionVisibilityRequestedEvent>
	 */
	private array $dispatched = [];

	/**
	 * The filter over a policy, one decision by j.devries, and group membership.
	 *
	 * @param array<string, mixed>|null $declaration The schema's x-openregister-review.
	 *
	 * @return DecisionVisibility
	 */
	private function visibility(?array $declaration=['ownership' => 'decider', 'supervisors' => ['woo-coordinator'], 'hideOthers' => true]): DecisionVisibility {
		$resolver = $this->createMock(ReviewPolicyResolver::class);
		$resolver->method('forRelation')->willReturn(
			['policy' => ReviewPolicy::fromDeclaration(declaration: $declaration), 'subject' => ['subjectType' => 'entity-relation', 'subjectId' => '12', 'objectUuid' => 'doc-1']]
		);

		$decision = new Decision();
		$decision->setUuid(self::DECISION);
		$decision->setDecidedBy('j.devries');
		$decisions = $this->createMock(DecisionService::class);
		$decisions->method('find')->willReturnCallback(
			static fn (?string $uuid): ?Decision => $uuid === self::DECISION ? $decision : null
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'coordinator' && $group === 'woo-coordinator'
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (DecisionVisibilityRequestedEvent $event): void {
				$this->dispatched[] = $event;
				if ($this->listener !== null) {
					($this->listener)($event);
				}
			}
		);

		return new DecisionVisibility(
			resolver: $resolver,
			decisions: $decisions,
			groupManager: $groups,
			eventDispatcher: $dispatcher,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end visibility()

	/**
	 * A decided relation: redacted on 5.1.2e by j.devries's decision.
	 *
	 * @param string|null $decisionUuid The decision it links.
	 *
	 * @return EntityRelation
	 */
	private function relation(?string $decisionUuid=self::DECISION): EntityRelation {
		$relation = new EntityRelation();
		$relation->setId(12);
		$relation->setEntityId(3);
		$relation->setPositionStart(10);
		$relation->setPositionEnd(20);
		$relation->setBases(['5.1.2e']);
		$relation->setSkipAnonymization(false);
		$relation->setAnonymized(true);
		$relation->setAnonymizedValue('[REDACTED]');
		$relation->setDecisionUuid($decisionUuid);

		return $relation;
	}//end relation()

	/**
	 * p.bakker sees the relation, its type and position, but neither the decision, its ground nor its verdict.
	 *
	 * @return void
	 */
	public function testAnotherReviewersDecisionIsHidden(): void {
		$data = $this->visibility()->present(relation: $this->relation(), userId: 'p.bakker');

		self::assertSame(DecisionVisibility::HIDDEN, $data['decision']);
		self::assertNull($data['bases']);
		self::assertNull($data['skipAnonymization']);
		self::assertNull($data['anonymized'], 'an anonymise pass would otherwise show the verdict');
		self::assertNull($data['anonymizedValue']);
		self::assertSame(12, $data['id']);
		self::assertSame(10, $data['positionStart']);
		self::assertSame(20, $data['positionEnd']);
	}//end testAnotherReviewersDecisionIsHidden()

	/**
	 * A supervisor, an administrator and the decider see the decision.
	 *
	 * @return void
	 */
	public function testTheSupervisorSeesIt(): void {
		foreach (['coordinator', 'admin', 'j.devries'] as $userId) {
			$data = $this->visibility()->present(relation: $this->relation(), userId: $userId);

			self::assertSame(self::DECISION, $data['decision'], $userId);
			self::assertSame(['5.1.2e'], $data['bases'], $userId);
			self::assertFalse($data['skipAnonymization'], $userId);
			self::assertTrue($data['anonymized'], $userId);
			self::assertSame('[REDACTED]', $data['anonymizedValue'], $userId);
		}
	}//end testTheSupervisorSeesIt()

	/**
	 * Nothing hides without hideOthers, an undecided relation hides nothing, and nobody signed in sees nothing.
	 *
	 * @return void
	 */
	public function testOnlyADecidedRelationUnderHideOthersIsMasked(): void {
		$open = $this->visibility(declaration: ['ownership' => 'decider', 'supervisors' => [], 'hideOthers' => false]);
		self::assertSame(self::DECISION, $open->present(relation: $this->relation(), userId: 'p.bakker')['decision']);

		self::assertNull($this->visibility()->present(relation: $this->relation(decisionUuid: null), userId: 'p.bakker')['decision']);
		self::assertSame(DecisionVisibility::HIDDEN, $this->visibility()->present(relation: $this->relation(), userId: '')['decision']);
	}//end testOnlyADecidedRelationUnderHideOthersIsMasked()

	/**
	 * A decision whose record is gone is hidden: who took it is unknown.
	 *
	 * @return void
	 */
	public function testADecisionWithoutItsRecordIsHidden(): void {
		$data = $this->visibility()->present(relation: $this->relation(decisionUuid: 'dec-gone'), userId: 'p.bakker');

		self::assertSame(DecisionVisibility::HIDDEN, $data['decision']);
	}//end testADecisionWithoutItsRecordIsHidden()

	/**
	 * The embedded decision record of a PATCH answer is masked with the rest.
	 *
	 * @return void
	 */
	public function testAnEmbeddedDecisionRecordIsMasked(): void {
		$relation = $this->relation();
		$serialised = $relation->jsonSerialize();
		$serialised['decisionRecord'] = ['decidedBy' => 'j.devries', 'note' => 'name of a resident'];

		$data = $this->visibility()->present(relation: $relation, userId: 'p.bakker', serialised: $serialised);

		self::assertNull($data['decisionRecord']);
	}//end testAnEmbeddedDecisionRecordIsMasked()

	/**
	 * presentAll masks each relation on its own.
	 *
	 * @return void
	 */
	public function testPresentAllMasksEachRelation(): void {
		$data = $this->visibility()->presentAll(relations: [$this->relation(), $this->relation(decisionUuid: null)], userId: 'p.bakker');

		self::assertSame([DecisionVisibility::HIDDEN, null], array_column($data, 'decision'));
	}//end testPresentAllMasksEachRelation()

	/**
	 * The app that owns the subject may show a hidden decision, e.g. to a case-type supervisor (REQ-DEC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function testTheOwningAppMayShowAHiddenDecision(): void {
		$this->listener = static function (DecisionVisibilityRequestedEvent $event): void {
			if ($event->getActorId() === 'case-supervisor') {
				$event->allow();
			}
		};

		$data = $this->visibility()->present(relation: $this->relation(), userId: 'case-supervisor');
		self::assertSame(self::DECISION, $data['decision']);
		self::assertSame(['5.1.2e'], $data['bases']);

		self::assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		self::assertSame(self::DECISION, $event->getDecision()?->getUuid());
		self::assertSame('doc-1', $event->getSubject()['objectUuid'] ?? null);
		self::assertTrue($event->getPolicy()->hidesOthers());

		self::assertSame(DecisionVisibility::HIDDEN, $this->visibility()->present(relation: $this->relation(), userId: 'p.bakker')['decision']);
	}//end testTheOwningAppMayShowAHiddenDecision()

	/**
	 * A listener that throws leaves the decision hidden.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function testAFailingListenerKeepsItHidden(): void {
		$this->listener = static function (DecisionVisibilityRequestedEvent $event): void {
			$event->allow();
			throw new RuntimeException('case lookup failed');
		};

		$data = $this->visibility()->present(relation: $this->relation(), userId: 'case-supervisor');

		self::assertSame(DecisionVisibility::HIDDEN, $data['decision']);
		self::assertNull($data['bases']);
	}//end testAFailingListenerKeepsItHidden()

	/**
	 * The question is asked only when the decision would otherwise be hidden from someone signed in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-the-app-that-owns-the-subject-may-show-a-hidden-decision-req-dec-003
	 */
	public function testTheQuestionIsAskedOnlyWhenHidden(): void {
		$this->listener = static function (DecisionVisibilityRequestedEvent $event): void {
			$event->allow();
		};

		foreach (['coordinator', 'admin', 'j.devries'] as $userId) {
			$this->visibility()->present(relation: $this->relation(), userId: $userId);
		}

		$this->visibility()->present(relation: $this->relation(decisionUuid: null), userId: 'p.bakker');
		$this->visibility(declaration: ['ownership' => 'decider', 'hideOthers' => false])->present(relation: $this->relation(), userId: 'p.bakker');

		$anonymous = $this->visibility()->present(relation: $this->relation(), userId: '');
		self::assertSame(DecisionVisibility::HIDDEN, $anonymous['decision']);

		self::assertSame([], $this->dispatched);
	}//end testTheQuestionIsAskedOnlyWhenHidden()

	/**
	 * Every controller that serialises an entity relation goes through the filter.
	 *
	 * A controller that injects the relation mapper and serialises what it
	 * reads (`$r->jsonSerialize()`, `$relation->jsonSerialize()`,
	 * `$decided['relation']->jsonSerialize()`) must also name DecisionVisibility.
	 * The two known endpoints must be found, so a scan that matches nothing fails.
	 *
	 * @return void
	 */
	public function testEveryRelationEndpointMasks(): void {
		$serialisers = [];
		$unfiltered = [];
		foreach (glob(__DIR__ . '/../../../../lib/Controller/{,*/}*Controller.php', GLOB_BRACE) as $path) {
			$source = (string)file_get_contents($path);
			if (str_contains($source, 'EntityRelationMapper') === false
				|| preg_match('/(\$r|\$relation\w*|\[\'relation\'\])->jsonSerialize\(\)|decisionVisibility->present/', $source) !== 1
			) {
				continue;
			}

			$serialisers[] = basename($path);
			if (str_contains($source, 'DecisionVisibility') === false) {
				$unfiltered[] = basename($path);
			}
		}

		self::assertContains('GdprEntitiesController.php', $serialisers, 'the scan must find the known relation endpoint, or it proves nothing');
		self::assertContains('EntityRelationsController.php', $serialisers, 'the scan must find the known relation endpoint, or it proves nothing');
		self::assertSame([], $unfiltered, 'these controllers serialise entity relations without DecisionVisibility');
	}//end testEveryRelationEndpointMasks()
}//end class
