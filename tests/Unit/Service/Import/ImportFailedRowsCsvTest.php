<?php

/**
 * Unit tests for ImportFailedRowsCsv — the failed rows of a run in the shape they were submitted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Import\ImportFailedRowsCsv;
use PHPUnit\Framework\TestCase;

final class ImportFailedRowsCsvTest extends TestCase {

	private function row(int $sourceRow, string $reason, array $source): ImportPreviewRow {
		$row = new ImportPreviewRow();
		$row->setSourceRow($sourceRow);
		$row->setDecision(ImportPreviewRow::DECISION_REFUSE);
		$row->setReason($reason);
		$row->setSource($source);

		return $row;
	}

	private function aRun(): ImportPreview {
		$run = new ImportPreview();
		$run->setId(12);

		return $run;
	}

	private function lines(string $csv): array {
		$lines = [];
		foreach (explode("\n", trim($csv)) as $line) {
			$lines[] = str_getcsv($line, ',', '"', '');
		}

		return $lines;
	}

	public function testEachFailedRowComesBackAsSubmittedWithItsReason(): void {
		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);
		$rows->method('findFailed')->willReturnCallback(
			function (int $runId, ListQuery $query): array {
				$this->assertSame(12, $runId);
				$this->assertSame(0, $query->offset);

				return [
					$this->row(2, 'Policy create-only, and the match key hits object-111.', ['bsn' => 111, 'naam' => 'Jansen']),
					$this->row(5, 'The write failed: invalid', ['naam' => 'Yilmaz', 'tags' => ['a', 'b'], 'actief' => false]),
				];
			}
		);

		$lines = $this->lines((new ImportFailedRowsCsv($rows))->render($this->aRun()));

		$this->assertSame(['_row', '_reason', 'bsn', 'naam', 'tags', 'actief'], $lines[0]);
		$this->assertSame(['2', 'Policy create-only, and the match key hits object-111.', '111', 'Jansen', '', ''], $lines[1]);
		$this->assertSame(['5', 'The write failed: invalid', '', 'Yilmaz', '["a","b"]', 'false'], $lines[2]);
		$this->assertCount(3, $lines);
	}

	public function testTheRowsAreReadAPageAtATime(): void {
		$offsets = [];
		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);
		$rows->method('findFailed')->willReturnCallback(
			function (int $runId, ListQuery $query) use (&$offsets): array {
				$offsets[] = [$query->offset, $query->limit];
				if ($query->offset === 0) {
					return array_fill(0, 500, $this->row(2, 'r', ['a' => 1]));
				}

				return [$this->row(600, 'r', ['b' => 2])];
			}
		);

		$lines = $this->lines((new ImportFailedRowsCsv($rows))->render($this->aRun()));

		// Two passes (the columns, then the rows), each reading two pages.
		$this->assertSame([[0, 500], [500, 500], [0, 500], [500, 500]], $offsets);
		$this->assertSame(['_row', '_reason', 'a', 'b'], $lines[0]);
		$this->assertCount(502, $lines);
		$this->assertSame(['600', 'r', '', '2'], $lines[501]);
	}

	public function testARunWithoutFailuresIsJustTheHeader(): void {
		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);
		$rows->method('findFailed')->willReturn([]);

		$this->assertSame("_row,_reason\n", (new ImportFailedRowsCsv($rows))->render($this->aRun()));
	}

	public function testTheLeadingColumnsAreOnesTheImporterIgnores(): void {
		// A corrected file loads without removing them: `_` columns are not data.
		$this->assertStringStartsWith('_', ImportFailedRowsCsv::ROW_COLUMN);
		$this->assertStringStartsWith('_', ImportFailedRowsCsv::REASON_COLUMN);
	}
}
