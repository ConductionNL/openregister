<?php

/**
 * `--refresh` writes a kept object only when what it would send differs from
 * the stored row; an unchanged object is counted, not written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 */
class ExampleSetRefreshSkipsUnchangedTest extends ExampleSetTestCase {
	/**
	 * A theme, and an incident reported the day before the install.
	 *
	 * @return void
	 */
	private function incident(): void {
		$this->store->offer(register: 'pub', schema: 'theme');
		$this->store->offer(register: 'pub', schema: 'incident');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			['key' => 'theme-1', 'register' => 'pub', 'schema' => 'theme', 'data' => ['slug' => 'wonen']],
			[
				'key'      => 'incident-1',
				'register' => 'pub',
				'schema'   => 'incident',
				'data'     => ['title' => 'Lamp kapot', 'reported' => '{{date:-1}}'],
			],
		]]);
	}//end incident()

	/**
	 * The stored incident.
	 *
	 * @return array<string, mixed>
	 */
	private function storedIncident(): array {
		return $this->store->rows['pub incident'][$this->store->ids(register: 'pub', schema: 'incident')[0]];
	}//end storedIncident()

	/**
	 * A second refresh on the same day, with nothing changed on the instance, writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	public function testASameDayRefreshWritesNothing(): void {
		$this->incident();
		$plan = ($this->catalogue->gather(set: 'demo') + ['refresh' => true]);
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);
		$this->installer->install(plan: $plan, today: $this->today());
		$updates = $this->store->updates;

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame($updates, $this->store->updates, 'no kept object is written');
		$this->assertSame(1, $report['types']['pub incident']['unchanged']);
		$this->assertSame(1, $report['types']['pub theme']['unchanged']);
		$this->assertArrayNotHasKey('refreshed', $report['types']['pub incident']);
	}//end testASameDayRefreshWritesNothing()

	/**
	 * Control: a declared date that moved on a later day is still written, and only that object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	public function testAMovedDateIsStillWritten(): void {
		$this->incident();
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);
		$updates = $this->store->updates;

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+3 days'));

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame($updates + 1, $this->store->updates, 'only the incident is written');
		$this->assertSame(1, $report['types']['pub incident']['refreshed']);
		$this->assertSame(1, $report['types']['pub theme']['unchanged']);
		$this->assertSame('2026-10-15', $this->storedIncident()['reported']);
	}//end testAMovedDateIsStillWritten()

	/**
	 * Control: a value an editor changed on the instance is written back on a same-day refresh.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	public function testAnInstanceEditIsWrittenBack(): void {
		$this->incident();
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);
		$id = $this->store->ids(register: 'pub', schema: 'incident')[0];
		$this->store->rows['pub incident'][$id]['title'] = 'Lamp gerepareerd';
		$updates = $this->store->updates;

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame($updates + 1, $this->store->updates);
		$this->assertSame(1, $report['types']['pub incident']['refreshed']);
		$this->assertSame('Lamp kapot', $this->storedIncident()['title']);
	}//end testAnInstanceEditIsWrittenBack()
}//end class
