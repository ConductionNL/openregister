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
		$view = $this->savedViews['view-1'];
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
		];
	}
}//end class
