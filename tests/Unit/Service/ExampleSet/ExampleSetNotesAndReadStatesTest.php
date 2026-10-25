<?php

/**
 * A fragment may leave notes of the set's own accounts on its objects and say
 * which objects they opened, and when: checked before anything is written,
 * written once, proved, and taken away on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetNotesAndReadStatesTest extends ExampleSetTestCase {
	/**
	 * A case register and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * One case, with the notes and read states given.
	 *
	 * @param array<int, array<string, mixed>> $notes      The fragment's note rows.
	 * @param array<int, array<string, mixed>> $readStates The fragment's read-state rows.
	 *
	 * @return void
	 */
	private function caseWith(array $notes = [], array $readStates = []): void {
		$this->app(app: 'alpha', fragment: [
			'objects'    => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'notes'      => $notes,
			'readStates' => $readStates,
		]);
	}//end caseWith()

	/**
	 * A colleague's note of this afternoon and the case she opened this morning
	 * are written once, at those moments, and taken away on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md#requirement-a-fragment-may-leave-notes-of-the-sets-own-accounts-on-its-objects
	 * @spec openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md#requirement-a-fragment-may-say-which-objects-the-sets-own-accounts-opened-and-when
	 * @spec openspec/changes/example-set-notes-and-read-states/specs/example-sets/spec.md#requirement-remove-takes-away-the-notes-and-read-states-a-set-wrote
	 */
	public function testANoteAndAReadStateAreWrittenOnceAtTheirMomentAndTakenAwayOnRemove(): void {
		$this->caseWith(
			notes: [['key' => 'esra-noteert', 'data' => [
				'user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}', 'message' => 'Inwoner gebeld.', 'createdAt' => '{{datetime:0 15:40}}',
			]]],
			readStates: [['key' => 'esra-opende', 'data' => [
				'user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}', 'register' => 'cases', 'schema' => 'case', 'seenAt' => '{{datetime:0 09:12}}',
			]]]
		);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->notes, message: 'the check writes no note');
		$this->assertSame(expected: [], actual: $this->readStates, message: 'the check writes no read state');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/esra-noteert' => 'created'], actual: $report['notes']);
		$this->assertSame(expected: ['alpha/esra-opende' => 'created'], actual: $report['readStates']);

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$note   = array_values($this->notes)[0];
		$this->assertSame(expected: 'esra.demir', actual: $note['user']);
		$this->assertSame(expected: $caseId, actual: $note['object']);
		$this->assertSame(expected: 'Inwoner gebeld.', actual: $note['message']);
		$this->assertSame(expected: '2026-10-13 15:40', actual: $note['createdAt']->format('Y-m-d H:i'));
		$this->assertSame(expected: '2026-10-13 09:12', actual: $this->readStates['esra.demir '.$caseId]->format('Y-m-d H:i'));

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: ['alpha/esra-noteert' => 'kept'], actual: $again['notes']);
		$this->assertSame(expected: ['alpha/esra-opende' => 'kept'], actual: $again['readStates']);
		$this->assertCount(expectedCount: 1, haystack: $this->notes);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: 1, actual: $removed['removed']['notes']);
		$this->assertSame(expected: 1, actual: $removed['removed']['readStates']);
		$this->assertSame(expected: [], actual: $this->notes);
		$this->assertSame(expected: [], actual: $this->readStates);
	}//end testANoteAndAReadStateAreWrittenOnceAtTheirMomentAndTakenAwayOnRemove()

	/**
	 * A note quotes a task of its fragment by `{{task:key.uuid}}`: a stand-in
	 * passes the check, and the install writes the note on the task written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-placeholder/specs/example-sets/spec.md#requirement-a-note-or-read-state-may-quote-a-task-of-its-fragment
	 */
	public function testANoteQuotesATaskOfItsFragment(): void {
		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'tasks'   => [['key' => 'hoorzitting', 'data' => [
				'title' => 'Hoorzitting inplannen', 'objectUuid' => '{{object:case-1.id}}', 'state' => 'active', 'assignee' => '{{cast:esra.uid}}',
			]]],
			'notes'   => [['key' => 'esra-noteert-taak', 'data' => [
				'user'      => '{{cast:esra.uid}}',
				'objectUuid' => '{{task:hoorzitting.uuid}}',
				'message'   => 'Zaal vastgelegd.',
				'createdAt' => '{{datetime:0 11:15}}',
			]]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$task = array_values($this->engineTasks)[0];
		$this->assertSame(expected: $task->getUuid(), actual: array_values($this->notes)[0]['object']);
	}//end testANoteQuotesATaskOfItsFragment()

	/**
	 * A note quoting a task its fragment does not declare is refused at check time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-placeholder/specs/example-sets/spec.md#requirement-a-note-or-read-state-may-quote-a-task-of-its-fragment
	 */
	public function testANoteQuotingAnUnknownTaskIsRefused(): void {
		$this->caseWith(notes: [['key' => 'n', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{task:nope.uuid}}', 'message' => 'x']]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'cannot resolve', haystack: $refusals[0]);
	}//end testANoteQuotingAnUnknownTaskIsRefused()

	/**
	 * `--refresh` writes a note and a read state again from today's declaration, once each.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-refresh/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	public function testARefreshWritesTheNoteAndReadStateAgainForToday(): void {
		$this->caseWith(
			notes: [['key' => 'n', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}', 'message' => 'Inwoner gebeld.', 'createdAt' => '{{datetime:0 15:40}}']]],
			readStates: [['key' => 'r', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}', 'register' => 'cases', 'schema' => 'case', 'seenAt' => '{{datetime:0 09:12}}']]]
		);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$later  = $this->today()->modify('+3 days');
		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $later);

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/n' => 'refreshed'], actual: $report['notes']);
		$this->assertSame(expected: ['alpha/r' => 'refreshed'], actual: $report['readStates']);
		$this->assertCount(expectedCount: 1, haystack: $this->notes);
		$this->assertSame(expected: '2026-10-16 15:40', actual: array_values($this->notes)[0]['createdAt']->format('Y-m-d H:i'));
		$this->assertSame(expected: '2026-10-16 09:12', actual: array_values($this->readStates)[0]->format('Y-m-d H:i'));
	}//end testARefreshWritesTheNoteAndReadStateAgainForToday()

	/**
	 * A note or read state that disappeared is reported missing.
	 *
	 * @return void
	 */
	public function testWhatDisappearedIsReportedMissing(): void {
		$this->caseWith(
			notes: [['key' => 'n', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}', 'message' => 'x']]],
			readStates: [['key' => 'r', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}']]]
		);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->notes      = [];
		$this->readStates = [];
		$report           = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertContains(needle: 'note alpha/n', haystack: $report['missing']);
		$this->assertContains(needle: 'read state alpha/r', haystack: $report['missing']);
		$this->assertFalse(condition: $report['ok']);
	}//end testWhatDisappearedIsReportedMissing()

	/**
	 * A faulty note or read state is refused at check time and nothing is written.
	 *
	 * @param string               $part   `notes` or `readStates`.
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedRows
	 */
	public function testACheckRefusesAFaultyRowAndWritesNothing(string $part, array $data, string $reason): void {
		$row = [['key' => 'bad', 'data' => $data]];
		if ($part === 'notes') {
			$this->caseWith(notes: $row);
		} else {
			$this->caseWith(readStates: $row);
		}

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: '"bad"', haystack: $refusals[0]);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->notes);
		$this->assertSame(expected: [], actual: $this->readStates);
	}//end testACheckRefusesAFaultyRowAndWritesNothing()

	/**
	 * The faulty rows and a part of their refusal.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function refusedRows(): array {
		$on = '{{object:case-1.id}}';
		return [
			'a note by a stranger'      => ['notes', ['user' => 'somebody.else', 'objectUuid' => $on, 'message' => 'x'], 'user "somebody.else"'],
			'a note without a message'  => ['notes', ['user' => '{{cast:esra.uid}}', 'objectUuid' => $on], 'needs a message'],
			'a note on nothing'         => ['notes', ['user' => '{{cast:esra.uid}}', 'message' => 'x'], 'needs the object'],
			'a note at no moment'       => ['notes', ['user' => '{{cast:esra.uid}}', 'objectUuid' => $on, 'message' => 'x', 'createdAt' => 'gisteren'], 'createdAt is a date'],
			'a note with another field' => ['notes', ['user' => '{{cast:esra.uid}}', 'objectUuid' => $on, 'message' => 'x', 'verb' => 'x'], 'field "verb"'],
			'a read state of a stranger' => ['readStates', ['user' => 'somebody.else', 'objectUuid' => $on], 'user "somebody.else"'],
			'a read state of nothing'   => ['readStates', ['user' => '{{cast:esra.uid}}'], 'needs the object'],
			'a read state at no moment' => ['readStates', ['user' => '{{cast:esra.uid}}', 'objectUuid' => $on, 'seenAt' => 'vanochtend'], 'seenAt is a date'],
		];
	}//end refusedRows()
}//end class
