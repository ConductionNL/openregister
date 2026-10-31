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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function testAnUntouchedSecondInstallRefreshesNothing(): void {
		$updates = $this->store->updates;

		$again = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($again['ok']);
		$this->assertSame($updates, $this->store->updates);
		$this->assertSame([], $this->record->read(set: 'demo')['changes']);
	}

	/**
	 * A user who pointed a kept object's reference at something else keeps that edit: the next install leaves it and says so.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function testAUsersEditOfAKeptObjectsReferenceIsLeftAndNamed(): void {
		$publications = $this->store->ids(register: 'pub', schema: 'publication');
		$this->store->rows['pub publication'][$publications[0]]['themes'] = ['an-unrelated-id'];
		$updates = $this->store->updates;

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(['an-unrelated-id'], $this->store->get(register: 'pub', schema: 'publication', id: $publications[0])['themes']);
		$this->assertSame($updates, $this->store->updates, 'nothing is written over the edit');
		$this->assertArrayNotHasKey('alpha/pub-1#references', $report['changes']);
		$this->assertArrayNotHasKey('alpha/pub-1#references', $this->record->read(set: 'demo')['changes']);
		$this->assertSame(['lib/Settings/example-sets/demo.json: object "pub-1": field "themes" was changed after the install and is left as it is'], array_map(
			static fn (string $line): string => preg_replace('#^[^:]*/lib/#', 'lib/', $line),
			$report['left']
		));
		$this->assertTrue($report['ok'], 'an edit is not a failure');
	}

	/**
	 * A kept object whose record predates the recorded values is not refreshed over a value nobody can vouch for.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function testAReferenceTheRecordHoldsNoValueForIsLeft(): void {
		$record = $this->record->read(set: 'demo');
		foreach ($record['objects'] as $name => $held) {
			unset($record['objects'][$name]['refs']);
		}

		$this->record->write(set: 'demo', record: $record);
		$this->store->undeletable = $this->store->ids(register: 'pub', schema: 'publication');
		$this->remover->remove(set: 'demo');
		$this->store->undeletable = [];

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertCount(2, $report['left']);
		$this->assertArrayNotHasKey('alpha/pub-1#references', $report['changes']);
	}

	/**
	 * A field refreshed in an earlier run stays in the refresh record when a later run refreshes another field of
	 * the object, so the earlier field is not read as edited by somebody on the run after.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function testARefreshKeepsTheFieldsAnEarlierRunRefreshed(): void {
		$this->app(app: 'beta', fragment: ['objects' => [
			['key' => 'theme-2', 'register' => 'pub', 'schema' => 'theme', 'data' => ['slug' => 'werk', 'title' => 'Werk']],
			['key' => 'pub-3', 'register' => 'pub', 'schema' => 'publication', 'data' => ['title' => 'Twee', 'theme' => '{{object:theme-2.id}}', 'second' => '{{object:theme-2.id}}']],
		]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$theme = $this->store->ids(register: 'pub', schema: 'theme');
		$themeId = end($theme);
		$all = $this->store->ids(register: 'pub', schema: 'publication');
		$pubId = end($all);

		// Each field points at an id the install once wrote and the object since lost: the install must refresh it.
		foreach (['theme' => 'stale-1', 'second' => 'stale-2'] as $field => $stale) {
			$record = $this->record->read(set: 'demo');
			$record['objects']['beta/pub-3']['refs'][$field] = $stale;
			$this->record->write(set: 'demo', record: $record);
			$this->store->rows['pub publication'][$pubId][$field] = $stale;
			$report = $this->installer->install(plan: $plan, today: $this->today());
			$this->assertSame('refreshed', $report['changes']['beta/pub-3#references'], $field);
			$this->assertSame($themeId, $this->store->get(register: 'pub', schema: 'publication', id: $pubId)[$field]);
		}

		$paths = array_column($this->record->read(set: 'demo')['changes']['beta/pub-3#references']['set'], 'path');
		sort($paths);
		$this->assertSame(['second', 'theme'], $paths, 'both refreshes are in the record');

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame([], $again['left'], 'a field the install refreshed is not read as edited by somebody');
	}
}
