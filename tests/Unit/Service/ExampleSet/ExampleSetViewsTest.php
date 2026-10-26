<?php

/**
 * A fragment may declare saved views for the set's own accounts: checked
 * before anything is written, written once through the view service with
 * their alert, proved, and deleted on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetViews;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Db\View
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 */
class ExampleSetViewsTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * A fragment with the given views.
	 *
	 * @param array<int, array<string, mixed>> $views The views.
	 *
	 * @return void
	 */
	private function withViews(array $views): void {
		$this->app(app: 'alpha', fragment: ['views' => $views]);
	}

	/**
	 * A saved search with a count alert, owned by the cast's employee.
	 *
	 * @return array<string, mixed>
	 */
	private function savedSearch(): array {
		return ['key' => 'zoek-lindelaan', 'data' => [
			'name'     => 'Lindelaan',
			'owner'    => '{{cast:esra.uid}}',
			'isPublic' => true,
			'query'    => ['filters' => [], 'search' => 'Lindelaan', 'sort' => null, 'scope' => 'source:search'],
			'alert'    => ['operator' => 'gte', 'threshold' => 12],
		]];
	}

	public function testASavedSearchIsWrittenOnceWithItsAlertAndRemoved(): void {
		$this->withViews([$this->savedSearch()]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->savedViews, 'the check writes no view');

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(['alpha/zoek-lindelaan' => 'created'], $report['views']);
		$view = $this->savedViews[ExampleSetViews::uuidFor(app: 'alpha', key: 'zoek-lindelaan')];
		$this->assertSame('esra.demir', $view->getOwner());
		$this->assertTrue($view->getIsPublic());
		$this->assertSame('Lindelaan', $view->getQuery()['search']);
		$this->assertSame(['operator' => 'gte', 'threshold' => 12], $view->getAlert());

		$this->assertSame(['alpha/zoek-lindelaan' => 'kept'], $this->installer->install(plan: $plan, today: $this->today())['views']);
		$this->assertCount(1, $this->savedViews, 'a second install writes nothing');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame(1, $removed['removed']['views']);
		$this->assertSame([], $this->savedViews);
	}

	/**
	 * A view can be shared with the set's own groups and accounts, as board
	 * DqFilterDelen draws Pieter's "Wacht op inwoner" shared with Woo en
	 * informatie (may use) and Anouk Bakker (may update).
	 *
	 * @return void
	 */
	public function testAViewIsWrittenWithItsShares(): void {
		$view = $this->savedSearch();
		$view['data']['sharedWith'] = [
			['group' => '{{cast:staff.gid}}', 'mode' => 'read'],
			['user' => '{{cast:sanne.uid}}', 'mode' => 'write'],
		];
		$this->withViews(views: [$view]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(
			expected: [['group' => 'demo-staff', 'mode' => 'read'], ['user' => 'sanne.devries', 'mode' => 'write']],
			actual: $this->savedViews[ExampleSetViews::uuidFor(app: 'alpha', key: 'zoek-lindelaan')]->getSharedWith()
		);
	}//end testAViewIsWrittenWithItsShares()

	/**
	 * A user preference may quote a view the same fragment declares, as
	 * board DqFilterInMenu has Pieter's "Termijn deze week" in his menu: the
	 * check accepts the placeholder before the view exists, and the install
	 * writes the preference after the view, with its uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-view-placeholder/specs/example-sets/spec.md#requirement-a-preference-may-quote-a-view-of-its-fragment
	 */
	public function testAPreferenceQuotesAViewOfItsFragment(): void {
		$this->app(app: 'alpha', fragment: [
			'views'           => [$this->savedSearch()],
			'userPreferences' => [[
				'user'  => '{{cast:esra.uid}}',
				'key'   => 'pref_cn_saved_view_menu',
				'value' => '[{"id":"{{view:zoek-lindelaan.uuid}}","page":"Search","name":"Lindelaan"}]',
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->savedViews, message: 'the check writes no view');

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(
			expected: '[{"id":"'.ExampleSetViews::uuidFor(app: 'alpha', key: 'zoek-lindelaan').'","page":"Search","name":"Lindelaan"}]',
			actual: $this->userValues['esra.demir']['alpha']['pref_cn_saved_view_menu']
		);
	}//end testAPreferenceQuotesAViewOfItsFragment()

	/**
	 * A preference quoting a view the fragment does not declare refuses the set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-view-placeholder/specs/example-sets/spec.md#requirement-a-preference-may-quote-a-view-of-its-fragment
	 */
	public function testAPreferenceQuotingAnUnknownViewIsRefused(): void {
		$this->app(app: 'alpha', fragment: [
			'views'           => [$this->savedSearch()],
			'userPreferences' => [[
				'user'  => '{{cast:esra.uid}}',
				'key'   => 'pref_cn_saved_view_menu',
				'value' => '[{"id":"{{view:nope.uuid}}","page":"Search","name":"Nope"}]',
			]],
		]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'cannot resolve', haystack: $refusals[0]);
	}//end testAPreferenceQuotingAnUnknownViewIsRefused()

	/**
	 * A refresh updates a view where it is, so its uuid, and every link and menu entry that names it, stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-views-keep-their-uuid/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function testARefreshUpdatesAViewInPlace(): void {
		$this->withViews([$this->savedSearch()]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$before = array_keys($this->savedViews);

		$this->withViews([['key' => 'zoek-lindelaan', 'data' => ['name' => 'Lindelaan en Parkweg'] + $this->savedSearch()['data']]]);
		$report = $this->installer->install(plan: ($this->catalogue->gather(set: 'demo') + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame($before, array_keys($this->savedViews));
		$this->assertSame('Lindelaan en Parkweg', $this->savedViews[$before[0]]->getName());
	}//end testARefreshUpdatesAViewInPlace()

	/**
	 * A seeded view's uuid comes from its app and key, so a remove and a new install give it the same uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-views-keep-their-uuid/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function testAViewKeepsItsUuidAcrossARemove(): void {
		$this->withViews([$this->savedSearch()]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$this->remover->remove(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame([ExampleSetViews::uuidFor(app: 'alpha', key: 'zoek-lindelaan')], array_keys($this->savedViews));
	}//end testAViewKeepsItsUuidAcrossARemove()

	public function testAViewThatDisappearedIsReportedMissing(): void {
		$this->withViews([$this->savedSearch()]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->savedViews = [];

		$this->assertContains('view alpha/zoek-lindelaan', $this->installer->install(plan: $plan, today: $this->today())['missing']);
	}

	/**
	 * @dataProvider refusedViews
	 *
	 * @param array<string, mixed> $data   The view's fields.
	 * @param string               $reason A part of the refusal.
	 */
	public function testACheckRefusesAFaultyViewAndWritesNothing(array $data, string $reason): void {
		$this->withViews([['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('view "bad"', $refusals[0]);
		$this->assertStringContainsString($reason, $refusals[0]);
		$this->assertSame([], $this->savedViews);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedViews(): array {
		return [
			'no name'       => [['owner' => '{{cast:esra.uid}}'], 'needs a name'],
			'a stranger'    => [['name' => 'V', 'owner' => 'somebody.else'], 'owner "somebody.else"'],
			'another field' => [['name' => 'V', 'owner' => '{{cast:esra.uid}}', 'favoredBy' => []], 'field "favoredBy"'],
			'a bad query'   => [['name' => 'V', 'owner' => '{{cast:esra.uid}}', 'query' => 'Lindelaan'], 'query must be an object'],
			'shares not a list' => [['name' => 'V', 'owner' => '{{cast:esra.uid}}', 'sharedWith' => 'staff'], 'sharedWith must be a list'],
			'a share to a stranger' => [
				['name' => 'V', 'owner' => '{{cast:esra.uid}}', 'sharedWith' => [['user' => 'somebody.else', 'mode' => 'read']]],
				'share to "somebody.else"',
			],
			'a share without a target' => [
				['name' => 'V', 'owner' => '{{cast:esra.uid}}', 'sharedWith' => [['mode' => 'read']]],
				'a share names one user or one group',
			],
			'a share with a bad mode' => [
				['name' => 'V', 'owner' => '{{cast:esra.uid}}', 'sharedWith' => [['group' => 'demo-staff', 'mode' => 'admin']]],
				'mode "admin"',
			],
		];
	}
}//end class
