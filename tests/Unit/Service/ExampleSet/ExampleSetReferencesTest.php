<?php

/**
 * Tests for what a reinstall after a partial remove left behind on :8080:
 * the themes were written again with new ids, but the publications the
 * remove had kept still pointed at the deleted themes.
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
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 */
class ExampleSetReferencesTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'pub', schema: 'theme');
		$this->store->offer(register: 'pub', schema: 'publication');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			['key' => 'theme-1', 'register' => 'pub', 'schema' => 'theme', 'data' => ['slug' => 'wonen', 'title' => 'Wonen']],
			[
				'key'      => 'pub-1',
				'register' => 'pub',
				'schema'   => 'publication',
				'data'     => ['title' => 'Besluit', 'themes' => ['{{object:theme-1.id}}']],
			],
			[
				'key'      => 'pub-2',
				'register' => 'pub',
				'schema'   => 'publication',
				'lookups'  => ['theme' => ['schema' => 'theme', 'where' => ['slug' => 'wonen']]],
				'data'     => ['title' => 'Notulen', 'theme' => '{{lookup:theme}}'],
			],
		]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function testAKeptPublicationPointsAtTheThemeWrittenAgainAfterAPartialRemove(): void {
		$publications             = $this->store->ids(register: 'pub', schema: 'publication');
		$oldTheme                 = $this->store->ids(register: 'pub', schema: 'theme')[0];
		$this->store->undeletable = $publications;

		$partial = $this->remover->remove(set: 'demo');
		$this->assertCount(2, $partial['failed'], 'the publications stay, the theme goes');
		$this->assertSame([], $this->store->ids(register: 'pub', schema: 'theme'));

		$this->store->undeletable = [];
		$report                   = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$newTheme = $this->store->ids(register: 'pub', schema: 'theme')[0];
		$this->assertNotSame($oldTheme, $newTheme);
		$this->assertSame($publications, $this->store->ids(register: 'pub', schema: 'publication'), 'the publications are kept, not written twice');
		$this->assertSame([$newTheme], $this->store->get(register: 'pub', schema: 'publication', id: $publications[0])['themes']);
		$this->assertSame($newTheme, $this->store->get(register: 'pub', schema: 'publication', id: $publications[1])['theme']);
		$this->assertSame('refreshed', $report['changes']['alpha/pub-1#references']);
		$this->assertSame('refreshed', $report['changes']['alpha/pub-2#references']);
		$this->assertSame(
			[['path' => 'themes', 'had' => true, 'old' => [$oldTheme], 'new' => [$newTheme], 'from' => 'themes']],
			$this->record->read(set: 'demo')['changes']['alpha/pub-1#references']['set'],
			'the refresh is recorded like a change, with what it replaced'
		);
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function testARefreshedSetInstallsAgainWithoutWritesAndRemovesWhole(): void {
		$this->store->undeletable = $this->store->ids(register: 'pub', schema: 'publication');
		$this->remover->remove(set: 'demo');
		$this->store->undeletable = [];
		$plan                     = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$updates = $this->store->updates;

		$again = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($again['ok'], (string) json_encode($again));
		$this->assertSame($updates, $this->store->updates, 'references that already hold their values are not written');
		$this->assertArrayNotHasKey('alpha/pub-1#references', $again['changes']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame([], $this->store->ids(register: 'pub', schema: 'publication'));
		$this->assertSame([], $this->store->ids(register: 'pub', schema: 'theme'));
		$this->assertSame([], $this->config, 'the record is gone');
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function testAnUntouchedSecondInstallRefreshesNothing(): void {
		$updates = $this->store->updates;

		$again = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($again['ok']);
		$this->assertSame($updates, $this->store->updates);
		$this->assertSame([], $this->record->read(set: 'demo')['changes']);
	}
}
