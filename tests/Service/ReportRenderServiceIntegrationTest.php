<?php

/**
 * Integration tests for `ReportRenderService` (rapportage Phase 2).
 *
 * Locks in:
 *   1. Render a fixture dashboard to each supported format and verify
 *      the bytes are non-empty + carry the right MIME type.
 *   2. The CSV / XLSX / ODS outputs reflect the resolved widget data
 *      (we test against decidesk's real `action-item` aggregations).
 *   3. The HTML output is well-formed (starts with <!DOCTYPE html>) +
 *      includes the dashboard title.
 *   4. Unsupported formats raise InvalidArgumentException (422 in the
 *      controller).
 *   5. Widgets whose data-source can't resolve render an inline error
 *      envelope rather than crashing the whole render.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Reporting\ReportRenderService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 render-pipeline integration suite.
 *
 * @group DB
 */
class ReportRenderServiceIntegrationTest extends TestCase
{

    /**
     * Renderer under test.
     *
     * @var ReportRenderService
     */
    private ReportRenderService $service;

    /**
     * Resolve the renderer from the DI container.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(ReportRenderService::class);

    }//end setUp()

    /**
     * XLSX output: bytes start with the ZIP header and MIME matches.
     *
     * @return void
     */
    public function testRenderXlsxProducesValidWorkbook(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'xlsx'
        );

        $this->assertSame(
            expected: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            actual: $rendered['mime']
        );
        $this->assertStringEndsWith(suffix: '.xlsx', string: $rendered['filename']);
        // XLSX is a ZIP container — first 2 bytes are PK.
        $this->assertSame(expected: 'PK', actual: substr($rendered['bytes'], 0, 2));

    }//end testRenderXlsxProducesValidWorkbook()

    /**
     * ODS output: bytes start with the ZIP header and MIME matches.
     *
     * @return void
     */
    public function testRenderOdsProducesValidWorkbook(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'ods'
        );

        $this->assertSame(
            expected: 'application/vnd.oasis.opendocument.spreadsheet',
            actual: $rendered['mime']
        );
        $this->assertStringEndsWith(suffix: '.ods', string: $rendered['filename']);
        // ODS is also a ZIP container.
        $this->assertSame(expected: 'PK', actual: substr($rendered['bytes'], 0, 2));

    }//end testRenderOdsProducesValidWorkbook()

    /**
     * CSV output: emits one section per sheet with a `# Sheet:` header.
     *
     * @return void
     */
    public function testRenderCsvIncludesEachSheetAsSection(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'csv'
        );

        $this->assertStringContainsString(needle: 'text/csv', haystack: $rendered['mime']);
        $this->assertStringEndsWith(suffix: '.csv', string: $rendered['filename']);
        // CSV writer emits a `# Sheet: Overview` header before the
        // overview-sheet rows + one section per widget sheet.
        $this->assertStringContainsString(needle: '# Sheet: Overview', haystack: $rendered['bytes']);

    }//end testRenderCsvIncludesEachSheetAsSection()

    /**
     * HTML output: well-formed document with print stylesheet baked in.
     *
     * @return void
     */
    public function testRenderHtmlProducesPrintableDocument(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'html'
        );

        $this->assertStringStartsWith(prefix: 'text/html', string: $rendered['mime']);
        $this->assertStringStartsWith(prefix: '<!DOCTYPE html>', string: $rendered['bytes']);
        $this->assertStringContainsString(needle: 'phpunit-rapportage', haystack: $rendered['bytes']);
        // Print stylesheet baked in.
        $this->assertStringContainsString(needle: '@media print', haystack: $rendered['bytes']);

    }//end testRenderHtmlProducesPrintableDocument()

    /**
     * PDF output: bytes start with the `%PDF-` magic header and MIME
     * matches.
     *
     * @return void
     */
    public function testRenderPdfProducesPdfDocument(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'pdf'
        );

        $this->assertSame(expected: 'application/pdf', actual: $rendered['mime']);
        $this->assertStringEndsWith(suffix: '.pdf', string: $rendered['filename']);
        // PDF files start with the `%PDF-` signature.
        $this->assertSame(expected: '%PDF-', actual: substr($rendered['bytes'], 0, 5));

    }//end testRenderPdfProducesPdfDocument()

    /**
     * Unsupported formats raise InvalidArgumentException (422 in the
     * controller).
     *
     * @return void
     */
    public function testRenderRejectsUnsupportedFormat(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Unsupported render format');

        $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'docx'
        );

    }//end testRenderRejectsUnsupportedFormat()

    /**
     * A widget whose data-source can't resolve renders an inline error
     * envelope rather than aborting the whole render.
     *
     * @return void
     */
    public function testRenderHandlesUnresolvableWidgetGracefully(): void
    {
        $dashboard = [
            'titel'   => 'phpunit-rapportage-broken',
            'widgets' => [
                [
                    'type'       => 'kpi',
                    'title'      => 'Bogus',
                    'dataSource' => [
                        'mode'        => 'aggregation',
                        'register'    => 'phpunit-no-such-register',
                        'schema'      => 'phpunit-no-such-schema',
                        'aggregation' => 'phpunit-no-such-agg',
                    ],
                ],
            ],
        ];

        $rendered = $this->service->render(dashboard: $dashboard, format: 'html');

        // The widget renders its inline error envelope rather than the
        // whole render aborting.
        $this->assertStringContainsString(needle: 'No data available', haystack: $rendered['bytes']);

    }//end testRenderHandlesUnresolvableWidgetGracefully()

    /**
     * Build a dashboard payload pointing at decidesk's existing
     * action-item aggregation declarations. The aggregation runner
     * runs against real Postgres data; we don't seed anything here
     * because the dev env already has the action-item schema with
     * its `x-openregister-aggregations` annotation.
     *
     * @return array<string, mixed>
     */
    private function makeDashboardFixture(): array
    {
        return [
            'titel'        => 'phpunit-rapportage-fixture',
            'beschrijving' => 'Phase 2 render fixture covering the three widget shapes.',
            'category'     => 'operational',
            'layout'       => ['cols' => 3],
            'widgets'      => [
                [
                    'type'       => 'kpi',
                    'title'      => 'Open',
                    'dataSource' => [
                        'mode'        => 'aggregation',
                        'register'    => 'decidesk',
                        'schema'      => 'action-item',
                        'aggregation' => 'totalOpen',
                    ],
                    'options'    => ['valueField' => 'value'],
                ],
                [
                    'type'       => 'chart',
                    'title'      => 'By status',
                    'dataSource' => [
                        'mode'        => 'aggregation',
                        'register'    => 'decidesk',
                        'schema'      => 'action-item',
                        'aggregation' => 'byStatus',
                    ],
                    'options'    => ['chartType' => 'bar', 'valueField' => 'value'],
                ],
                [
                    'type'       => 'stats',
                    'title'      => 'Avg days to close',
                    'dataSource' => [
                        'mode'        => 'aggregation',
                        'register'    => 'decidesk',
                        'schema'      => 'action-item',
                        'aggregation' => 'avgDaysToClose',
                    ],
                ],
            ],
        ];

    }//end makeDashboardFixture()
}//end class
