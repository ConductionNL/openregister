<?php

/**
 * Which stored `assignee` values mean "this caller".
 *
 * 🔴 THE RESOLVER DECIDES AUTHORISATION, NOT LISTING. A typed assignee is
 * stored as `type:id`, so the caller's identity has to expand to a small fixed
 * set of strings the datastore can match with an IN. Resolving a reference per
 * row instead would resolve it a hundred times on one page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace Unit\Db;

use OCA\OpenRegister\Db\TaskInboxCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see TaskInboxCriteria::assigneeNames()}.
 *
 * @covers \OCA\OpenRegister\Db\TaskInboxCriteria
 */
final class TaskInboxCriteriaNamesTest extends TestCase {

	/**
	 * 🔴 THE BARE UID IS FIRST AND NEVER DROPPED.
	 *
	 * Every flow ever authored names people by bare uid, and none of them may
	 * stop working because the picker now writes a typed reference.
	 *
	 * @return void
	 */
	public function testTheBareUidIsAlwaysAName(): void {
		$this->assertContains('alice', (new TaskInboxCriteria(uid: 'alice'))->assigneeNames());
	}//end testTheBareUidIsAlwaysAName()

	/**
	 * The caller's own typed reference is a name.
	 *
	 * @return void
	 */
	public function testTheCallersTypedReferenceIsAName(): void {
		$this->assertContains('user:alice', (new TaskInboxCriteria(uid: 'alice'))->assigneeNames());
	}//end testTheCallersTypedReferenceIsAName()

	/**
	 * 🔑 A GROUP REFERENCE IS A DIRECT ASSIGNEE, NOT A CANDIDATE POOL.
	 *
	 * A step naming `{type: group, id: bezwaar}` assigns the task to the group,
	 * and every member has to see it in the inbox — otherwise the task exists,
	 * the run is healthy, and nobody is looking at it.
	 *
	 * @return void
	 */
	public function testEveryGroupTheCallerIsInIsAName(): void {
		$names = (new TaskInboxCriteria(uid: 'alice', groupIds: ['bezwaar', 'reviewers']))->assigneeNames();

		$this->assertContains('group:bezwaar', $names);
		$this->assertContains('group:reviewers', $names);
	}//end testEveryGroupTheCallerIsInIsAName()

	/**
	 * 🔴 AN AGENT'S TASK STAYS OUT OF A PERSON'S INBOX.
	 *
	 * An agent step must not be answerable by the humans, nor appear to be
	 * theirs. Nothing here ever produces an `agent:` name, whatever the caller
	 * is called or belongs to.
	 *
	 * @return void
	 */
	public function testNoNameIsEverAnAgentReference(): void {
		$names = (new TaskInboxCriteria(uid: 'scribe', groupIds: ['agents']))->assigneeNames();

		foreach ($names as $name) {
			$this->assertStringStartsNotWith('agent:', $name);
		}
	}//end testNoNameIsEverAnAgentReference()

	/**
	 * An empty group id contributes no name.
	 *
	 * `group:` would match a stored value of exactly that, which is not a group
	 * anybody holds.
	 *
	 * @return void
	 */
	public function testAnEmptyGroupIdIsNotAName(): void {
		$this->assertNotContains(
			'group:',
			(new TaskInboxCriteria(uid: 'alice', groupIds: ['', '  ']))->assigneeNames()
		);
	}//end testAnEmptyGroupIdIsNotAName()

	/**
	 * The list carries no duplicates, so the IN stays small.
	 *
	 * @return void
	 */
	public function testTheListHasNoDuplicates(): void {
		$names = (new TaskInboxCriteria(uid: 'alice', groupIds: ['bezwaar', 'bezwaar']))->assigneeNames();

		$this->assertSame(array_values(array_unique($names)), $names);
	}//end testTheListHasNoDuplicates()
}//end class
