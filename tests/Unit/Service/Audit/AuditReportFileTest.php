<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Audit\AuditReport;
use OCA\OpenRegister\Service\Audit\AuditReportFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The audit report file: an Excel or ODS workbook with an entries sheet and a summary sheet, or CSV
 * with the entries only.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */
#[CoversClass(AuditReportFile::class)]
final class AuditReportFileTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function report(bool $truncated = false): array {
		return [
			'entries' => [
				['timestamp' => '2026-03-05T09:00:00+00:00', 'action' => 'update', 'objectUuid' => 'obj-a', 'objectName' => 'Melding 1', 'userId' => 'medewerker-1', 'ipAddress' => '10.0.0.2', 'changes' => 'title'],
				['timestamp' => '2026-03-02T10:00:00+00:00', 'action' => 'create', 'objectUuid' => 'obj-a', 'objectName' => '=HYPERLINK("x")', 'userId' => 'medewerker-1', 'ipAddress' => '10.0.0.1', 'changes' => ''],
			],
			'summary' => [
				'total' => 2,
				'actions' => ['create' => 1, 'update' => 1],
				'mostActiveObjects' => [['objectUuid' => 'obj-a', 'objectName' => 'Melding 1', 'count' => 2]],
			],
			'truncated' => $truncated,
		];
	}

	/**
	 * @return array<int, array<int, mixed>> Each sheet's cells, by sheet title.
	 */
	private static function sheets(string $bytes, string $extension): array {
		$path = tempnam(sys_get_temp_dir(), 'audit-report').'.'.$extension;
		file_put_contents($path, $bytes);
		$book = IOFactory::load($path);
		unlink($path);
		$sheets = [];
		foreach ($book->getAllSheets() as $sheet) {
			$sheets[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
		}

		return $sheets;
	}

	public function testAnExcelFileHasTheEntriesAndTheSummary(): void {
		$written = (new AuditReportFile())->write(report: self::report(), format: 'xlsx');

		$this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $written['mime']);
		$this->assertSame('audit-report.xlsx', $written['filename']);
		$sheets = self::sheets(bytes: $written['bytes'], extension: 'xlsx');
		$this->assertSame(['Audit trail', 'Summary'], array_keys($sheets));
		$this->assertSame(AuditReport::COLUMNS, $sheets['Audit trail'][0]);
		$this->assertSame(['2026-03-05T09:00:00+00:00', 'update', 'obj-a', 'Melding 1', 'medewerker-1', '10.0.0.2', 'title'], $sheets['Audit trail'][1]);
		$this->assertSame("'=HYPERLINK(\"x\")", $sheets['Audit trail'][2][3], 'A value that starts like a formula stays text.');
		$this->assertSame(['Total entries', 2], array_slice($sheets['Summary'][0], 0, 2));
		$this->assertContains(['Action', 'Entries', null], $sheets['Summary']);
		$this->assertContains(['update', 1, null], $sheets['Summary']);
		$this->assertContains(['obj-a', 'Melding 1', 2], $sheets['Summary']);
	}

	public function testAnOdsFileHasTheSameSheets(): void {
		$written = (new AuditReportFile())->write(report: self::report(), format: 'ods');

		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $written['mime']);
		$sheets = self::sheets(bytes: $written['bytes'], extension: 'ods');
		$this->assertSame(['Audit trail', 'Summary'], array_keys($sheets));
		$this->assertSame('create', $sheets['Audit trail'][2][1]);
	}

	public function testACutReportSaysSoOnTheSummary(): void {
		$sheets = self::sheets(bytes: (new AuditReportFile())->write(report: self::report(truncated: true), format: 'xlsx')['bytes'], extension: 'xlsx');

		$this->assertSame(['Cut at', AuditReport::MAX_ENTRIES], array_slice($sheets['Summary'][1], 0, 2));
	}

	public function testACsvFileHasTheEntriesOnly(): void {
		$written = (new AuditReportFile())->write(report: self::report(), format: 'csv');

		$this->assertSame(['text/csv', 'audit-report.csv'], [$written['mime'], $written['filename']]);
		$lines = array_map('str_getcsv', array_filter(explode("\n", $written['bytes'])));
		$this->assertSame(AuditReport::COLUMNS, $lines[0]);
		$this->assertCount(3, $lines);
		$this->assertSame("'=HYPERLINK(\"x\")", $lines[2][3]);
	}

	public function testAnUnknownFormatIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported audit report format "pdf"; use xlsx, ods, csv.');

		(new AuditReportFile())->write(report: self::report(), format: 'pdf');
	}
}
