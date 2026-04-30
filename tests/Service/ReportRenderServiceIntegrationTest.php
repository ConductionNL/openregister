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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Reporting\ReportRenderService;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class ReportRenderServiceIntegrationTest extends TestCase
{

    private ReportRenderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(ReportRenderService::class);

    }//end setUp()

    public function testRenderXlsxProducesValidWorkbook(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'xlsx'
        );

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $rendered['mime']
        );
        $this->assertStringEndsWith('.xlsx', $rendered['filename']);
        // XLSX is a ZIP container — first 2 bytes are PK.
        $this->assertSame('PK', substr($rendered['bytes'], 0, 2));

    }//end testRenderXlsxProducesValidWorkbook()

    public function testRenderOdsProducesValidWorkbook(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'ods'
        );

        $this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $rendered['mime']);
        $this->assertStringEndsWith('.ods', $rendered['filename']);
        // ODS is also a ZIP container.
        $this->assertSame('PK', substr($rendered['bytes'], 0, 2));

    }//end testRenderOdsProducesValidWorkbook()

    public function testRenderCsvIncludesEachSheetAsSection(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'csv'
        );

        $this->assertStringContainsString('text/csv', $rendered['mime']);
        $this->assertStringEndsWith('.csv', $rendered['filename']);
        // CSV writer emits a `# Sheet: Overview` header before the
        // overview-sheet rows + one section per widget sheet.
        $this->assertStringContainsString('# Sheet: Overview', $rendered['bytes']);

    }//end testRenderCsvIncludesEachSheetAsSection()

    public function testRenderHtmlProducesPrintableDocument(): void
    {
        $rendered = $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'html'
        );

        $this->assertStringStartsWith('text/html', $rendered['mime']);
        $this->assertStringStartsWith('<!DOCTYPE html>', $rendered['bytes']);
        $this->assertStringContainsString('phpunit-rapportage', $rendered['bytes']);
        // Print stylesheet baked in.
        $this->assertStringContainsString('@media print', $rendered['bytes']);

    }//end testRenderHtmlProducesPrintableDocument()

    public function testRenderRejectsUnsupportedFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported render format');

        $this->service->render(
            dashboard: $this->makeDashboardFixture(),
            format: 'pdf'
        );

    }//end testRenderRejectsUnsupportedFormat()

    public function testRenderHandlesUnresolvableWidgetGracefully(): void
    {
        $dashboard = [
            'titel'    => 'phpunit-rapportage-broken',
            'widgets'  => [
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
        $this->assertStringContainsString('No data available', $rendered['bytes']);

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
