<?php

/**
 * Who may answer, when the step said WHAT KIND of principal it asked.
 *
 * 🔴 THE TWO READINGS ARE DELIBERATELY DIFFERENT, and that is the whole point
 * of this file. A stored bare string keeps its old, wider meaning — uid OR
 * group — because every flow on every instance was authored against exactly
 * that, and narrowing it would silently stop authorising the groups the fleet's
 * flows name by bare string. A TYPED reference means what it says.
 *
 * Which of the two an old string meant is a question for a person, not for the
 * guard: the repair reports the ambiguous ones and rewrites only what resolves
 * one way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A resolver whose roster the test can change between answers.
 */
class RosterResolver implements IPrincipalResolver {

	/**
	 * Who currently holds each id.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $holders = [];

	/**
	 * Constructor.
	 *
	 * @param string $type The type it answers for.
	 */
	public function __construct(private readonly string $type = 'position') {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string The type.
	 */
	public function type(): string {
		return $this->type;
	}//end type()

	/**
	 * Who holds it now.
	 *
	 * @param string $id The id.
	 *
	 * @return array<int, string> The uids.
	 */
	public function resolve(string $id): array {
		return ($this->holders[$id] ?? []);
	}//end resolve()
}//end class

/**
 * The typed half of {@see FlowRunAssignee}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunAssignee
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 */
final class FlowRunAssigneeTypedTest extends TestCase {

	/**
	 * A run whose one asking node recorded this assignee.
	 *
	 * @param mixed $assignee What the node wrote.
	 *
	 * @return FlowRun The run.
	 */
	private function runAssignedTo(mixed $assignee): FlowRun {
		$run = new FlowRun();
		$run->setContext(
			[
				FlowResumeState::CONTEXT_KEY => [
					'ask' => ['askedAt' => '2026-03-01T10:00:00+00:00', 'assignee' => $assignee],
				],
			]
		);

		return $run;
	}//end runAssignedTo()

	/**
	 * A registry holding one resolver.
	 *
	 * @param IPrincipalResolver $resolver The resolver.
	 *
	 * @return PrincipalResolverRegistry The registry.
	 */
	private function registryOf(IPrincipalResolver $resolver): PrincipalResolverRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($resolver): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === true) {
					$event->registerResolver($resolver);
				}
			}
		);

		return new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));
	}//end registryOf()

	/**
	 * 🔴 A POSITION AUTHORISES WHOEVER HOLDS IT, AND RE-READS EVERY TIME.
	 *
	 * The scenario the whole design exists for: the task was raised in March
	 * against the chair of the committee. In June somebody else chairs it. The
	 * new chair may answer; the old one may not, even though they are named in
	 * the task's own record of who was asked.
	 *
	 * @return void
	 */
	public function testAPositionAuthorisesWhoeverHoldsItNow(): void {
		$positions = new RosterResolver();
		$positions->holders['chair'] = ['alice'];

		$assignee = new FlowRunAssignee(null, $this->registryOf($positions));
		$run = $this->runAssignedTo(['type' => 'position', 'id' => 'chair']);

		$this->assertTrue($assignee->mayAnswer(run: $run, uid: 'alice'));
		$this->assertFalse($assignee->mayAnswer(run: $run, uid: 'bob'));

		// June: bob takes the chair.
		$positions->holders['chair'] = ['bob'];

		$this->assertTrue($assignee->mayAnswer(run: $run, uid: 'bob'), 'the new holder must be able to answer');
		$this->assertFalse(
			$assignee->mayAnswer(run: $run, uid: 'alice'),
			'the former holder must not, however the task records who was asked'
		);
	}//end testAPositionAuthorisesWhoeverHoldsItNow()

	/**
	 * 🔴 A COINCIDENTAL GROUP NAME NO LONGER AUTHORISES A TYPED `user`.
	 *
	 * The ambiguity this change exists to remove. A bare `"bezwaar"` authorises
	 * the user AND the group; `{type: 'user', id: 'bezwaar'}` authorises only
	 * the user, even on an instance where a group of that name exists and the
	 * actor is in it.
	 *
	 * @return void
	 */
	public function testATypedUserIsNotSatisfiedByAGroupOfTheSameName(): void {
		$groups = $this->createMock(IGroupManager::class);
		// The actor IS in a group called `bezwaar` — the coincidence.
		$groups->method('isInGroup')->willReturn(true);

		$users = new RosterResolver('user');
		$users->holders['bezwaar'] = ['bezwaar'];

		$assignee = new FlowRunAssignee($groups, $this->registryOf($users));

		$this->assertFalse(
			$assignee->mayAnswer(run: $this->runAssignedTo(['type' => 'user', 'id' => 'bezwaar']), uid: 'carol'),
			'a typed user reference must not be satisfied by group membership'
		);

		// And the SAME instance, the SAME actor, against the legacy string:
		// still allowed, because that is what every stored flow means.
		$this->assertTrue(
			$assignee->mayAnswer(run: $this->runAssignedTo('bezwaar'), uid: 'carol'),
			'a bare string keeps its old, wider meaning'
		);
	}//end testATypedUserIsNotSatisfiedByAGroupOfTheSameName()

	/**
	 * An unassigned step stays open, in either spelling.
	 *
	 * The one branch that must not be tightened without changing the spec.
	 *
	 * @return void
	 */
	public function testAnUnassignedStepIsStillOpen(): void {
		$assignee = new FlowRunAssignee(null, $this->registryOf(new RosterResolver()));

		$this->assertTrue($assignee->mayAnswer(run: $this->runAssignedTo(''), uid: 'anyone'));
		$this->assertTrue($assignee->mayAnswer(run: $this->runAssignedTo([]), uid: 'anyone'));
		$this->assertTrue($assignee->mayAnswer(run: $this->runAssignedTo(null), uid: 'anyone'));
		// Even with no session at all: unassigned is deliberately open.
		$this->assertTrue($assignee->mayAnswer(run: $this->runAssignedTo(''), uid: null));
	}//end testAnUnassignedStepIsStillOpen()

	/**
	 * An assigned step is never anonymous.
	 *
	 * @return void
	 */
	public function testAnAssignedStepRefusesAnAnonymousAnswer(): void {
		$positions = new RosterResolver();
		$positions->holders['chair'] = ['alice'];
		$assignee = new FlowRunAssignee(null, $this->registryOf($positions));

		$this->assertFalse(
			$assignee->mayAnswer(run: $this->runAssignedTo(['type' => 'position', 'id' => 'chair']), uid: null)
		);
	}//end testAnAssignedStepRefusesAnAnonymousAnswer()

	/**
	 * A list of references authorises the union of what they mean.
	 *
	 * @return void
	 */
	public function testAListAuthorisesTheUnion(): void {
		$positions = new RosterResolver();
		$positions->holders['chair'] = ['alice'];
		$positions->holders['clerk'] = ['bob'];

		$assignee = new FlowRunAssignee(null, $this->registryOf($positions));
		$run = $this->runAssignedTo(
			[
				['type' => 'position', 'id' => 'chair'],
				['type' => 'position', 'id' => 'clerk'],
			]
		);

		$this->assertTrue($assignee->mayAnswer(run: $run, uid: 'alice'));
		$this->assertTrue($assignee->mayAnswer(run: $run, uid: 'bob'));
		$this->assertFalse($assignee->mayAnswer(run: $run, uid: 'carol'));
	}//end testAListAuthorisesTheUnion()

	/**
	 * 🔴 WITHOUT A REGISTRY, A TYPED ASSIGNMENT REFUSES.
	 *
	 * The same fail-closed direction as the absent group manager, and asserted
	 * for the same reason: its absence must be visible in a test rather than
	 * inferred from a suite that happens to pass.
	 *
	 * @return void
	 */
	public function testATypedAssignmentWithoutAResolverRefuses(): void {
		$assignee = new FlowRunAssignee();

		$this->assertFalse(
			$assignee->mayAnswer(run: $this->runAssignedTo(['type' => 'position', 'id' => 'chair']), uid: 'alice')
		);
	}//end testATypedAssignmentWithoutAResolverRefuses()

	/**
	 * A type nothing on this instance resolves refuses the answer.
	 *
	 * A flow authored where decidiq is installed, opened where it is not.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTypeRefuses(): void {
		$assignee = new FlowRunAssignee(null, $this->registryOf(new RosterResolver('function')));

		$this->assertFalse(
			$assignee->mayAnswer(run: $this->runAssignedTo(['type' => 'position', 'id' => 'chair']), uid: 'alice')
		);
	}//end testAnUnresolvableTypeRefuses()

	/**
	 * `recordedFor()` still answers with a string for callers that want one.
	 *
	 * @return void
	 */
	public function testRecordedForStillRendersOneString(): void {
		$assignee = new FlowRunAssignee(null, $this->registryOf(new RosterResolver()));

		$this->assertSame('bob', $assignee->recordedFor(run: $this->runAssignedTo('bob')));
		$this->assertSame(
			'chair',
			$assignee->recordedFor(run: $this->runAssignedTo(['type' => 'position', 'id' => 'chair']))
		);
		$this->assertSame('', $assignee->recordedFor(run: $this->runAssignedTo('')));
	}//end testRecordedForStillRendersOneString()
}//end class
