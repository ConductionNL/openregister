<?php

/**
 * The rematerialiser re-derives a selection, and the command produces the same counts through it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use OCA\OpenRegister\Command\RematerialiseCalculationsCommand;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Calculation\RecomputeSelection;
use OCA\OpenRegister\Tests\Unit\Service\Calculation\Support\RecomputeHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @covers \OCA\OpenRegister\Service\Calculation\CalculationRematerialiser
 * @covers \OCA\OpenRegister\Service\Calculation\RecomputeSelection
 * @covers \OCA\OpenRegister\Service\Calculation\RecomputeResult
 * @covers \OCA\OpenRegister\Command\RematerialiseCalculationsCommand
 * @covers \OCA\OpenRegister\Command\RematerialiseRun
 */
final class CalculationRematerialiserTest extends TestCase {
	use RecomputeHarness;

	/**
	 * The register and case schema.
	 *
	 * @return RegisterSchema
	 */
	private function table(): RegisterSchema {
		return new RegisterSchema(register: $this->register(), schema: $this->caseSchema());
	}//end table()

	/**
	 * A selection by id re-derives only the named objects.
	 *
	 * @return void
	 */
	public function testASelectionTouchesOnlyItsObjects(): void {
		$result = $this->rematerialiser()->run(table: $this->table(), selection: RecomputeSelection::byIds(ids: ['case-A', '13']), dryRun: false);

		$this->assertSame(['touched' => 1, 'unchanged' => 1, 'failed' => []], $result->jsonSerialize());
		$this->assertSame(['case-A'], $this->savedUuids);
	}//end testASelectionTouchesOnlyItsObjects()

	/**
	 * The filters of a filter selection reach the read.
	 *
	 * @return void
	 */
	public function testAFilterSelectionFiltersTheRead(): void {
		$this->rematerialiser()->run(table: $this->table(), selection: RecomputeSelection::byFilters(filters: ['caseType' => 'permit']), dryRun: true);

		$this->assertSame(['caseType' => 'permit'], $this->listings[0]->filters);
		$this->assertSame([], $this->savedUuids);
	}//end testAFilterSelectionFiltersTheRead()

	/**
	 * The command's dry run and the service's dry run over the whole schema agree.
	 *
	 * @return void
	 */
	public function testCommandAndEndpointProduceTheSameCounts(): void {
		[$storage, $evaluation, $types] = $this->collaborators();
		$output = new BufferedOutput();
		$code = (new RematerialiseCalculationsCommand($storage, $evaluation, $types))->run(new ArrayInput(['register' => 'zaken', 'schema' => 'case', '--dry-run' => true]), $output);

		$result = $this->rematerialiser()->run(table: $this->table(), selection: RecomputeSelection::byFilters(filters: []), dryRun: true);

		$this->assertSame(0, $code);
		$this->assertStringContainsString(
			sprintf('Touched %d, unchanged %d, failed %d', $result->touched, $result->unchanged, count($result->failed)),
			$output->fetch()
		);
		$this->assertSame(['touched' => 2, 'unchanged' => 1, 'failed' => []], $result->jsonSerialize());
		$this->assertSame([], $this->savedUuids);
	}//end testCommandAndEndpointProduceTheSameCounts()

	/**
	 * A failed object is reported with its id and the reason.
	 *
	 * @return void
	 */
	public function testAFailureCarriesTheIdAndTheReason(): void {
		$result = $this->rematerialiser()->run(
			table: $this->table(),
			selection: RecomputeSelection::all(),
			dryRun: false,
			mayUpdate: static fn ($object): bool => $object->getUuid() !== 'case-B'
		);

		$this->assertSame(['touched' => 1, 'unchanged' => 1, 'failed' => [['id' => 'case-B', 'reason' => 'forbidden']]], $result->jsonSerialize());
		$this->assertSame(['case-A'], $this->savedUuids);
	}//end testAFailureCarriesTheIdAndTheReason()
}//end class
