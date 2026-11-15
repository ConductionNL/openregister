<?php

/**
 * Unit tests for CrossRegisterReport, the aggregation across registers
 * behind `GET /api/reports/aggregate`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicTableLocatorInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\CrossRegisterReport;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\CrossRegisterReport
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQueryRules
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQueryShape
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods
 */
class CrossRegisterReportTest extends TestCase {

	private AggregationRunnerInterface&MockObject $runner;

	private MagicTableLocatorInterface&MockObject $locator;

	private RegisterMapperInterface&MockObject $registers;

	protected function setUp(): void {
		$this->runner = $this->createMock(AggregationRunnerInterface::class);
		$this->locator = $this->createMock(MagicTableLocatorInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->registers->method('getIdToSlugMap')->willReturn([1 => 'zaken', 2 => 'klanten', 3 => 'vertrouwelijk']);
		$this->locator->method('getAllRegisterSchemaPairs')->willReturn(
			[
				['registerId' => 1, 'schemaId' => 10],
				['registerId' => 1, 'schemaId' => 11],
				['registerId' => 2, 'schemaId' => 20],
				['registerId' => 3, 'schemaId' => 30],
			]
		);
	}//end setUp()

	private function report(): CrossRegisterReport {
		return new CrossRegisterReport($this->runner, $this->locator, $this->registers);
	}//end report()

	/**
	 * Answer each pair from a map keyed `<register>/<schema>`; a Throwable value is thrown.
	 *
	 * @param array<string, mixed> $byPair The envelopes.
	 */
	private function answer(array $byPair): void {
		$this->runner->method('runAdhocByRef')->willReturnCallback(
			static function (string $registerRef, string $schemaRef, AggregationQuery $query) use ($byPair): array {
				$answer = $byPair[$registerRef.'/'.$schemaRef];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
	}//end answer()

	public function testCountsEveryRegisterAndAddsUpItsSchemas(): void {
		$this->answer(
			[
				'1/10' => ['value' => 5000],
				'1/11' => ['value' => 3000],
				'2/20' => ['value' => 5000],
				'3/30' => ['value' => 2000],
			]
		);

		$totals = $this->report()->totals(metric: 'count', field: null);

		$this->assertSame(15000, $totals['total']);
		$this->assertSame(
			[
				['register' => 'zaken', 'count' => 8000],
				['register' => 'klanten', 'count' => 5000],
				['register' => 'vertrouwelijk', 'count' => 2000],
			],
			$totals['byRegister']
		);
	}//end testCountsEveryRegisterAndAddsUpItsSchemas()

	public function testARegisterTheCallerMayNotReadIsLeftOutSilently(): void {
		$this->answer(
			[
				'1/10' => ['value' => 5],
				'1/11' => new NotAuthorizedException('no read'),
				'2/20' => new RuntimeException('schema gone'),
				'3/30' => new NotAuthorizedException('no read'),
			]
		);

		$totals = $this->report()->totals(metric: 'count', field: null);

		$this->assertSame(5, $totals['total']);
		$this->assertSame([['register' => 'zaken', 'count' => 5]], $totals['byRegister']);
	}//end testARegisterTheCallerMayNotReadIsLeftOutSilently()

	public function testRegistersNarrowsBySlugOrId(): void {
		$asked = [];
		$this->runner->method('runAdhocByRef')->willReturnCallback(
			static function (string $registerRef, string $schemaRef) use (&$asked): array {
				$asked[] = $registerRef.'/'.$schemaRef;
				return ['value' => 1];
			}
		);

		$totals = $this->report()->totals(metric: 'count', field: null, registerRefs: ['Klanten', '3']);

		$this->assertSame(['2/20', '3/30'], $asked);
		$this->assertSame(2, $totals['total']);
	}//end testRegistersNarrowsBySlugOrId()

	public function testSumAddsUpAndMaxTakesTheLargest(): void {
		$this->answer(['1/10' => ['value' => 2.5], '1/11' => ['value' => 4], '2/20' => ['value' => 7], '3/30' => ['value' => null]]);

		$sum = $this->report()->totals(metric: 'sum', field: 'bedrag');
		$max = $this->report()->totals(metric: 'max', field: 'bedrag');

		$this->assertSame(13.5, $sum['total']);
		$this->assertSame([['register' => 'zaken', 'sum' => 6.5], ['register' => 'klanten', 'sum' => 7]], $sum['byRegister']);
		$this->assertSame(7, $max['total']);
		$this->assertSame([['register' => 'zaken', 'max' => 4], ['register' => 'klanten', 'max' => 7]], $max['byRegister']);
	}//end testSumAddsUpAndMaxTakesTheLargest()

	public function testAnAverageCannotBeCombinedAcrossRegisters(): void {
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$this->expectException(InvalidArgumentException::class);
		$this->report()->totals(metric: 'avg', field: 'bedrag');
	}//end testAnAverageCannotBeCombinedAcrossRegisters()

	public function testTheSeriesHasOneLineUpPerRegisterWithEveryMonth(): void {
		$this->answer(
			[
				'1/10' => ['groups' => [['key' => '2026-01-01T00:00:00Z', 'value' => 60], ['key' => '2026-02-01T00:00:00Z', 'value' => 70]]],
				'1/11' => ['groups' => [['key' => '2026-01-01T00:00:00Z', 'value' => 40], ['key' => '2026-03-01T00:00:00Z', 'value' => 95]]],
				'2/20' => ['groups' => [['key' => '2026-02-01T00:00:00Z', 'value' => 60]]],
				'3/30' => new NotAuthorizedException('no read'),
			]
		);
		$built = [];
		$queryFor = static function (string $register, string $schema) use (&$built): AggregationQuery {
			$built[] = $register.'/'.$schema;
			return AggregationQuery::create(metric: 'count');
		};

		$series = $this->report()->series(
			registerRefs: [],
			gap: 'month',
			range: ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-04-01T00:00:00Z'],
			queryFor: $queryFor
		);

		$this->assertSame(['1/10', '1/11', '2/20', '3/30'], $built);
		$this->assertSame(['2026-01', '2026-02', '2026-03'], $series['labels']);
		$this->assertSame(
			[
				['name' => 'zaken', 'data' => [100, 70, 95]],
				['name' => 'klanten', 'data' => [0, 60, 0]],
			],
			$series['series']
		);
	}//end testTheSeriesHasOneLineUpPerRegisterWithEveryMonth()
}//end class
