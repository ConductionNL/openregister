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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
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

		$this->assertCount(16, $plan['cast']['people']);
		$this->assertSame(['brp', 'kvk'], $plan['requires']);
		$this->assertSame(['vergunningen'], array_values(array_diff($plan['cast']['people']['marleen']['groups'], ['medewerkers'])));
		$this->assertSame('Teamleider', $plan['cast']['people']['marleen']['function']);
		$this->assertNotContains('admin', array_column($plan['cast']['groups'], 'gid'));
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
