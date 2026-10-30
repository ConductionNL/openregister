<?php

/**
 * OpenRegister's fragment of a set may name the register and schema its
 * destruction lists live in: only those keys of the `archival` setting are
 * written, every other key stays, and remove puts back what was there.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-archival-setting/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetArchivalTest extends ExampleSetTestCase {
	/**
	 * The destruction-list register and schema, with ids.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->registerIds['destruction-lists']                = 41;
		$this->store->schemaIds['destruction-lists/destructionList'] = 42;
	}

	/**
	 * The cast file with an `archival` part.
	 *
	 * @param array<string, mixed> $setting The part's fields.
	 *
	 * @return void
	 */
	private function castWithArchival(array $setting): void {
		$this->app(app: 'openregister', fragment: ($this->castFile() + ['archival' => [['key' => 'destruction-lists', 'data' => $setting]]]));
	}//end castWithArchival()

	/**
	 * The register and schema are merged into the setting by id, the other keys
	 * stay, and remove puts back the values they replaced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-archival-setting/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function testTheRegisterAndSchemaAreMergedAndPutBackOnRemove(): void {
		$this->appValues['openregister']['archival'] = (string) json_encode(['notificationLeadDays' => 14, 'destructionListRegister' => 7]);
		$this->castWithArchival(setting: [
			'destructionListRegister' => '{{register:destruction-lists}}',
			'destructionListSchema'   => '{{schema:destruction-lists/destructionList}}',
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(
			expected: ['notificationLeadDays' => 14, 'destructionListRegister' => 7],
			actual: json_decode($this->appValues['openregister']['archival'], true),
			message: 'the check writes nothing'
		);

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['openregister/destruction-lists' => 'created'], actual: $report['archival']);
		$this->assertSame(
			expected: ['notificationLeadDays' => 14, 'destructionListRegister' => 41, 'destructionListSchema' => 42],
			actual: json_decode($this->appValues['openregister']['archival'], true)
		);

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(expected: ['openregister/destruction-lists' => 'kept'], actual: $again['archival']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(
			expected: ['notificationLeadDays' => 14, 'destructionListRegister' => 7],
			actual: json_decode($this->appValues['openregister']['archival'], true)
		);
	}//end testTheRegisterAndSchemaAreMergedAndPutBackOnRemove()

	/**
	 * A key an administrator changed after the install stays on remove; the
	 * setting the set made from nothing is taken away whole.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-archival-setting/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function testRemoveLeavesWhatAnAdministratorChanged(): void {
		$this->castWithArchival(setting: [
			'destructionListRegister' => '{{register:destruction-lists}}',
			'destructionListSchema'   => '{{schema:destruction-lists/destructionList}}',
		]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue(condition: $this->installer->install(plan: $plan, today: $this->today())['ok']);

		$setting = json_decode($this->appValues['openregister']['archival'], true);
		$setting['destructionListSchema'] = 99;
		$this->appValues['openregister']['archival'] = (string) json_encode($setting);

		$this->assertSame(expected: [], actual: $this->remover->remove(set: 'demo')['failed']);
		$this->assertSame(expected: ['destructionListSchema' => 99], actual: json_decode($this->appValues['openregister']['archival'], true));

		$this->appValues = [];
		$this->assertTrue(condition: $this->installer->install(plan: $plan, today: $this->today())['ok']);
		$this->remover->remove(set: 'demo');
		$this->assertArrayNotHasKey(key: 'archival', array: ($this->appValues['openregister'] ?? []), message: 'nothing was there, nothing stays');
	}//end testRemoveLeavesWhatAnAdministratorChanged()

	/**
	 * Any other archival key, and a register the instance lacks, refuse the set before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-archival-setting/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function testAnUnknownKeyOrAMissingRegisterIsRefused(): void {
		$this->castWithArchival(setting: ['destructionListRegister' => '{{register:destruction-lists}}', 'destructionBatchSize' => 5]);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'destructionBatchSize', haystack: $refusals[0]);

		$this->castWithArchival(setting: ['destructionListRegister' => '{{register:nowhere}}']);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: 'cannot resolve', haystack: $refusals[0]);
		$this->assertArrayNotHasKey(key: 'archival', array: ($this->appValues['openregister'] ?? []));
	}//end testAnUnknownKeyOrAMissingRegisterIsRefused()

	/**
	 * Only OpenRegister's own fragment may write its archival setting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-archival-setting/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	public function testAnotherAppsFragmentMayNotWriteTheArchivalSetting(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['archival' => [['key' => 'lists', 'data' => ['destructionListRegister' => 1]]]]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('archival');
		$this->catalogue->gather(set: 'demo');
	}//end testAnotherAppsFragmentMayNotWriteTheArchivalSetting()
}//end class
