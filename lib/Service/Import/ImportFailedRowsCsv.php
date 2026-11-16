<?php

/**
 * ImportFailedRowsCsv — the failed rows of an import run, in the shape they were submitted.
 *
 * Correcting the rows that did not land is the reason a run keeps them
 * (D-5). The export gives each skipped, refused or failed row back as it was
 * submitted, one column per source column, so it can be corrected and loaded
 * again as a retry. Two leading columns name the row and why it failed; they
 * start with `_`, which the importer ignores, so the corrected file loads
 * without removing them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewRowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Renders a run's failed rows as CSV, reading them a page at a time.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */
class ImportFailedRowsCsv {

	/**
	 * The rows read per page, so a large run is never assembled in memory (ADR-009).
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * The column naming the source row.
	 *
	 * @var string
	 */
	public const ROW_COLUMN = '_row';

	/**
	 * The column naming why the row failed.
	 *
	 * @var string
	 */
	public const REASON_COLUMN = '_reason';

	/**
	 * Constructor.
	 *
	 * @param ImportPreviewRowMapperInterface $rowMapper The run's rows.
	 */
	public function __construct(
		private readonly ImportPreviewRowMapperInterface $rowMapper,
	) {
	}//end __construct()

	/**
	 * The failed rows of a run as CSV, with a header row.
	 *
	 * The columns are every source column any failed row carried, in the
	 * order they were first seen; a row without one leaves its cell empty.
	 *
	 * @param ImportPreview $run The run.
	 *
	 * @return string The CSV.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-the-failed-rows-of-an-import-are-exportable-and-retryable-req-rcn-006
	 */
	public function render(ImportPreview $run): string {
		$runId = (int)$run->getId();
		$columns = $this->columns(runId: $runId);

		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, array_merge([self::ROW_COLUMN, self::REASON_COLUMN], $columns), ',', '"', '');
		$offset = 0;
		do {
			$rows = $this->rowMapper->findFailed(previewId: $runId, query: new ListQuery(limit: self::PAGE, offset: $offset));
			foreach ($rows as $row) {
				$source = ($row->getSource() ?? []);
				$line = [(string)$row->getSourceRow(), (string)$row->getReason()];
				foreach ($columns as $column) {
					$line[] = $this->cell(value: ($source[$column] ?? null));
				}

				fputcsv($handle, $line, ',', '"', '');
			}

			$offset += self::PAGE;
		} while (count($rows) === self::PAGE);

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end render()

	/**
	 * The source columns of a run's failed rows, in first-seen order.
	 *
	 * @param int $runId The run's id.
	 *
	 * @return array<int, string> The column names.
	 */
	private function columns(int $runId): array {
		$columns = [];
		$offset = 0;
		do {
			$rows = $this->rowMapper->findFailed(previewId: $runId, query: new ListQuery(limit: self::PAGE, offset: $offset));
			foreach ($rows as $row) {
				foreach (array_keys($row->getSource() ?? []) as $column) {
					$columns[(string)$column] = true;
				}
			}

			$offset += self::PAGE;
		} while (count($rows) === self::PAGE);

		return array_map('strval', array_keys($columns));
	}//end columns()

	/**
	 * One cell: a scalar as text, anything nested as JSON.
	 *
	 * @param mixed $value The source value.
	 *
	 * @return string The cell.
	 */
	private function cell(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end cell()
}//end class
