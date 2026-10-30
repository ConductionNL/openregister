<?php

/**
 * Tests for what round three added to the example-set loader, through the
 * installer and remover: files on an object, service accounts, a fragment's
 * own app config, the instance's address, a change to an object written
 * earlier in the same run, OpenRegister organisations, and contactsUid.
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

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Db\Organisation
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetFragmentPartsTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'pub', schema: 'publication');
		$this->store->offer(register: 'cases', schema: 'case');
		$this->store->offer(register: 'cases', schema: 'result');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * A fragment of app `alpha` with a file beside it.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return void
	 */
	private function alpha(array $fragment): void {
		$this->app(app: 'alpha', fragment: $fragment);
		$folder = $this->apps['alpha'].'/'.ExampleSetCatalogue::FOLDER.'/demo/pub-1';
		@mkdir($folder, 0777, true);
		file_put_contents($folder.'/01-besluit.pdf', '%PDF-1.4 example');
	}

	public function testFilesAreAttachedSharedRecordedAndRemovedWithTheirObject(): void {
		$this->alpha(['objects' => [[
			'key'      => 'pub-1',
			'register' => 'pub',
			'schema'   => 'publication',
			'data'     => ['title' => 'Besluit'],
			'files'    => [['path' => 'demo/pub-1/01-besluit.pdf', 'title' => 'Besluit', 'share' => true]],
		]]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertSame(1, $report['files']);
		$objectId = $this->store->ids(register: 'pub', schema: 'publication')[0];
		$this->assertSame([['name' => '01-besluit.pdf', 'shared' => true, 'content' => '%PDF-1.4 example']], array_values($this->files[$objectId]));
		$this->assertSame(1, $this->installer->install(plan: $plan, today: $this->today())['ok'] === true ? count($this->files[$objectId]) : -1, 'a second install attaches nothing');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(1, $removed['removed']['files']);
		$this->assertSame([], $this->files[$objectId]);
		$this->assertSame([], $this->store->ids(register: 'pub', schema: 'publication'));
	}

	/**
	 * A file an app moved out of its object's folder still counts as arrived: it keeps its id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function testAFileMovedToAnotherFolderStillArrived(): void {
		$this->alpha(fragment: ['objects' => [[
			'key'      => 'pub-1',
			'register' => 'pub',
			'schema'   => 'publication',
			'data'     => ['title' => 'Besluit'],
			'files'    => [['path' => 'demo/pub-1/01-besluit.pdf', 'title' => 'Besluit']],
		]]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$objectId = $this->store->ids(register: 'pub', schema: 'publication')[0];
		$this->files['case-folder'] = $this->files[$objectId];
		$this->files[$objectId]     = [];

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(expected: [], actual: $report['missing']);
		$this->assertSame(expected: [], actual: $this->files[$objectId], message: 'a moved file is not attached again');
	}//end testAFileMovedToAnotherFolderStillArrived()

	/**
	 * Files declared after an object was written are attached on the next install, once.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-later-install-attaches-the-files-an-object-gained
	 */
	public function testFilesDeclaredLaterAreAttachedToTheKeptObjectOnce(): void {
		$object = ['key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => ['title' => 'Besluit']];
		$this->alpha(fragment: ['objects' => [$object]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$objectId = $this->store->ids(register: 'pub', schema: 'publication')[0];
		$this->assertSame(expected: [], actual: array_values($this->files[$objectId] ?? []));

		$object['files'] = [['path' => 'demo/pub-1/01-besluit.pdf', 'title' => 'Besluit']];
		$this->alpha(fragment: ['objects' => [$object]]);
		$plan   = $this->catalogue->gather(set: 'demo');
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: 1, actual: $report['files']);
		$ids = $this->store->ids(register: 'pub', schema: 'publication');
		$this->assertSame(expected: [$objectId], actual: $ids, message: 'the object is kept, not written again');
		$this->assertCount(expectedCount: 1, haystack: $this->files[$objectId]);

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(expectedCount: 1, haystack: $this->files[$objectId], message: 'a third install attaches nothing');
	}//end testFilesDeclaredLaterAreAttachedToTheKeptObjectOnce()

	public function testAFileThatIsNotThereRefusesTheCheck(): void {
		$this->alpha(['objects' => [[
			'key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => ['title' => 'Besluit'],
			'files' => [['path' => 'demo/pub-1/missing.pdf']],
		]]]);

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(
			['alpha/lib/Settings/example-sets/demo.json: object "pub-1" (pub publication): file "demo/pub-1/missing.pdf" is not a readable file inside the fragment\'s folder'],
			$result['refusals']
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafePaths(): array {
		return [
			'parent'   => ['../openregister/secret.pdf'],
			'nested'   => ['demo/../../x.pdf'],
			'absolute' => ['/etc/passwd'],
			'backslash' => ['demo\\..\\x.pdf'],
			'nul'       => ["demo/x.pdf\0.txt"],
		];
	}

	/**
	 * @dataProvider unsafePaths
	 */
	public function testAPathOutsideTheFragmentsFolderRefusesTheSet(string $path): void {
		$this->alpha(['objects' => [['key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => [], 'files' => [['path' => $path]]]]]);

		$this->expectException(ExampleSetException::class);
		$this->catalogue->gather(set: 'demo');
	}

	/**
	 * A fragment with a service account, its group, and app config naming it.
	 *
	 * @return array<string, mixed>
	 */
	private function serviceFragment(): array {
		return [
			'serviceAccounts' => [['key' => 'background', 'uid' => 'demo_background', 'displayName' => 'Achtergrond', 'groups' => ['alpha-background-service']]],
			'appConfig'       => [
				['key' => 'background_service_account', 'value' => '{{account:background.uid}}'],
				['key' => 'catalog_slug', 'value' => 'demo'],
				['key' => 'groups', 'value' => '{{cast:staff.gid}}'],
			],
		];
	}

	/**
	 * A declared service account that turns out to exist when the install creates it (another writer made it in
	 * between) is adopted into the record, so the set uses it and remove leaves it.
	 *
	 * @return void
	 */
	public function testAServiceAccountThatAppearedIsAdoptedNotDropped(): void {
		$this->alpha(fragment: $this->serviceFragment());
		$this->accounts->unseenUsers = ['demo_background'];

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$dropped = array_filter($report['dropped'], static fn (string $line): bool => str_contains($line, 'demo_background'));
		$this->assertSame(expected: [], actual: array_values($dropped));
		$this->assertSame(expected: 'adopted', actual: $report['users']['demo_background']);
		$this->remover->remove(set: 'demo');
		$this->assertArrayHasKey(key: 'demo_background', array: $this->accounts->users, message: 'remove leaves an adopted account');
	}//end testAServiceAccountThatAppearedIsAdoptedNotDropped()

	public function testAServiceAccountIsMadeWithoutAPasswordAnyoneSeesAndJoinsItsGroup(): void {
		$this->alpha($this->serviceFragment());

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertSame('created', $report['users']['demo_background']);
		$this->assertArrayNotHasKey('demo_background', $report['passwords']);
		$this->assertSame(['demo_background'], $this->accounts->groups['alpha-background-service']);
		$this->assertStringNotContainsString($this->accounts->users['demo_background']['password'], (string) json_encode($report));
		$this->assertStringNotContainsString($this->accounts->users['demo_background']['password'], (string) json_encode($this->config));

		$this->remover->remove(set: 'demo');
		$this->assertArrayNotHasKey('demo_background', $this->accounts->users);
		$this->assertArrayNotHasKey('alpha-background-service', $this->accounts->groups);
	}

	public function testAppConfigIsWrittenForTheFragmentsOwnAppAndRestoredExactly(): void {
		$this->appValues['alpha'] = ['catalog_slug' => 'publication'];
		$this->alpha($this->serviceFragment());
		$plan = $this->catalogue->gather(set: 'demo');

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(['catalog_slug' => 'demo', 'background_service_account' => 'demo_background', 'groups' => 'demo-staff'], $this->appValues['alpha']);
		$this->assertSame(['alpha/background_service_account' => 'created', 'alpha/catalog_slug' => 'created', 'alpha/groups' => 'created'], $report['appConfig']);
		$this->assertSame('kept', $this->installer->install(plan: $plan, today: $this->today())['appConfig']['alpha/catalog_slug']);

		// An administrator changes one key after the install; remove must leave it.
		$this->appValues['alpha']['groups'] = 'their-own';
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(['catalog_slug' => 'publication', 'groups' => 'their-own'], $this->appValues['alpha']);
		$this->assertContains('app config alpha groups, which somebody changed after the install', $removed['left']);
	}

	/**
	 * An entry may name its type: an integer key (a sequence counter an app reads with getValueInt) is written as
	 * an integer, kept on a second install and put back on remove; a value that is no integer refuses the set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-app-config-entry-may-name-its-type
	 */
	public function testAnIntegerAppConfigEntryIsWrittenAsAnInteger(): void {
		$this->appValues['alpha'] = ['counter' => '7'];
		$this->appTypes['alpha']  = ['counter' => 'int'];
		$this->alpha(['appConfig' => [['key' => 'counter', 'value' => '402', 'type' => 'int']]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame('created', $report['appConfig']['alpha/counter'], (string) json_encode($report));
		$this->assertSame(['402', 'int'], [$this->appValues['alpha']['counter'], $this->appTypes['alpha']['counter']]);
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);

		$this->remover->remove(set: 'demo');
		$this->assertSame('7', $this->appValues['alpha']['counter']);

		$this->alpha(['appConfig' => [['key' => 'counter', 'value' => 'veel', 'type' => 'int']]]);
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('is an integer');
		$this->catalogue->gather(set: 'demo');
	}

	public function testAppConfigForAnotherAppIsRefused(): void {
		$this->alpha(['appConfig' => [['app' => 'beta', 'key' => 'x', 'value' => 'y']]]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('a fragment sets only its own app\'s config');
		$this->catalogue->gather(set: 'demo');
	}

	public function testASensitiveKeyIsRefusedUnlessTheSetGeneratesIt(): void {
		$this->appValues['alpha'] = ['secret' => 'theirs'];
		$this->sensitive['alpha'] = ['secret' => true];
		$this->alpha(['appConfig' => [['key' => 'secret', 'value' => 'from-a-file']]]);

		$check = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertStringContainsString('app config "secret": app config alpha secret is kept as sensitive', $check['refusals'][0]);
		$this->assertSame('theirs', $this->appValues['alpha']['secret']);

		$this->appValues = [];
		$this->sensitive = [];
		$this->alpha(['appConfig' => [['key' => 'secret', 'generate' => true]]]);
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertTrue($this->sensitive['alpha']['secret']);
		$this->assertStringNotContainsString($this->appValues['alpha']['secret'], (string) json_encode($report).json_encode($this->config));
		$this->assertStringNotContainsString($this->appValues['alpha']['secret'], $this->config['example_set_demo'], 'the record holds a fingerprint, never the value');
		$this->remover->remove(set: 'demo');
		$this->assertArrayNotHasKey('secret', $this->appValues['alpha']);
	}

	public function testAGeneratedKeyAnAdministratorReplacedStaysAndIsNamed(): void {
		$this->alpha(['appConfig' => [['key' => 'secret', 'generate' => true]]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		// The administrator puts a real token there after the install.
		$this->appValues['alpha']['secret'] = 'their-real-api-token';
		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame('their-real-api-token', $this->appValues['alpha']['secret']);
		$this->assertContains('app config alpha secret, which somebody changed after the install', $removed['left']);
		$this->assertSame([], $removed['failed']);
	}

	public function testAGeneratedKeyThatHeldAnEmptyValueGetsItBack(): void {
		$this->appValues['alpha'] = ['secret' => ''];
		$this->alpha(['appConfig' => [['key' => 'secret', 'generate' => true]]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertNotSame('', $this->appValues['alpha']['secret']);

		$this->remover->remove(set: 'demo');

		$this->assertArrayHasKey('secret', $this->appValues['alpha'], 'a key that was there stays there');
		$this->assertSame('', $this->appValues['alpha']['secret']);
	}

	public function testTheInstanceAddressFillsAPlaceholderAndIsNeverEmpty(): void {
		$this->alpha(['objects' => [['key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => ['url' => '{{instance:baseUrl}}/apps/x']]]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertSame('https://cloud.example.test/apps/x', $this->store->find(register: 'pub', schema: 'publication', filters: [])[0]['url']);

		$this->remover->remove(set: 'demo');
		$this->baseUrl = '';
		$check         = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertStringContainsString('cannot resolve {{instance:baseUrl}}', $check['refusals'][0]);
	}

	public function testAChangeSetsALinkOnAnObjectWrittenEarlierInTheRun(): void {
		$this->alpha([
			'objects' => [
				['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']],
				['key' => 'result-1', 'register' => 'cases', 'schema' => 'result', 'data' => ['case' => '{{object:case-1.id}}']],
			],
			'changes' => [['key' => 'case-1-result', 'register' => 'cases', 'schema' => 'case', 'object' => 'case-1', 'set' => ['result' => '{{object:result-1.id}}']]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], json_encode($report));
		$case   = $this->store->find(register: 'cases', schema: 'case', filters: [])[0];
		$result = $this->store->find(register: 'cases', schema: 'result', filters: [])[0];
		$this->assertSame($result['id'], $case['result']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame([], $this->store->ids(register: 'cases', schema: 'case'));
		$this->assertSame([], $this->config);
	}

	/**
	 * A value the store keeps in another form is still kept: a moment stored in UTC, and an empty text stored as
	 * null (:8080, 24 Oct: six decidiq agendaPublishedAt changes and three opencatalogi status messages reported as
	 * written but not kept).
	 *
	 * @return void
	 */
	public function testAValueStoredInAnotherFormIsNotReportedLost(): void {
		$this->store->storedAs['cases case'] = [
			'publishedAt' => static fn (mixed $v): string => (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('UTC'))->format('c'),
			'message'     => static function (mixed $v): mixed {
				if ($v === '') {
					return null;
				}

				return $v;
			},
		];
		$this->alpha(fragment: [
			'objects' => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak', 'message' => '']]],
			'changes' => [
				[
					'key' => 'case-1-published', 'register' => 'cases', 'schema' => 'case', 'object' => 'case-1',
					'set' => ['publishedAt' => '{{datetime:-3@tue 09:00}}'],
				],
			],
		]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: [], actual: $report['lost']);
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
	}

	public function testAChangeToAnObjectNotWrittenEarlierIsRefused(): void {
		$this->alpha(['changes' => [['key' => 'c', 'register' => 'cases', 'schema' => 'case', 'object' => 'nope', 'set' => ['x' => 1]]]]);

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertStringContainsString('object "nope" is not written earlier in this fragment', $result['refusals'][0]);
	}

	public function testTheCastsOrganisationIsCreatedOnceFoundByPlaceholderAndRemoved(): void {
		$existing = new Organisation();
		$existing->setUuid('org-existing');
		$existing->setName('Bestaand');
		$existing->setSlug('bestaand');
		$this->organisations['org-existing'] = $existing;
		$file = $this->castFile();
		$file['cast']['openregisterOrganisations'] = [
			['key' => 'gemeente', 'name' => 'Gemeente Demo', 'slug' => 'demo'],
			['key' => 'bestaand', 'name' => 'Bestaand', 'slug' => 'bestaand'],
		];
		$this->app(app: 'openregister', fragment: $file);
		$this->alpha(['objects' => [['key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => [
			'organization' => '{{organisation:demo}}',
			'other'        => '{{organisation:Bestaand}}',
		]]]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertCount(2, $this->organisations, 'created once');
		$row = $this->store->find(register: 'pub', schema: 'publication', filters: [])[0];
		$this->assertSame('org-2', $row['organization']);
		$this->assertSame('org-existing', $row['other']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(1, $removed['removed']['organisations']);
		$this->assertSame(['org-existing'], array_keys($this->organisations));
	}

	public function testAnOrganisationWithAMemberTheSetDidNotAddSurvivesRemoveAndIsNamed(): void {
		$file = $this->castFile();
		$file['cast']['openregisterOrganisations'] = [['key' => 'gemeente', 'name' => 'Gemeente Demo', 'slug' => 'demo']];
		$this->app(app: 'openregister', fragment: $file);
		$this->alpha(['objects' => [['key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => ['organization' => '{{organisation:demo}}']]]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->accounts->users['real.person'] = ['displayName' => 'Real Person', 'email' => '', 'password' => 'x'];
		$this->organisations['org-1']->setUsers(['sanne.devries', 'real.person']);

		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(0, $removed['removed']['organisations']);
		$this->assertCount(1, $this->organisations, 'a tenant somebody joined is not deleted');
		$this->assertSame(['OpenRegister organisation org-1, which has members the set did not add'], $removed['left']);
		$this->assertSame([], $removed['failed']);
		$this->assertSame(['org-1'], $this->record->read(set: 'demo')['organisations'], 'it stays in the record');

		unset($this->accounts->users['real.person']);
		$again = $this->remover->remove(set: 'demo');
		$this->assertSame(1, $again['removed']['organisations'], 'once only its own accounts and leftovers of deleted ones are members it goes');
		$this->assertSame([], $this->organisations);
	}

	/**
	 * A declared organisation is created with its quota and the cast members who belong to it, and still goes on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
	 */
	public function testAnOrganisationIsCreatedWithItsQuotaAndMembers(): void {
		$file = $this->castFile();
		$file['cast']['openregisterOrganisations'] = [[
			'key' => 'gemeente', 'name' => 'Gemeente Demo', 'slug' => 'demo',
			'quota' => ['storage' => 107374182400, 'requests' => 5000], 'members' => ['sanne'],
		]];
		$this->app(app: 'openregister', fragment: $file);
		$this->alpha(fragment: ['objects' => [['key' => 'pub-1', 'register' => 'pub', 'schema' => 'publication', 'data' => ['organization' => '{{organisation:demo}}']]]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$organisation = array_values($this->organisations)[0];
		$this->assertSame(expected: 107374182400, actual: $organisation->getStorageQuota());
		$this->assertSame(expected: 5000, actual: $organisation->getRequestQuota());
		$this->assertSame(expected: ['sanne.devries'], actual: $organisation->getUserIds());

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: 1, actual: $removed['removed']['organisations'], message: 'its members are the set\'s own accounts');
	}//end testAnOrganisationIsCreatedWithItsQuotaAndMembers()

	/**
	 * A quota that is not a whole number, or a member the cast does not hold, refuses the set.
	 *
	 * @return void
	 */
	public function testAFaultyQuotaOrMemberRefusesTheSet(): void {
		foreach ([['quota' => ['storage' => 'veel']], ['members' => ['nobody']], ['quota' => ['seats' => 3]]] as $extra) {
			$file = $this->castFile();
			$file['cast']['openregisterOrganisations'] = [['key' => 'gemeente', 'name' => 'Gemeente Demo', 'slug' => 'demo'] + $extra];
			$this->app(app: 'openregister', fragment: $file);
			try {
				$this->catalogue->gather(set: 'demo');
				$this->fail(message: 'refused: '.json_encode($extra));
			} catch (ExampleSetException $e) {
				$this->assertStringContainsString(needle: 'OpenRegister organisation', haystack: $e->getMessage());
			}
		}
	}//end testAFaultyQuotaOrMemberRefusesTheSet()

	public function testEveryCastMemberHasAStableContactsUid(): void {
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame('example-set-demo-sanne', $plan['cast']['entries']['sanne']['contactsUid']);
		$this->assertSame('example-set-demo-shop', $plan['cast']['entries']['shop']['contactsUid']);
		$this->assertArrayNotHasKey('contactsUid', $plan['cast']['entries']['staff']);
	}
}
