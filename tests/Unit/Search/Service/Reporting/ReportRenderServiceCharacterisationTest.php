<?php

/**
 * Characterisation test for ReportRenderService::render().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Reporting;

use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCA\OpenRegister\Search\Service\Reporting\HtmlReportWriter;
use OCA\OpenRegister\Search\Service\Reporting\PdfReportWriter;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCA\OpenRegister\Search\Service\Reporting\ReportWriters;
use OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what render() hands back per format, how widget data is resolved
 * (aggregation as the system, GraphQL, a failure, no data source) and the
 * refused format. The filename stamp is matched by pattern, never by clock.
 *
 * @covers \OCA\OpenRegister\Search\Service\Reporting\ReportRenderService
 */
class ReportRenderServiceCharacterisationTest extends TestCase {

	/**
	 * A dashboard with an aggregation, a GraphQL, a failing and a source-less widget.
	 *
	 * @return array<string, mixed>
	 */
	private function dashboard(): array {
		return [
			'titel' => 'Zaken Overzicht!',
			'widgets' => [
				['title' => 'Per status', 'dataSource' => ['mode' => 'aggregation', 'register' => 'zaken', 'schema' => 'zaak', 'aggregation' => 'perStatus']],
				['title' => 'Via graphql', 'dataSource' => ['mode' => 'graphql', 'graphqlQuery' => '{ zaken { id } }']],
				['title' => 'Kapot', 'dataSource' => ['mode' => 'aggregation', 'register' => 'kapot', 'schema' => 'zaak', 'aggregation' => 'x']],
				['title' => 'Zonder bron'],
				['title' => 'Statistiek', 'dataSource' => ['mode' => 'statistics']],
			],
		];
	}//end dashboard()

	private function service(?LoggerInterface $logger = null): ReportRenderService {
		$runner = $this->createMock(AggregationRunnerInterface::class);
		$runner->method('run')->willReturnCallback(
			function (NamedAggregationRef $ref, ?NamedAggregationOptions $options = null): array {
				if ($ref->register === 'kapot') {
					throw new RuntimeException('boom');
				}

				$bypassRbac = $options?->bypassRbac ?? false;
				return ['name' => $ref->name, 'metric' => 'count', 'value' => ($bypassRbac === true ? 7 : -1), 'register' => $ref->register, 'schema' => $ref->schema];
			}
		);
		$graphql = $this->createMock(GraphQLService::class);
		$graphql->method('execute')->willReturnCallback(
			fn (string $query): array => ['data' => ['echo' => $query]]
		);
		$pdf = $this->createMock(PdfReportWriter::class);
		$pdf->method('write')->willReturnCallback(
			fn (array $dashboard, array $resolvedWidgets): string => '%PDF ' . json_encode([$dashboard['titel'], array_column($resolvedWidgets, 'data')])
		);

		return new ReportRenderService(
			$runner,
			$graphql,
			new ReportWriters(new SpreadsheetReportWriter(), new HtmlReportWriter(), $pdf),
			$logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end service()

	public function testUnsupportedFormatIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->render($this->dashboard(), 'docx');
	}//end testUnsupportedFormatIsRefused()

	public function testDashboardWithoutWidgetsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->render(['titel' => 'x'], 'csv');
	}//end testDashboardWithoutWidgetsIsRefused()

	public function testPdfCarriesTheResolvedWidgetData(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$rendered = $this->service($logger)->render($this->dashboard(), 'pdf');

		$this->assertSame('application/pdf', $rendered['mime']);
		$this->assertMatchesRegularExpression('/^zaken-overzicht_\d{4}-\d{2}-\d{2}_\d{6}\.pdf$/', $rendered['filename']);
		$this->assertSame(
			'%PDF ' . json_encode(
				[
					'Zaken Overzicht!',
					[
						['name' => 'perStatus', 'metric' => 'count', 'value' => 7, 'register' => 'zaken', 'schema' => 'zaak'],
						['data' => ['echo' => '{ zaken { id } }']],
						null,
						null,
						null,
					],
				]
			),
			$rendered['bytes']
		);
	}//end testPdfCarriesTheResolvedWidgetData()

	public function testHtmlAndSpreadsheetFormats(): void {
		$service = $this->service();
		$html    = $service->render($this->dashboard(), 'html');
		$this->assertSame('text/html; charset=utf-8', $html['mime']);
		$this->assertStringEndsWith('.html', $html['filename']);
		$this->assertStringContainsString('Per status', $html['bytes']);

		$csv = $service->render($this->dashboard(), 'csv');
		$this->assertSame('text/csv; charset=utf-8', $csv['mime']);
		$this->assertStringEndsWith('.csv', $csv['filename']);
		$this->assertStringContainsString('Per status', $csv['bytes']);

		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $service->render($this->dashboard(), 'ods')['mime']);
		$this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $service->render($this->dashboard(), 'xlsx')['mime']);
	}//end testHtmlAndSpreadsheetFormats()

	public function testUntitledDashboardIsNamedDashboard(): void {
		$rendered = $this->service()->render(['widgets' => []], 'csv');
		$this->assertMatchesRegularExpression('/^dashboard_\d{4}-\d{2}-\d{2}_\d{6}\.csv$/', $rendered['filename']);
	}//end testUntitledDashboardIsNamedDashboard()
}//end class
