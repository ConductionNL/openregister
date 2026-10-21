<?php

/**
 * Tests for `{{register:<slug>}}` and `{{schema:<register>/<schema>}}`.
 *
 * The live install of zuiddrecht wrote opencatalogi's catalog with
 * `registers: ["publication"]`; the register stores the resolved id, so the
 * proof read `[31]` back and named the field as not kept. A fragment now
 * names the register by slug and writes its id.
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
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetRegisterIdsTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'publication', schema: 'catalog');
		$this->store->registerIds['publication']             = 31;
		$this->store->schemaIds['publication/publication']   = 1113;
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * A catalog fragment.
	 *
	 * @param array<string, mixed> $data The catalog's data.
	 *
	 * @return void
	 */
	private function catalog(array $data): void {
		$this->app(app: 'catalogi', fragment: ['objects' => [['key' => 'catalogus', 'register' => 'publication', 'schema' => 'catalog', 'data' => $data]]]);
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function testARegisterAndASchemaAreWrittenAsTheirIdsAndProvedAsKept(): void {
		$this->catalog([
			'title'     => 'Zuiddrecht',
			'registers' => ['{{register:publication}}'],
			'schemas'   => ['{{schema:publication/publication}}'],
			'note'      => 'register {{register:publication}}',
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$catalog = $this->store->find(register: 'publication', schema: 'catalog', filters: [])[0];
		$this->assertSame([31], $catalog['registers'], 'a whole-value placeholder keeps the integer');
		$this->assertSame([1113], $catalog['schemas']);
		$this->assertSame('register 31', $catalog['note']);
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function testARegisterOrSchemaTheInstanceDoesNotHaveRefusesTheSet(): void {
		$this->catalog(['registers' => ['{{register:nope}}'], 'schemas' => ['{{schema:publication/nope}}']]);

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertCount(1, $result['refusals']);
		$this->assertStringContainsString('object "catalogus"', $result['refusals'][0]);
		$this->assertStringContainsString('cannot resolve {{register:nope}}, {{schema:publication/nope}}', $result['refusals'][0]);
		$this->assertSame(0, $this->store->creates);
	}

	/**
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	public function testARegisterTheSetImportsHasItsIdInTheCheck(): void {
		$this->store->registerExists      = fn (string $slug): bool => $this->registers->exists(slug: $slug);
		$this->store->registerIds['brp']  = 7;
		$this->registers->importable['brp'] = true;
		$this->registers->present['publication'] = true;
		$cast             = $this->castFile();
		$cast['requires'] = ['brp'];
		$this->app(app: 'openregister', fragment: $cast);
		$this->catalog(['registers' => ['{{register:brp}}', '{{register:publication}}']]);

		$check = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(['brp' => 'imported'], $check['registers']);
		$this->assertSame([], $check['refusals']);
	}
}
