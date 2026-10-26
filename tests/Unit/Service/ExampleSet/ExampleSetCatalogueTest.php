<?php

/**
 * Tests for ExampleSetCatalogue: discovery in enabled apps, the cast rule, and
 * the zuiddrecht file this repository ships.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetCatalogueTest extends ExampleSetTestCase {
	public function testGathersEnabledAppsInOrderAndNamesTheAbsentOnes(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['after' => ['beta', 'gamma'], 'objects' => []]);
		$this->app(app: 'beta', fragment: ['objects' => []]);
		$this->app(app: 'bystander');

		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(['openregister', 'beta', 'alpha'], array_column($plan['fragments'], 'app'));
		$this->assertSame(['gamma'], $plan['skipped']);
		$this->assertSame('alpha/lib/Settings/example-sets/demo.json', $plan['fragments'][2]['file']);
		$this->assertArrayHasKey('sanne', $plan['cast']['entries']);
		$this->assertArrayHasKey('shop', $plan['cast']['entries']);
	}

	public function testACastOutsideOpenRegisterRefusesTheSet(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['cast' => ['people' => []]]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('alpha/lib/Settings/example-sets/demo.json carries a cast');
		$this->catalogue->gather(set: 'demo');
	}

	public function testASetWithoutOpenRegistersCastDoesNotExist(): void {
		$this->app(app: 'openregister');
		$this->app(app: 'alpha', fragment: ['objects' => []]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('There is no example set "demo"');
		$this->catalogue->gather(set: 'demo');
	}

	public function testADuplicateObjectKeyRefusesTheSet(): void {
		$object = ['key' => 'one', 'register' => 'r', 'schema' => 's', 'data' => []];
		$this->app(app: 'openregister', fragment: $this->castFile(objects: [$object, $object]));

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('the key "one" is used twice');
		$this->catalogue->gather(set: 'demo');
	}

	/**
	 * A fragment's parts add their objects after the main file's, in file-name order, and list no set of their own.
	 *
	 * @return void
	 */
	public function testAFragmentsPartsAddTheirListsInFileNameOrder(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [['key' => 'main', 'register' => 'r', 'schema' => 's', 'data' => []]]]);
		$this->part(app: 'alpha', name: '20-later.json', part: ['objects' => [['key' => 'later', 'register' => 'r', 'schema' => 's', 'data' => []]]]);
		$this->part(
			app: 'alpha',
			name: '10-sooner.json',
			part: [
				'$comment' => 'The sooner part.',
				'objects'  => [['key' => 'sooner', 'register' => 'r', 'schema' => 's', 'data' => []]],
				'appConfig' => [['key' => 'counter', 'value' => '1']],
			]
		);

		$plan  = $this->catalogue->gather(set: 'demo');
		$alpha = $plan['fragments'][1];

		$this->assertSame(expected: ['main', 'sooner', 'later'], actual: array_column($alpha['objects'], 'key'));
		$this->assertSame(expected: ['demo' => ['alpha', 'openregister']], actual: $this->catalogue->sets());
	}

	/**
	 * A part that sets a value other than a list is refused, naming the part and the key.
	 *
	 * @return void
	 */
	public function testAPartThatSetsMoreThanAListIsRefused(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => []]);
		$this->part(app: 'alpha', name: 'extra.json', part: ['after' => 'beta']);

		$this->expectException(exception: ExampleSetException::class);
		$this->expectExceptionMessage(
			message: 'alpha/lib/Settings/example-sets/demo.d/extra.json: a part may only add lists; "after" belongs in the main file.'
		);
		$this->catalogue->gather(set: 'demo');
	}

	/**
	 * An object key used in the main file and again in a part refuses the set.
	 *
	 * @return void
	 */
	public function testAKeyRepeatedAcrossPartsRefusesTheSet(): void {
		$object = ['key' => 'one', 'register' => 'r', 'schema' => 's', 'data' => []];
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [$object]]);
		$this->part(app: 'alpha', name: 'again.json', part: ['objects' => [$object]]);

		$this->expectException(exception: ExampleSetException::class);
		$this->expectExceptionMessage(message: 'the key "one" is used twice');
		$this->catalogue->gather(set: 'demo');
	}

	/**
	 * Write one part of an app's fragment of the demo set.
	 *
	 * @param string               $app  The app id.
	 * @param string               $name The part's file name.
	 * @param array<string, mixed> $part The part.
	 *
	 * @return void
	 */
	private function part(string $app, string $name, array $part): void {
		$folder = $this->apps[$app].'/'.ExampleSetCatalogue::FOLDER.'/demo.d';
		@mkdir($folder, 0777, true);
		file_put_contents($folder.'/'.$name, (string) json_encode($part));
	}

	public function testAnUnsafeSetIdIsRefused(): void {
		$this->expectException(ExampleSetException::class);
		$this->catalogue->gather(set: '../etc');
	}

	public function testListsTheSetsAndTheirApps(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => []]);
		$this->app(app: 'alpha', set: 'other', fragment: ['objects' => []]);

		$this->assertSame(['demo' => ['alpha', 'openregister'], 'other' => ['alpha']], $this->catalogue->sets());
	}

	/**
	 * The shipped zuiddrecht file gathers, its BSNs pass the eleven test and
	 * every placeholder it uses resolves against its own cast.
	 */
	public function testTheShippedZuiddrechtSetIsWhole(): void {
		$this->apps['openregister'] = dirname(__DIR__, 4);
		$plan = $this->catalogue->gather(set: 'zuiddrecht');

		$this->assertCount(expectedCount: 41, haystack: $plan['cast']['people']);
		// DqOrganisaties: 23 organisations in Zuiddrecht, 8 associations, 6 foundations, 9 companies, each with its own made-up KvK number.
		$forms = array_count_values(array_column($plan['cast']['organisations'], 'legalForm'));
		$companies = ($forms['Besloten Vennootschap'] + $forms['Eenmanszaak']);
		$this->assertSame(expected: [8, 6, 9], actual: [$forms['Vereniging'], $forms['Stichting'], $companies]);
		$kvk = array_column($plan['cast']['organisations'], 'kvk');
		$this->assertSame(expected: $kvk, actual: array_values(array_unique($kvk)));
		$this->assertSame(expected: [], actual: array_values(array_filter($kvk, static fn (string $n): bool => preg_match('/^00001\d{3}$/', $n) !== 1)));
		// DqOverdragen's team picker: Juridische zaken with six members, Juridische zaken sociaal domein with four.
		$members = static fn (string $group): int => count(
			array_filter($plan['cast']['people'], static fn (array $person): bool => in_array($group, ($person['groups'] ?? []), true))
		);
		$this->assertSame(expected: [6, 4], actual: [$members('juridische-zaken'), $members('juridische-zaken-sociaal-domein')]);
		// Buurtvereniging Oost and its chair, whom DqOrganisatie draws.
		$this->assertSame(expected: 'buurtoost', actual: $plan['cast']['people']['marja']['organisation']);
		// The parties on the Lindelaan Woo case: Van Wijk's representative and the residents' association.
		$this->assertSame(expected: 'vanwijk', actual: $plan['cast']['people']['jvanwijk']['organisation']);
		// The contacts and companies the dossiq list and new-case boards name, and the second tenant with its contact.
		$this->assertSame(
			expected: ['Pieter Bos', 'Henk Visser', 'Marieke Visser'],
			actual: [$plan['cast']['people']['pieterbos']['displayName'], $plan['cast']['people']['henkvisser']['displayName'], $plan['cast']['people']['marieke']['displayName']]
		);
		$this->assertSame(expected: 'Beukenlaan', actual: $plan['cast']['people']['henkvisser']['address']['street']);
		// Sanne's first names as the BRP holds them, which DqContact, PqKlant and PqBriefOpstellen draw
		// ("Sanne Johanna"); her given name stays "Sanne", the name the portal greets her by, and her
		// initials stay "S.", which the case lists draw as "S. de Vries".
		$sanne = $plan['cast']['people']['sanne'];
		$this->assertSame(expected: ['Sanne Johanna', 'Sanne'], actual: [$sanne['firstNames'], $sanne['givenName']]);
		$brp = array_column($plan['fragments'][0]['objects'], 'data', 'key')['brp-sanne'];
		$this->assertSame(expected: ['{{cast:sanne.firstNames}}', 'S.'], actual: [$brp['voornamen'], $brp['voorletters']]);
		// The handler the dossiq boards draw as "u", who owns a saved view; his one team is Woo en informatie, so the
		// team queue (DqTeamwachtrij) is that team's.
		$pieter = $plan['cast']['people']['pieter'];
		$this->assertSame(
			expected: ['pieter', 'Pieter Jansen', ['woo-en-informatie']],
			actual: [$pieter['uid'], $pieter['displayName'], array_values(array_diff($pieter['groups'], ['medewerkers']))]
		);
		// The jeugdteam's regisseur of the jeugdwet example case.
		$this->assertSame(['fatma.kaya', ['sociaal-domein']], [$plan['cast']['people']['fatma']['uid'], array_values(array_diff($plan['cast']['people']['fatma']['groups'], ['medewerkers']))]);
		$this->assertSame(
			expected: ['brp' => ['mock' => true], 'kvk' => ['mock' => true], 'destruction-lists' => ['mock' => false], 'public-space' => ['mock' => true]],
			actual: $plan['requires']
		);
		// Dossiq's archival review widget reads the destruction lists in the register the archival setting names.
		$this->assertSame(
			expected: [['key' => 'destruction-lists', 'data' => ['destructionListRegister' => '{{register:destruction-lists}}', 'destructionListSchema' => '{{schema:destruction-lists/destructionList}}']]],
			actual: $plan['fragments'][0]['archival']
		);
		// The colleague at Mobiliteit en verkeer the dossiq case boards ask for the Lindelaan documents.
		$this->assertSame(
			expected: ['lars.dekoning', 'Lars de Koning', ['mobiliteit-en-verkeer']],
			actual: [$plan['cast']['people']['lars']['uid'], $plan['cast']['people']['lars']['displayName'], array_values(array_diff($plan['cast']['people']['lars']['groups'], ['medewerkers']))]
		);
		// DqTeamwachtrij / DqAfdelingen: Lars is in a meeting until two.
		$this->assertSame(expected: ['status' => 'busy', 'message' => 'In overleg tot 14:00'], actual: $plan['cast']['people']['lars']['status']);
		// Buurtvereniging Oost's secretary, a contact person with no BSN and no account (dossiq DqOrganisatie).
		$kees = $plan['cast']['people']['keesdewit'];
		$this->assertSame(expected: ['Kees de Wit', 'buurtoost', 'kees.dewit@zuiddrecht.example'], actual: [$kees['displayName'], $kees['organisation'], $kees['email']]);
		$this->assertArrayNotHasKey(key: 'bsn', array: $kees);
		$this->assertArrayNotHasKey(key: 'uid', array: $kees);
		$this->assertSame(['vergunningen'], array_values(array_diff($plan['cast']['people']['marleen']['groups'], ['medewerkers'])));
		$this->assertSame('Teamleider', $plan['cast']['people']['marleen']['function']);
		// The Woo team of six the dossiq boards name, led by Anouk Bakker; Tim Mulder is away today.
		$woo = array_keys(array_filter($plan['cast']['people'], static fn (array $p): bool => in_array('woo-en-informatie', ($p['groups'] ?? []), true)));
		$this->assertSame(expected: ['thomas', 'anouk', 'pieter', 'hannabos', 'timmulder', 'fatmayildiz'], actual: $woo);
		$absence = $plan['cast']['people']['timmulder']['absence'];
		$this->assertSame(expected: [0, 1], actual: [$absence['fromDay'], $absence['untilDay']]);
		$this->assertSame(expected: ['Teamleider', 'Woo en informatie'], actual: [$plan['cast']['people']['anouk']['function'], $plan['cast']['people']['anouk']['department']]);
		// The dossiq header reads the profile role: Pieter is the Woo-coördinator there, as on the boards.
		$this->assertSame(expected: ['Woo-coördinator', ['Woo-coördinator']], actual: [$plan['cast']['people']['pieter']['function'], $plan['cast']['people']['pieter']['roles']]);
		// The ketenpartner DqZaakSamenwerken shares a case with: an organisation of type partner.
		$partner = array_values(array_filter($plan['organisations'], static fn (array $o): bool => $o['slug'] === 'odzhz'))[0];
		$this->assertSame(expected: ['Omgevingsdienst Zuid-Holland Zuid', 'partner'], actual: [$partner['name'], $partner['type']]);
		// Gemeente Waterland, the second tenant: its quota, its administrator and Pieter, who reads it on DqTenant.
		$waterland = array_values(array_filter($plan['organisations'], static fn (array $o): bool => $o['slug'] === 'waterland'))[0];
		$this->assertSame(expected: ['marieke.visser', 'pieter'], actual: $waterland['members']);
		$this->assertSame(expected: 107374182400, actual: $waterland['quota']['storage']);
		// DqTenant's quota: 5,000 cases and 50 users.
		$this->assertSame(expected: [5000, 'case', 50], actual: [$waterland['quota']['objects'], $waterland['quota']['objectsSchema'], $waterland['quota']['users']]);
		$this->assertNotContains('admin', array_column($plan['cast']['groups'], 'gid'));
		// DqAfdelingen: Pieter, the boards' functional administrator, is sub-admin of every cast group, so he sees the department tree.
		$this->assertSame(expected: [['pieter']], actual: array_values(array_unique(array_column($plan['cast']['groups'], 'subAdmins'), SORT_REGULAR)));
		$this->assertNotContains('noor', array_keys(array_filter($plan['cast']['people'], static fn (array $p): bool => in_array('admin', ($p['groups'] ?? []), true))));
		$apps = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/lib/Settings/example-sets/zuiddrecht.json'), true)['apps'];
		$this->assertCount(18, $apps);
		foreach (['learniq', 'larpinq', 'zaakafhandelapp'] as $out) {
			$this->assertNotContains($out, $apps);
		}

		foreach ($plan['cast']['people'] as $key => $person) {
			$this->assertStringEndsWith('@zuiddrecht.example', $person['email'], $key);
			if (isset($person['bsn']) === true) {
				$this->assertTrue(ExampleSetPeople::passesElevenTest(bsn: $person['bsn']), $key.' '.$person['bsn']);
				$this->assertStringStartsWith('99999', $person['bsn'], $key);
			}
		}

		$values = new ExampleSetValues();
		foreach ($plan['fragments'][0]['objects'] as $object) {
			$gaps = [];
			$values->fill(value: $object['data'], context: ['cast' => $plan['cast']['entries'], 'objects' => [], 'today' => $this->today()], gaps: $gaps);
			$this->assertSame([], $gaps, $object['key']);
		}
	}
}
