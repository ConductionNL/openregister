<?php

/**
 * A fragment may let the set's own accounts follow its objects: checked
 * before anything is written, written once, proved, and taken away on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetWatchersTest extends ExampleSetTestCase {
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
	 * One case, and the follows given.
	 *
	 * @param array<int, array<string, mixed>> $watchers The fragment's watcher rows.
	 *
	 * @return void
	 */
	private function caseWithWatchers(array $watchers): void {
		$this->app(app: 'alpha', fragment: [
			'objects'  => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'watchers' => $watchers,
		]);
	}//end caseWithWatchers()

	/**
	 * A colleague follows the case once, quietly when asked, and stops following on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-watchers/specs/example-sets/spec.md#requirement-a-fragment-may-let-the-sets-own-accounts-follow-its-objects
	 */
	public function testAColleagueFollowsTheCaseOnceAndStopsOnRemove(): void {
		$this->caseWithWatchers(watchers: [
			['key' => 'esra-volgt', 'data' => [
				'user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}', 'register' => 'cases', 'schema' => 'case', 'notify' => false,
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->follows, message: 'the check writes no follow');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/esra-volgt' => 'created'], actual: $report['watchers']);

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$follow = ($this->follows['esra.demir '.$caseId] ?? null);
		$this->assertNotNull(actual: $follow);
		$this->assertFalse(condition: $follow->notifies());
		$this->assertSame(expected: 'cases', actual: $follow->getRegister());

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: ['alpha/esra-volgt' => 'kept'], actual: $again['watchers']);
		$this->assertCount(expectedCount: 1, haystack: $this->follows);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: 1, actual: $removed['removed']['watchers']);
		$this->assertSame(expected: [], actual: $this->follows);
	}//end testAColleagueFollowsTheCaseOnceAndStopsOnRemove()

	/**
	 * A follow that disappeared is reported missing.
	 *
	 * @return void
	 */
	public function testAFollowThatDisappearedIsReportedMissing(): void {
		$this->caseWithWatchers(watchers: [['key' => 'w', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}']]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->follows = [];
		$report        = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertContains(needle: 'watcher alpha/w', haystack: $report['missing']);
		$this->assertFalse(condition: $report['ok']);
	}//end testAFollowThatDisappearedIsReportedMissing()

	/**
	 * A faulty follow is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The follow's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedWatchers
	 */
	public function testACheckRefusesAFaultyFollowAndWritesNothing(array $data, string $reason): void {
		$this->caseWithWatchers(watchers: [['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'watcher "bad"', haystack: $refusals[0]);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->follows);
	}//end testACheckRefusesAFaultyFollowAndWritesNothing()

	/**
	 * The faulty follows and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedWatchers(): array {
		$on = '{{object:case-1.id}}';
		return [
			'a stranger'        => [['user' => 'somebody.else', 'objectUuid' => $on], 'user "somebody.else"'],
			'no object'         => [['user' => '{{cast:esra.uid}}'], 'needs the object'],
			'notify as text'    => [['user' => '{{cast:esra.uid}}', 'objectUuid' => $on, 'notify' => 'yes'], 'notify is true or false'],
			'another field'     => [['user' => '{{cast:esra.uid}}', 'objectUuid' => $on, 'role' => 'x'], 'field "role"'],
			'an unknown object' => [['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:nope.id}}'], 'cannot resolve'],
		];
	}//end refusedWatchers()
}//end class
