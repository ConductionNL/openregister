<?php

/**
 * Tests for ReportTemplateGenerator: a report template rendered over a period.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Reporting;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCA\OpenRegister\Search\Service\Reporting\HtmlReportWriter;
use OCA\OpenRegister\Search\Service\Reporting\PdfReportWriter;
use OCA\OpenRegister\Search\Service\Reporting\ReportPeriod;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCA\OpenRegister\Search\Service\Reporting\ReportSectionQuery;
use OCA\OpenRegister\Search\Service\Reporting\ReportSectionRows;
use OCA\OpenRegister\Search\Service\Reporting\ReportSectionSource;
use OCA\OpenRegister\Search\Service\Reporting\ReportTemplateGenerator;
use OCA\OpenRegister\Search\Service\Reporting\ReportWriters;
use OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ReportTemplateGenerator::class)]
#[CoversClass(ReportPeriod::class)]
#[CoversClass(ReportSectionRows::class)]
#[CoversClass(ReportSectionQuery::class)]
#[CoversClass(ReportSectionSource::class)]
#[CoversClass(ReportRenderService::class)]
class ReportTemplateGeneratorTest extends TestCase {

	/**
	 * Every query the runner was handed, with its register and schema.
	 *
	 * @var array<int, array{register: string, schema: string, query: AggregationQuery}>
	 */
	private array $runs = [];

	private function template(): array {
		return [
			'name' => 'Jaarcijfers',
			'description' => 'Wat er dit jaar gebeurde.',
			'dataSource' => ['register' => 'publicaties', 'schema' => 'publicatie', 'filters' => ['status' => 'published']],
			'dateField' => 'publicationDate',
			'branding' => ['organisation' => 'Gemeente Voorbeeld'],
			'sections' => [
				['key' => 'total', 'title' => 'Totaal', 'type' => 'total'],
				[
					'key' => 'per-category', 'title' => 'Per categorie', 'type' => 'grouped', 'groupBy' => 'category',
					'rows' => ['a', 'b', 'c'], 'compareWithPreviousYear' => true,
				],
			],
		];
	}//end template()

	private function generator(?callable $groups = null): ReportTemplateGenerator {
		$groups ??= static fn (AggregationQuery $query): array => [['key' => 'b', 'value' => 4], ['key' => 'z', 'value' => 1]];
		$runner = $this->createMock(AggregationRunnerInterface::class);
		$runner->method('findSchema')->willReturn(new Schema());
		$runner->method('runAdhocByRef')->willReturnCallback(
			function (string $registerRef, string $schemaRef, AggregationQuery $query, bool $bypassRbac = false) use ($groups): array {
				$this->assertFalse($bypassRbac, 'a template section counts with the caller\'s rights');
				$this->runs[] = ['register' => $registerRef, 'schema' => $schemaRef, 'query' => $query];
				if ($query->groupBy === null && $query->dateBucket === null) {
					return ['value' => 5];
				}

				return ['groups' => $groups($query)];
			}
		);
		$validator = $this->createMock(TimeseriesRequestValidator::class);
		$validator->method('validate')->willReturnCallback(
			function (array $input): AggregationQuery {
				$this->assertSame('2027-01-01', $input['to'], 'a series ends the day after the period');
				return AggregationQuery::create(
					metric: 'count',
					filter: $input['filter'],
					layout: new AggregationLayout(
						dateBucket: ['field' => $input['field'], 'start' => $input['from'], 'end' => $input['to'], 'gap' => $input['interval']]
					)
				);
			}
		);
		$pdf = $this->createMock(PdfReportWriter::class);
		$pdf->method('write')->willReturnCallback(
			static fn (array $dashboard, array $resolvedWidgets): string => json_encode(
				['title' => $dashboard['titel'], 'organisation' => $dashboard['category'], 'widgets' => $resolvedWidgets]
			)
		);
		$render = new ReportRenderService(
			$runner,
			$this->createMock(GraphQLService::class),
			new ReportWriters(new SpreadsheetReportWriter(), new HtmlReportWriter(), $pdf),
			$this->createMock(LoggerInterface::class)
		);

		return new ReportTemplateGenerator(new ReportSectionQuery(new AggregationControllerServices($runner, $validator)), $render);
	}//end generator()

	private function generatedWidgets(array $template, string $from = '2026-01-01', string $to = '2026-12-31'): array {
		$rendered = $this->generator()->generate($template, new ReportPeriod($from, $to), 'pdf');
		$this->assertSame('application/pdf', $rendered['mime']);

		return json_decode($rendered['bytes'], true);
	}//end generatedWidgets()

	public function testEveryListedRowIsPresentZeroIncludedAndUnlistedKeysFollow(): void {
		$report = $this->generatedWidgets($this->template());
		$groups = $report['widgets'][1]['data']['groups'];

		$this->assertSame(
			[['key' => 'a', 'value' => 0], ['key' => 'b', 'value' => 4], ['key' => 'c', 'value' => 0], ['key' => 'z', 'value' => 1]],
			$groups
		);
	}//end testEveryListedRowIsPresentZeroIncludedAndUnlistedKeysFollow()

	public function testTheSectionsCountInThePeriodOnTheTemplatesDateProperty(): void {
		$this->generatedWidgets($this->template());

		$this->assertSame('publicaties', $this->runs[0]['register']);
		$this->assertSame('publicatie', $this->runs[0]['schema']);
		$this->assertSame(
			['status' => 'published', 'publicationDate' => ['gte' => '2026-01-01', 'lt' => '2027-01-01']],
			$this->runs[0]['query']->filter
		);
		$this->assertSame(['field' => 'category'], $this->runs[1]['query']->groupBy);
	}//end testTheSectionsCountInThePeriodOnTheTemplatesDateProperty()

	public function testACompareSectionIsFollowedByTheSameSectionAYearEarlier(): void {
		$report = $this->generatedWidgets($this->template());

		$this->assertCount(3, $report['widgets']);
		$this->assertSame('2026', $report['widgets'][1]['widget']['subtitle']);
		$this->assertSame('2025', $report['widgets'][2]['widget']['subtitle']);
		$this->assertSame(['gte' => '2025-01-01', 'lt' => '2026-01-01'], $this->runs[2]['query']->filter['publicationDate']);
		$this->assertSame('Jaarcijfers 2026', $report['title']);
		$this->assertSame('Gemeente Voorbeeld', $report['organisation']);
	}//end testACompareSectionIsFollowedByTheSameSectionAYearEarlier()

	public function testATotalSectionIsOneNumber(): void {
		$report = $this->generatedWidgets($this->template());

		$this->assertSame([['key' => 'Totaal', 'value' => 5]], $report['widgets'][0]['data']['groups']);
		$this->assertNull($this->runs[0]['query']->groupBy);
	}//end testATotalSectionIsOneNumber()

	public function testASectionNamesItsOwnSourceAndFilters(): void {
		$template = $this->template();
		$template['sections'] = [
			[
				'key' => 'withdrawn', 'type' => 'grouped', 'register' => 'archief', 'schema' => 'intrekking',
				'filters' => ['status' => 'withdrawn'], 'dateField' => 'withdrawnAt', 'groupBy' => 'reason',
			],
		];
		$this->generatedWidgets($template);

		$this->assertSame('archief', $this->runs[0]['register']);
		$this->assertSame('intrekking', $this->runs[0]['schema']);
		$this->assertSame('withdrawn', $this->runs[0]['query']->filter['status']);
		$this->assertSame(['status', 'withdrawnAt'], array_keys($this->runs[0]['query']->filter), 'the template filters stay with the template schema');
		$this->assertArrayHasKey('withdrawnAt', $this->runs[0]['query']->filter);
	}//end testASectionNamesItsOwnSourceAndFilters()

	public function testASeriesSectionHasEveryMonthOfThePeriod(): void {
		$template = $this->template();
		$template['sections'] = [['key' => 'per-month', 'type' => 'series', 'interval' => 'month']];
		$generator = $this->generator(static fn (AggregationQuery $query): array => [['key' => '2026-03-01T00:00:00Z', 'value' => 2]]);
		$report = json_decode($generator->generate($template, new ReportPeriod('2026-01-01', '2026-12-31'), 'pdf')['bytes'], true);

		$groups = $report['widgets'][0]['data']['groups'];
		$this->assertCount(12, $groups);
		$this->assertSame(2, $groups[2]['value']);
		$this->assertSame(0, $groups[0]['value']);
		$this->assertSame('2026-01', $groups[0]['key']);
		$this->assertSame('publicationDate', $this->runs[0]['query']->dateBucket['field'] ?? null);
	}//end testASeriesSectionHasEveryMonthOfThePeriod()

	public function testASectionTheCallerMayNotReadRefusesTheWholeReport(): void {
		$runner = $this->createMock(AggregationRunnerInterface::class);
		$runner->method('runAdhocByRef')->willThrowException(new NotAuthorizedException('no read'));
		$generator = new ReportTemplateGenerator(
			new ReportSectionQuery(new AggregationControllerServices($runner, $this->createMock(TimeseriesRequestValidator::class))),
			$this->createMock(ReportRenderService::class)
		);

		$this->expectException(NotAuthorizedException::class);
		$generator->generate($this->template(), new ReportPeriod('2026-01-01', '2026-12-31'), 'csv');
	}//end testASectionTheCallerMayNotReadRefusesTheWholeReport()

	public function testATemplateWithoutSectionsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->generator()->generate(['name' => 'x', 'sections' => []], new ReportPeriod('2026-01-01', '2026-12-31'), 'csv');
	}//end testATemplateWithoutSectionsIsRefused()

	public function testAnUnknownSectionTypeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$template = $this->template();
		$template['sections'] = [['key' => 'x', 'type' => 'pie']];
		$this->generator()->generate($template, new ReportPeriod('2026-01-01', '2026-12-31'), 'csv');
	}//end testAnUnknownSectionTypeIsRefused()

	public function testAGroupedSectionWithoutGroupByIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$template = $this->template();
		$template['sections'] = [['key' => 'x', 'type' => 'grouped']];
		$this->generator()->generate($template, new ReportPeriod('2026-01-01', '2026-12-31'), 'csv');
	}//end testAGroupedSectionWithoutGroupByIsRefused()

	public function testASectionWithoutRegisterOrSchemaIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->generator()->generate(['name' => 'x', 'sections' => [['key' => 't', 'type' => 'total']]], new ReportPeriod('2026-01-01', '2026-12-31'), 'csv');
	}//end testASectionWithoutRegisterOrSchemaIsRefused()

	public function testCsvCarriesTheZeroRows(): void {
		$rendered = $this->generator()->generate($this->template(), new ReportPeriod('2026-01-01', '2026-12-31'), 'csv');

		$this->assertStringContainsString('text/csv', $rendered['mime']);
		$this->assertStringStartsWith('jaarcijfers-2026_', $rendered['filename']);
	}//end testCsvCarriesTheZeroRows()

	public function testAReversedOrMalformedPeriodIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new ReportPeriod('2026-12-31', '2026-01-01');
	}//end testAReversedOrMalformedPeriodIsRefused()

	public function testAMalformedDateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new ReportPeriod('2026-02-30', '2026-12-31');
	}//end testAMalformedDateIsRefused()

	public function testAPeriodIsLabelledByYearMonthOrRange(): void {
		$this->assertSame('2026', (new ReportPeriod('2026-01-01', '2026-12-31'))->label());
		$this->assertSame('2026-02', (new ReportPeriod('2026-02-01', '2026-02-28'))->label());
		$this->assertSame('2026-03-01/2026-03-07', (new ReportPeriod('2026-03-01', '2026-03-07'))->label());
	}//end testAPeriodIsLabelledByYearMonthOrRange()

	public function testAnExistingConditionOnTheDatePropertyIsKept(): void {
		$filter = (new ReportPeriod('2026-01-01', '2026-12-31'))->narrow(['publicationDate' => 'x', 'other' => 1], 'publicationDate');

		$this->assertSame(['eq' => 'x', 'gte' => '2026-01-01', 'lt' => '2027-01-01'], $filter['publicationDate']);
		$this->assertSame(1, $filter['other']);
	}//end testAnExistingConditionOnTheDatePropertyIsKept()
}//end class
