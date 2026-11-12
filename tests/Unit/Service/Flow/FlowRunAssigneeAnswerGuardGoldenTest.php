<?php

/**
 * Characterisation tests for who may answer a step with a given recorded
 * assignee.
 *
 * Pins allowed AND refused outcomes of the private
 * `FlowRunAssignee::recordedMayAnswer()` (reached by reflection, so this
 * test does not depend on the public `mayAnswer()` signature): an unassigned
 * step is open to anybody, even anonymously; an assigned step refuses a
 * missing, empty or blank caller; a bare string admits the uid (trimmed on
 * both sides) or a member of that group, and refuses without a group
 * manager; a typed reference admits whoever it resolves to and refuses
 * without a resolver.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden tests for recordedMayAnswer().
 */
class FlowRunAssigneeAnswerGuardGoldenTest extends TestCase {

	/**
	 * Ask the private guard.
	 *
	 * @param FlowRunAssignee $assignee The guard.
	 * @param mixed           $recorded The recorded assignee.
	 * @param string|null     $uid      The answering user.
	 *
	 * @return bool
	 */
	private function may(FlowRunAssignee $assignee, mixed $recorded, ?string $uid): bool {
		return (new ReflectionMethod(FlowRunAssignee::class, 'recordedMayAnswer'))->invoke($assignee, $recorded, $uid);
	}//end may()

	/**
	 * Unassigned is open; an assigned step is never anonymous.
	 *
	 * @return void
	 */
	public function testUnassignedIsOpenAndAssignedIsNeverAnonymous(): void {
		$assignee = new FlowRunAssignee();

		foreach (['', '  ', [], [['type' => 'user', 'id' => '']]] as $unassigned) {
			$this->assertTrue($this->may($assignee, $unassigned, null), json_encode($unassigned));
		}

		foreach ([null, '', '   '] as $nobody) {
			$this->assertFalse($this->may($assignee, 'alice', $nobody), 'string, caller ' . json_encode($nobody));
			$this->assertFalse($this->may($assignee, ['type' => 'user', 'id' => 'alice'], $nobody), 'typed, caller ' . json_encode($nobody));
		}

		$everybody = $this->createMock(IGroupManager::class);
		$everybody->method('isInGroup')->willReturn(true);
		$registry = $this->createMock(PrincipalResolverRegistry::class);
		$registry->method('resolveAll')->willReturn(['']);
		$open = new FlowRunAssignee(groupManager: $everybody, principals: $registry);
		foreach (['', '   '] as $blank) {
			$this->assertFalse($this->may($open, 'bezwaar', $blank), 'a blank caller is refused before any group lookup');
			$this->assertFalse($this->may($open, ['type' => 'role', 'id' => 'x'], $blank), 'a blank caller is refused before any resolution');
		}
	}//end testUnassignedIsOpenAndAssignedIsNeverAnonymous()

	/**
	 * A bare string admits the uid or a member of that group.
	 *
	 * @return void
	 */
	public function testABareStringMeansUidOrGroup(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'bob' && $group === 'bezwaar')
		);
		$assignee = new FlowRunAssignee(groupManager: $groups);

		$this->assertTrue($this->may($assignee, ' alice ', ' alice'), 'the uid, trimmed on both sides');
		$this->assertTrue($this->may($assignee, 'bezwaar', 'bob'), 'a member of the named group');
		$this->assertFalse($this->may($assignee, 'bezwaar', 'carol'), 'not a member');
		$this->assertFalse($this->may($assignee, 'alice', 'Alice'), 'uids are compared exactly');
		$this->assertFalse($this->may(new FlowRunAssignee(), 'bezwaar', 'bob'), 'no group manager refuses');
		$this->assertTrue($this->may(new FlowRunAssignee(), 'bob', 'bob'), 'the uid needs no group manager');
	}//end testABareStringMeansUidOrGroup()

	/**
	 * A typed reference admits whoever it resolves to; without a resolver it refuses.
	 *
	 * @return void
	 */
	public function testATypedReferenceResolvesFresh(): void {
		$registry = $this->createMock(PrincipalResolverRegistry::class);
		$registry->method('resolveAll')->willReturn(['dana', 'erik']);
		$assignee = new FlowRunAssignee(principals: $registry);

		$this->assertTrue($this->may($assignee, ['type' => 'role', 'id' => 'teamlead'], 'erik'));
		$this->assertTrue($this->may($assignee, [['type' => 'role', 'id' => 'a'], ['type' => 'group', 'id' => 'b']], ' dana '));
		$this->assertFalse($this->may($assignee, ['type' => 'role', 'id' => 'teamlead'], 'frank'));
		$this->assertFalse($this->may(new FlowRunAssignee(), ['type' => 'role', 'id' => 'teamlead'], 'erik'), 'no resolver refuses');
	}//end testATypedReferenceResolvesFresh()
}//end class
