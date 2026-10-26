<?php

/**
 * Tests that a change or preference the record holds stays in step with its declaration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-held-entries-follow-the-declaration/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A held change proves only the fields it still declares and a refresh puts back what an import undid; a held
 * preference follows a view that a refresh wrote again.
 */
class ExampleSetHeldEntriesTest extends ExampleSetTestCase {

	/**
	 * Set up the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A fragment that changes a connection another app shipped.
	 *
	 * @param array<string, mixed> $set The fields the change sets.
	 *
	 * @return void
	 */
	private function connectionChange(array $set): void {
		$this->app(app: 'alpha', fragment: [
			'changes' => [[
				'key'      => 'stuf-bron',
				'register' => 'int',
				'schema'   => 'connection',
				'where'    => ['key' => 'stuf'],
				'set'      => $set,
			]],
		]);
	}//end connectionChange()

	/**
	 * A field the change no longer declares (a job's health check owns it) is not proved, and the record lets go of it.
	 *
	 * @return void
	 */
	public function testAFieldTheChangeNoLongerDeclaresIsNotProved(): void {
		$this->store->offer(register: 'int', schema: 'connection');
		$id = $this->store->seed(register: 'int', schema: 'connection', data: ['key' => 'stuf', 'source' => null, 'status' => 'unconfigured']);
		$this->connectionChange(set: ['source' => 'zaakmagazijn', 'status' => 'error']);
		$this->assertTrue($this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['ok']);

		$this->connectionChange(set: ['source' => 'zaakmagazijn']);
		$this->store->rows['int connection'][$id]['status'] = 'ok';
		$report = $this->installer->install(plan: ($this->catalogue->gather(set: 'demo') + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('ok', $this->store->rows['int connection'][$id]['status'], 'the job\'s value is left');
	}//end testAFieldTheChangeNoLongerDeclaresIsNotProved()

	/**
	 * A refresh writes a declared field again when an import put back the value the change replaced.
	 *
	 * @return void
	 */
	public function testARefreshPutsBackAChangeAnImportUndid(): void {
		$this->store->offer(register: 'int', schema: 'connection');
		$id = $this->store->seed(register: 'int', schema: 'connection', data: ['key' => 'stuf', 'name' => 'Fase 3', 'version' => 1]);
		$this->connectionChange(set: ['name' => 'Oplevering', 'version' => 2]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);

		$this->store->rows['int connection'][$id]['name']    = 'Fase 3';
		$this->store->rows['int connection'][$id]['version'] = 1;
		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(['Oplevering', 2], [$this->store->rows['int connection'][$id]['name'], $this->store->rows['int connection'][$id]['version']]);
		$this->assertSame('refreshed', $report['changes']['alpha/stuf-bron']);
	}//end testARefreshPutsBackAChangeAnImportUndid()

	/**
	 * A field somebody gave a third value is theirs: the refresh leaves it.
	 *
	 * @return void
	 */
	public function testARefreshLeavesAFieldSomebodyEdited(): void {
		$this->store->offer(register: 'int', schema: 'connection');
		$id = $this->store->seed(register: 'int', schema: 'connection', data: ['key' => 'stuf', 'name' => 'Fase 3']);
		$this->connectionChange(set: ['name' => 'Oplevering']);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->store->rows['int connection'][$id]['name'] = 'Eigen naam';
		$this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertSame('Eigen naam', $this->store->rows['int connection'][$id]['name']);
	}//end testARefreshLeavesAFieldSomebodyEdited()

	/**
	 * A refresh writes a view again under a new uuid; the preference that quotes it moves along.
	 *
	 * @return void
	 */
	public function testAPreferenceFollowsAViewARefreshWroteAgain(): void {
		$this->app(app: 'alpha', fragment: [
			'views'           => [[
				'key'  => 'zoek-lindelaan',
				'data' => ['name' => 'Lindelaan', 'owner' => '{{cast:esra.uid}}', 'query' => ['_search' => 'Lindelaan']],
			]],
			'userPreferences' => [[
				'user'  => '{{cast:esra.uid}}',
				'key'   => 'pref_cn_saved_view_menu',
				'value' => '[{"id":"{{view:zoek-lindelaan.uuid}}","page":"Search","name":"Lindelaan"}]',
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$uuid = (string) array_key_last($this->savedViews);
		$this->assertSame(
			expected: '[{"id":"'.$uuid.'","page":"Search","name":"Lindelaan"}]',
			actual: $this->userValues['esra.demir']['alpha']['pref_cn_saved_view_menu']
		);
		$this->assertSame('refreshed', $report['userPreferences']['alpha/esra.demir/pref_cn_saved_view_menu']);
	}//end testAPreferenceFollowsAViewARefreshWroteAgain()
}//end class
