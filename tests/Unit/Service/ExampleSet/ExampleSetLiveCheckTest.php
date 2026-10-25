<?php

/**
 * Tests for the two refusals the first live check of the zuiddrecht set hit
 * that were the loader's fault, not the data's:
 *
 * - dossiq's change that sets a case's `result` was validated with the case's
 *   `statusHistory` as a list, while the case is written with it as JSON text
 *   (`jsonFields`), so the check refused a change the install would make.
 * - pipelinq's `accountOwner` and `assignee` name a cast member's account,
 *   which the `user` format refused because the check runs before the
 *   accounts are written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetLiveCheckTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->store->offer(register: 'cases', schema: 'result');
		$this->store->offer(register: 'crm', schema: 'ticket');
		$this->store->textFields['cases case'] = ['statusHistory', 'history'];
		$this->store->userFields['crm ticket'] = ['assignee', 'owner'];
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * Dossiq's shape: a case with its history as JSON text, its result, and a
	 * change that links the case to the result.
	 */
	public function testAChangeOnACaseWithAJsonTextHistoryPassesTheCheckAndKeepsTheHistoryAsText(): void {
		$this->app(app: 'cases', fragment: [
			'objects' => [
				[
					'key'        => 'case-1',
					'register'   => 'cases',
					'schema'     => 'case',
					'jsonFields' => ['statusHistory'],
					'data'       => ['title' => 'Parkeren', 'statusHistory' => [['status' => 'ontvangen', 'enteredAt' => '{{date:-3}}']]],
				],
				['key' => 'result-1', 'register' => 'cases', 'schema' => 'result', 'data' => ['case' => '{{object:case-1.id}}']],
			],
			'changes' => [['key' => 'case-1-result', 'register' => 'cases', 'schema' => 'case', 'object' => 'case-1', 'set' => ['result' => '{{object:result-1.id}}']]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('created', $report['changes']['cases/case-1-result']);
		$case   = $this->store->find(register: 'cases', schema: 'case', filters: [])[0];
		$result = $this->store->find(register: 'cases', schema: 'result', filters: [])[0];
		$this->assertSame($result['id'], $case['result']);
		$this->assertSame('[{"status":"ontvangen","enteredAt":"2026-10-10"}]', $case['statusHistory']);

		$this->assertSame([], $this->remover->remove(set: 'demo')['failed']);
		$this->assertSame([], $this->store->ids(register: 'cases', schema: 'case'));
	}

	/**
	 * A text property the store reads back as a structure (a GeoJSON geometry
	 * kept as JSON text) is validated and written as text again when a change
	 * touches another field of the object, without the change naming it.
	 *
	 * @spec openspec/changes/example-set-change-keeps-text/specs/example-sets/spec.md#requirement-a-change-sends-a-text-property-as-text
	 */
	public function testAChangeSendsATextPropertyTheStoreReadsAsAStructureAsText(): void {
		$this->store->textFields['cases case'][] = 'geometry';
		$this->store->storedAs['cases case']['geometry'] = static fn (mixed $value): mixed => (is_string($value) ? json_decode($value, true) : $value);
		$this->app(app: 'cases', fragment: [
			'objects' => [
				['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Parkeren', 'geometry' => '{"type":"Point","coordinates":[4.6612,51.8148]}']],
				['key' => 'result-1', 'register' => 'cases', 'schema' => 'result', 'data' => ['case' => '{{object:case-1.id}}']],
			],
			'changes' => [['key' => 'case-1-result', 'register' => 'cases', 'schema' => 'case', 'object' => 'case-1', 'set' => ['result' => '{{object:result-1.id}}']]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('created', $report['changes']['cases/case-1-result']);
		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals'], 'the check on the kept object');
		$this->assertSame([], $this->remover->remove(set: 'demo')['failed'], 'remove writes the text property as text too');
	}//end testAChangeSendsATextPropertyTheStoreReadsAsAStructureAsText()

	/**
	 * The check counts objects per register and schema as the install declares
	 * them, and a change on the same schema apart (the zuiddrecht set read 12
	 * dossiq cases checked against 10 declared).
	 */
	public function testTheCheckCountsObjectsAsTheInstallDeclaresThemAndChangesApart(): void {
		$this->app(app: 'cases', fragment: [
			'objects' => [
				['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Parkeren']],
				['key' => 'result-1', 'register' => 'cases', 'schema' => 'result', 'data' => ['case' => '{{object:case-1.id}}']],
			],
			'changes' => [['key' => 'case-1-result', 'register' => 'cases', 'schema' => 'case', 'object' => 'case-1', 'set' => ['result' => '{{object:result-1.id}}']]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$checked = $this->installer->check(plan: $plan, today: $this->today());
		$report  = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(['cases case' => 1, 'cases result' => 1], $checked['checked']);
		$this->assertSame(['cases case' => 1], $checked['checkedChanges']);
		$this->assertSame($report['types']['cases case']['declared'], $checked['checked']['cases case'], 'check and install count the same objects');
		$this->assertSame(['cases/case-1-result' => 'created'], $report['changes']);
	}

	/**
	 * A change that declares its own `jsonFields` appends to the list inside
	 * the text, is proved against the decoded list, and is taken out again.
	 */
	public function testAnAppendToAJsonTextFieldIsWrittenAsTextProvedAsAListAndReverted(): void {
		$id = $this->store->seed(register: 'cases', schema: 'case', data: ['slug' => 'c-1', 'history' => '[{"at":"a"}]']);
		$this->app(app: 'cases', fragment: [
			'changes' => [[
				'key'        => 'history-b',
				'register'   => 'cases',
				'schema'     => 'case',
				'where'      => ['slug' => 'c-1'],
				'jsonFields' => ['history'],
				'append'     => ['history' => ['at' => 'b']],
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame([], $report['lost']);
		$this->assertSame('[{"at":"a"},{"at":"b"}]', $this->store->get(register: 'cases', schema: 'case', id: $id)['history']);

		$this->assertSame([], $this->remover->remove(set: 'demo')['failed']);
		$this->assertSame('[{"at":"a"}]', $this->store->get(register: 'cases', schema: 'case', id: $id)['history']);
	}

	/**
	 * Pipelinq's shape: a ticket assigned to a cast member and owned by a
	 * service account, neither of which exists before the install.
	 */
	public function testAnAccountTheSetCreatesPassesAUserFormatInTheCheckAndIsWrittenBeforeTheObject(): void {
		$this->app(app: 'crm', fragment: [
			'serviceAccounts' => [['key' => 'bot', 'uid' => 'demo_bot', 'displayName' => 'Bot', 'groups' => ['crm-bots']]],
			'objects'         => [[
				'key'      => 'ticket-1',
				'register' => 'crm',
				'schema'   => 'ticket',
				'data'     => ['title' => 'Afvalkalender', 'assignee' => '{{cast:esra.uid}}'],
			]],
			'changes'         => [['key' => 'hand-over', 'register' => 'crm', 'schema' => 'ticket', 'object' => 'ticket-1', 'set' => ['owner' => '{{account:bot.uid}}']]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->accounts->users, 'the check writes no account');

		// The fake's save validates the user format with no planned accounts,
		// so the ticket is only written when the accounts are there first.
		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$ticket = $this->store->find(register: 'crm', schema: 'ticket', filters: [])[0];
		$this->assertSame('esra.demir', $ticket['assignee']);
		$this->assertSame('demo_bot', $ticket['owner']);
	}

	/**
	 * Only the set's own accounts are let through: an id that is neither on
	 * the instance nor declared by the set is still refused, in an object and
	 * in a change; an existing account still passes.
	 */
	public function testAnAccountIdTheSetDoesNotDeclareIsStillRefused(): void {
		$this->accounts->createUser(uid: 'kees.bestaand', displayName: 'Kees', email: '', password: 'x');
		$this->app(app: 'crm', fragment: [
			'objects' => [
				['key' => 'ticket-1', 'register' => 'crm', 'schema' => 'ticket', 'data' => ['assignee' => 'nobody.here']],
				['key' => 'ticket-2', 'register' => 'crm', 'schema' => 'ticket', 'data' => ['assignee' => 'kees.bestaand']],
			],
			'changes' => [['key' => 'reassign', 'register' => 'crm', 'schema' => 'ticket', 'object' => 'ticket-2', 'set' => ['owner' => 'ghost.user']]],
		]);

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(
			[
				"crm/lib/Settings/example-sets/demo.json: object \"ticket-1\" (crm ticket): Property 'assignee' should match format 'user' but 'nobody.here' does not.",
				"crm/lib/Settings/example-sets/demo.json: change \"reassign\" (crm ticket): Property 'owner' should match format 'user' but 'ghost.user' does not.",
			],
			$result['refusals']
		);
	}
}
