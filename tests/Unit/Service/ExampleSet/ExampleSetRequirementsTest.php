<?php

/**
 * Tests for what the second round added to the example-set loader: required
 * registers, the refusal to touch Nextcloud's administrators, changes to
 * objects the set did not create, and fields kept as JSON text.
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
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetRequirementsTest extends ExampleSetTestCase {
	/**
	 * A register already on the instance is imported again when the shipped
	 * descriptor is a newer version, so a schema fixed in the descriptor
	 * reaches an instance that installed the set before.
	 *
	 * @return void
	 */
	public function testARegisterBehindItsDescriptorIsImportedAgain(): void {
		$this->store->offer(register: 'brp', schema: 'person');
		$this->registers->present['brp']    = true;
		$this->registers->importable['brp'] = true;
		$this->registers->behind['brp']     = true;
		$this->app(app: 'openregister', fragment: $this->castRequiringBrp());

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: ['brp' => 'imported'], actual: $report['registers']);
		$this->assertSame(expected: 1, actual: $this->registers->imports);
	}//end testARegisterBehindItsDescriptorIsImportedAgain()

	/**
	 * A cast file that requires the `brp` register and writes one person into it.
	 *
	 * @return array<string, mixed>
	 */
	private function castRequiringBrp(): array {
		$file             = $this->castFile(objects: [['key' => 'brp-sanne', 'register' => 'brp', 'schema' => 'person', 'data' => ['bsn' => '{{cast:sanne.bsn}}']]]);
		$file['requires'] = ['brp'];

		return $file;
	}

	public function testAMissingRequiredRegisterIsImportedBeforeTheCheckAndRemovedAfterwards(): void {
		$this->store->offer(register: 'brp', schema: 'person');
		$this->registers->importable['brp'] = true;
		$this->app(app: 'openregister', fragment: $this->castRequiringBrp());
		$plan = $this->catalogue->gather(set: 'demo');

		$check = $this->installer->check(plan: $plan, today: $this->today());
		$this->assertSame(['brp' => 'imported'], $check['registers']);
		$this->assertSame([], $check['refusals']);
		$this->assertSame(0, $this->store->creates, 'the check writes none of the set\'s objects');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok']);
		$this->assertSame(['brp' => 'kept'], $report['registers'], 'imported once, then already there');
		$this->assertSame(1, $this->registers->imports);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(1, $removed['removed']['register imports']);
		$this->assertSame([], $this->registers->jobs);
		$this->assertSame(['example_set_demo' => '{"registers":{"brp":[]}}'], $this->config, 'only the memory that the register\'s data was removed');
		$this->assertTrue($this->record->isEmpty(record: $this->record->read(set: 'demo')), 'which is not an install');
	}

	public function testARegisterTheSetImportedIsImportedAgainAfterARemove(): void {
		$this->store->offer(register: 'brp', schema: 'person');
		$this->registers->importable['brp'] = true;
		$this->app(app: 'openregister', fragment: $this->castRequiringBrp());
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$this->remover->remove(set: 'demo');
		$this->assertSame([], $this->registers->jobs, 'the data of the first import is gone');
		$this->assertTrue($this->registers->exists(slug: 'brp'), 'the register and its schemas stay');

		$second = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($second['ok'], json_encode($second));
		$this->assertSame(2, $this->registers->imports, 'the data is imported again, not skipped because the register exists');
		$this->assertCount(1, $this->registers->jobs);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(1, $removed['removed']['register imports']);
		$this->assertSame([], $this->registers->jobs);
	}

	public function testARegisterThatWasAlreadyThereIsNeitherImportedNorRemoved(): void {
		$this->store->offer(register: 'brp', schema: 'person');
		$this->registers->present['brp'] = true;
		$this->registers->jobs['someone-elses'] = 'brp';
		$this->app(app: 'openregister', fragment: $this->castRequiringBrp());

		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(0, $this->registers->imports);
		$this->assertSame(0, $removed['removed']['register imports']);
		$this->assertSame(['someone-elses' => 'brp'], $this->registers->jobs);
	}

	public function testARequiredRegisterThatCannotBeImportedRefusesTheSet(): void {
		$this->app(app: 'openregister', fragment: $this->castRequiringBrp());

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertStringContainsString('register "brp" is required and could not be imported', $report['refusals'][0]);
		$this->assertSame(0, $this->store->creates);
		$this->assertSame(0, $this->accounts->userCreates);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function adminGroups(): array {
		return [
			'cast group'     => ['cast'],
			'fragment group' => ['fragment'],
		];
	}

	/**
	 * @dataProvider adminGroups
	 */
	public function testNobodyIsAddedToNextcloudsAdministrators(string $where): void {
		$file = $this->castFile();
		if ($where === 'cast') {
			$file['cast']['groups'][0]['gid'] = 'admin';
		}

		$this->app(app: 'openregister', fragment: $file);
		if ($where === 'fragment') {
			$this->app(app: 'alpha', fragment: ['groups' => [['group' => 'Admin', 'members' => ['esra']]]]);
		}

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertStringContainsString('never adds anyone to Nextcloud\'s administrators', implode("\n", $report['refusals']));
		$this->assertSame([], $this->accounts->groups);
		$this->assertSame(0, $this->accounts->userCreates);
	}

	/**
	 * A fragment that adds a sign-in mode and its card to a portal it did not create.
	 *
	 * @return array<string, mixed>
	 */
	private function signInChange(): array {
		return [
			'changes' => [[
				'key'      => 'sign-in',
				'register' => 'site',
				'schema'   => 'portal',
				'where'    => ['slug' => 'demo'],
				'append'   => ['authentication.modes' => 'nextcloud'],
				'set'      => ['authentication.modeLabels.nextcloud' => ['title' => 'Voorbeeldinwoner', 'who' => '{{cast:sanne.uid}}']],
			]],
		];
	}

	public function testAChangeIsMadeOnceProvedAndRevertedExactly(): void {
		$this->store->offer(register: 'site', schema: 'portal');
		$portal = $this->store->seed(register: 'site', schema: 'portal', data: ['slug' => 'demo', 'title' => 'Demo', 'authentication' => ['modes' => ['password']]]);
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'site', fragment: $this->signInChange());
		$plan = $this->catalogue->gather(set: 'demo');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertSame(['site/sign-in' => 'created'], $report['changes']);
		$row = $this->store->get(register: 'site', schema: 'portal', id: $portal);
		$this->assertSame(['password', 'nextcloud'], $row['authentication']['modes']);
		$this->assertSame('sanne.devries', $row['authentication']['modeLabels']['nextcloud']['who']);

		$updates = $this->store->updates;
		$again   = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(['site/sign-in' => 'kept'], $again['changes']);
		$this->assertSame($updates, $this->store->updates, 'a second install changes nothing');

		// Somebody renames the portal in the meantime; remove must keep that.
		$this->store->rows['site portal'][$portal]['title'] = 'Renamed';
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(1, $removed['removed']['changes']);
		$this->assertSame(
			['id' => $portal, 'slug' => 'demo', 'title' => 'Renamed', 'authentication' => ['modes' => ['password']]],
			$this->store->get(register: 'site', schema: 'portal', id: $portal)
		);
	}

	public function testAPathSomebodyEditedAfterTheInstallIsLeftAndNamedOnRemove(): void {
		$this->store->offer(register: 'site', schema: 'portal');
		$portal = $this->store->seed(register: 'site', schema: 'portal', data: ['slug' => 'demo', 'title' => 'Demo', 'authentication' => ['modes' => ['password']]]);
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'site', fragment: $this->signInChange());
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		// A user rewrites the card the set wrote; the appended mode is still the set's.
		$this->store->rows['site portal'][$portal]['authentication']['modeLabels']['nextcloud'] = ['title' => 'Mijn eigen kaart'];
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame([], $removed['failed']);
		$this->assertSame(1, $removed['removed']['changes']);
		$this->assertSame(
			['change site/sign-in on site portal ('.$portal.'), path authentication.modeLabels.nextcloud, which somebody changed after the install'],
			$removed['left']
		);
		$row = $this->store->get(register: 'site', schema: 'portal', id: $portal);
		$this->assertSame(['title' => 'Mijn eigen kaart'], $row['authentication']['modeLabels']['nextcloud'], 'the user\'s value stays');
		$this->assertSame(['password'], $row['authentication']['modes'], 'what the set appended is still taken out');
	}

	public function testAChangeOnAnObjectThatCannotBeReadIsNotReportedAsReverted(): void {
		$this->store->offer(register: 'site', schema: 'portal');
		$portal = $this->store->seed(register: 'site', schema: 'portal', data: ['slug' => 'demo', 'title' => 'Demo', 'authentication' => ['modes' => ['password']]]);
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'site', fragment: $this->signInChange());
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		// A database that is down says nothing about the object: the change is still on it and still recorded.
		$this->store->unreadable = [$portal];
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(0, $removed['removed']['changes']);
		$this->assertCount(1, array_filter($removed['failed'], static fn (string $line): bool => str_starts_with($line, 'change site/sign-in')));
		$this->assertSame(['password', 'nextcloud'], $this->store->rows['site portal'][$portal]['authentication']['modes']);

		// Once it can be read again the next remove undoes it.
		$this->store->unreadable = [];
		$again = $this->remover->remove(set: 'demo');
		$this->assertSame(1, $again['removed']['changes']);
		$this->assertSame(['password'], $this->store->rows['site portal'][$portal]['authentication']['modes']);
	}

	public function testAChangeThatIsAlreadyThereIsNotRecordedAndSurvivesRemove(): void {
		$this->store->offer(register: 'site', schema: 'portal');
		$portal = $this->store->seed(
			register: 'site',
			schema: 'portal',
			data: ['slug' => 'demo', 'authentication' => ['modes' => ['nextcloud'], 'modeLabels' => ['nextcloud' => ['title' => 'Voorbeeldinwoner', 'who' => 'sanne.devries']]]]
		);
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'site', fragment: $this->signInChange());

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->remover->remove(set: 'demo');

		$this->assertSame(['site/sign-in' => 'kept'], $report['changes']);
		$this->assertSame(['nextcloud'], $this->store->get(register: 'site', schema: 'portal', id: $portal)['authentication']['modes']);
	}

	public function testAChangeWhoseWhereTwoObjectsAnswerRefusesTheCheckAndWritesNothing(): void {
		$this->store->offer(register: 'site', schema: 'portal');
		$first  = $this->store->seed(register: 'site', schema: 'portal', data: ['slug' => 'demo', 'authentication' => ['modes' => ['password']]]);
		$second = $this->store->seed(register: 'site', schema: 'portal', data: ['slug' => 'demo', 'authentication' => ['modes' => ['password']]]);
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'site', fragment: $this->signInChange());
		$plan = $this->catalogue->gather(set: 'demo');

		$check = $this->installer->check(plan: $plan, today: $this->today());
		$this->assertSame(
			['site/lib/Settings/example-sets/demo.json: change "sign-in" (site portal): 2 site portal rows match {"slug":"demo"}; a change needs exactly one to change'],
			$check['refusals']
		);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(1, $report['refusals']);
		$this->assertSame(0, $this->store->updates);
		$this->assertSame(['password'], $this->store->get(register: 'site', schema: 'portal', id: $first)['authentication']['modes']);
		$this->assertSame(['password'], $this->store->get(register: 'site', schema: 'portal', id: $second)['authentication']['modes']);
	}

	public function testAChangeWithoutItsObjectRefusesTheCheck(): void {
		$this->store->offer(register: 'site', schema: 'portal');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'site', fragment: $this->signInChange());

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(
			['site/lib/Settings/example-sets/demo.json: change "sign-in" (site portal): no site portal with {"slug":"demo"} to change'],
			$result['refusals']
		);
	}

	public function testAJsonFieldIsSentAsTextAndProvedAsAList(): void {
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(
			app: 'cases',
			fragment: ['objects' => [[
				'key'        => 'case-1',
				'register'   => 'cases',
				'schema'     => 'case',
				'jsonFields' => ['history'],
				'data'       => ['history' => [['at' => '{{date:-1}}']]],
			]]]
		);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertSame('[{"at":"2026-10-12"}]', $this->store->find(register: 'cases', schema: 'case', filters: [])[0]['history']);
	}
}
