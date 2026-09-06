<?php

/**
 * The external performer type's verb table: the pooling and mandate verbs
 * refused for everyone naming the type, the answering verbs admitted to the
 * stored party alone with no administrator bypass, cancel the requester's.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the external branch of {@see TaskAuthorizationService}.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 * @covers \OCA\OpenRegister\Db\Task
 */
class TaskAuthorizationExternalTest extends TestCase {

	/**
	 * An external task matched to `party:bsn-1`, requested by `caseworker`.
	 *
	 * @return Task The task.
	 */
	private function externalTask(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');
		$task->setRequester('caseworker');

		return $task;
	}//end externalTask()

	/**
	 * A group backend where `root` is the administrator.
	 *
	 * @return IGroupManager The backend.
	 */
	private function adminIsRoot(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groups->method('isInGroup')->willReturn(false);
		$groups->method('groupExists')->willReturn(false);

		return $groups;
	}//end adminIsRoot()

	/**
	 * The refused verbs, with every kind of caller.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedVerbsAndCallers(): array {
		$rows = [];
		foreach (['claim', 'unclaim', 'delegate', 'offer', 'assign', 'reassign'] as $verb) {
			foreach (['party:bsn-1' => 'the party', 'caseworker' => 'the requester', 'root' => 'an administrator', 'stranger' => 'a stranger'] as $uid => $who) {
				$rows["$verb by $who"] = [$verb, $uid];
			}
		}

		return $rows;
	}//end refusedVerbsAndCallers()

	/**
	 * Claim, unclaim, delegate, offer, assign and reassign are refused for
	 * everyone, naming the performer type.
	 *
	 * @param string $verb The verb.
	 * @param string $uid The caller.
	 *
	 * @return void
	 */
	#[DataProvider('refusedVerbsAndCallers')]
	public function testPoolingAndMandateVerbsAreRefusedForEveryoneNamingTheType(string $verb, string $uid): void {
		$service = new TaskAuthorizationService(groupManager: $this->adminIsRoot());
		try {
			$service->assertMay(verb: $verb, task: $this->externalTask(), uid: $uid);
			$this->fail("$verb by $uid was admitted on an external task.");
		} catch (TaskAccessDeniedException $refused) {
			$this->assertStringContainsString("'external'", $refused->getMessage());
			$this->assertStringContainsString("'$verb'", $refused->getMessage());
		}
	}//end testPoolingAndMandateVerbsAreRefusedForEveryoneNamingTheType()

	/**
	 * Only the stored party completes: another party, the requester, an
	 * administrator, a uid equal to the bare reference, and nobody are denied.
	 *
	 * @return void
	 */
	public function testOnlyTheStoredPartyMayAnswer(): void {
		$service = new TaskAuthorizationService(groupManager: $this->adminIsRoot());
		$task = $this->externalTask();

		foreach (['complete', 'resolve', 'checklist'] as $verb) {
			$service->assertMay(verb: $verb, task: $task, uid: 'party:bsn-1');
			$this->addToAssertionCount(1);

			foreach (['party:bsn-2', 'caseworker', 'root', 'bsn-1', 'party:', 'PARTY:bsn-1', null, ''] as $uid) {
				try {
					$service->assertMay(verb: $verb, task: $task, uid: $uid);
					$this->fail("$verb by " . var_export($uid, true) . ' was admitted.');
				} catch (TaskAccessDeniedException) {
					$this->addToAssertionCount(1);
				}
			}
		}
	}//end testOnlyTheStoredPartyMayAnswer()

	/**
	 * An external task with no stored reference, or one that is not a party
	 * reference, admits nobody: undeterminable is a denial.
	 *
	 * @return void
	 */
	public function testAnUndeterminableComparisonDenies(): void {
		$service = new TaskAuthorizationService();
		foreach ([null, '', 'alice'] as $assignee) {
			$task = $this->externalTask();
			$task->setAssignee($assignee);
			foreach (['party:alice', 'alice', 'party:'] as $uid) {
				try {
					$service->assertMay(verb: 'complete', task: $task, uid: $uid);
					$this->fail('An undeterminable comparison admitted ' . $uid);
				} catch (TaskAccessDeniedException) {
					$this->addToAssertionCount(1);
				}
			}
		}
	}//end testAnUndeterminableComparisonDenies()

	/**
	 * Cancel stays the requester's (and the administrator's); the party and a
	 * stranger cannot withdraw the ask.
	 *
	 * @return void
	 */
	public function testCancelIsTheRequestersOrAnAdministrators(): void {
		$service = new TaskAuthorizationService(groupManager: $this->adminIsRoot());
		$task = $this->externalTask();
		$service->assertMay(verb: 'cancel', task: $task, uid: 'caseworker');
		$service->assertMay(verb: 'cancel', task: $task, uid: 'root');
		$this->addToAssertionCount(2);

		foreach (['party:bsn-1', 'stranger'] as $uid) {
			try {
				$service->assertMay(verb: 'cancel', task: $task, uid: $uid);
				$this->fail("cancel by $uid was admitted.");
			} catch (TaskAccessDeniedException) {
				$this->addToAssertionCount(1);
			}
		}

		// Creation needs an identity and nothing else; an unknown verb has no rule.
		$service->assertMay(verb: 'create', task: $task, uid: 'caseworker');
		$this->expectException(TaskAccessDeniedException::class);
		$service->assertMay(verb: 'frobnicate', task: $task, uid: 'caseworker');
	}//end testCancelIsTheRequestersOrAnAdministrators()

	/**
	 * The vocabulary carries the type and the prefix a uid can never contain.
	 *
	 * @return void
	 */
	public function testTheVocabularyCarriesExternal(): void {
		$this->assertContains(Task::PERFORMER_EXTERNAL, Task::PERFORMER_TYPES);
		$this->assertSame('party:', Task::EXTERNAL_PARTY_PREFIX);
	}//end testTheVocabularyCarriesExternal()
}//end class
