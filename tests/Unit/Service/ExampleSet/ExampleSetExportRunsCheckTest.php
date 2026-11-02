<?php

/**
 * A fragment's exports are read with its other parts and checked before
 * anything is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 */
class ExampleSetExportRunsCheckTest extends ExampleSetTestCase {
	/**
	 * An export for an account outside the set refuses the set; one for a cast account passes the check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
	 */
	public function testTheCheckReadsAFragmentsExports(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['exportRuns' => [
			['key' => 'export-1', 'data' => ['user' => 'nobody', 'fileName' => 'a.csv', 'format' => 'csv', 'content' => 'x']],
			['key' => 'export-2', 'data' => ['user' => '{{cast:esra.uid}}', 'fileName' => 'b.csv', 'format' => 'csv', 'content' => 'x', 'producedAt' => '{{datetime:-2 14:05}}']],
		]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('export "export-1"', $refusals[0]);
		$this->assertStringContainsString('not an account', $refusals[0]);
	}//end testTheCheckReadsAFragmentsExports()
}//end class
