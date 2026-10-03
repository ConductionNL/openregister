<?php

/**
 * The feed a reader is looking at, as a file they can keep.
 *
 * 🔑 IT EXPORTS THE PAGE IT WAS GIVEN, AND RESOLVES NOTHING. Zaaksysteem's
 * Tijdlijn exports what the filters produced, and the defect to avoid is an
 * export that re-queries and quietly returns something else: a reader who
 * filtered on notes and received every row has no way to tell whether the
 * filter or the export was wrong. So the caller hands over the rows it
 * rendered, and this writes those.
 *
 * 🔴 THE EXPORT IS NOT A SECOND READ, WHICH IS ALSO WHY IT ENFORCES NOTHING.
 * Access was decided by the object read that produced the rows; re-checking
 * here would be a second answer to that question, and re-fetching here would
 * be a second read with different filters. Both are how an export and a
 * screen come to disagree.
 *
 * 🔴 A CELL THAT STARTS WITH `=` IS A FORMULA IN A SPREADSHEET. A summary
 * beginning `=cmd|…` is executed by Excel when the file is opened, so every
 * cell that could start with one is prefixed. That is the one thing this
 * class does to the data, and it does it to text nobody typed expecting to
 * be run.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Writes a merged activity page as a file.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedExport {

	/**
	 * The columns, in the order a reader reads them.
	 *
	 * @var array<int,string>
	 */
	public const COLUMNS = ['when', 'kind', 'actor', 'action', 'summary', 'url'];

	/**
	 * The characters a spreadsheet treats as the start of a formula.
	 *
	 * @var array<int,string>
	 */
	private const FORMULA_STARTS = ['=', '+', '-', '@'];

	/**
	 * The filtered page as CSV.
	 *
	 * @param array<int,array<string,mixed>> $rows     The rows the caller rendered.
	 * @param string                         $timezone The zone the moments are written in.
	 *
	 * @return string The CSV document, header first.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-feed-filters-by-kind-and-period-and-exports
	 */
	public function toCsv(array $rows, string $timezone = 'Europe/Amsterdam'): string {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, self::COLUMNS);

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			fputcsv(
				$handle,
				[
					$this->moment(timestamp: (int)($row['timestamp'] ?? 0), timezone: $timezone),
					$this->cell(value: (string)($row['kind'] ?? '')),
					$this->cell(value: (string)($row['actor'] ?? '')),
					$this->cell(value: (string)($row['action'] ?? '')),
					$this->cell(value: (string)($row['summary'] ?? '')),
					$this->cell(value: (string)($row['url'] ?? '')),
				]
			);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end toCsv()

	/**
	 * One moment a reader can compare to their own calendar.
	 *
	 * A unix integer is what the feed sorts on and not what anybody reads,
	 * and a bare date would lose the evening: two rows an hour apart on one
	 * day are the sequence the feed exists to show.
	 *
	 * @param int    $timestamp The moment.
	 * @param string $timezone  The zone to write it in.
	 *
	 * @return string The moment, or an empty cell when the row carried none.
	 */
	private function moment(int $timestamp, string $timezone): string {
		if ($timestamp <= 0) {
			// An undated row exports as an empty cell rather than as 1970,
			// which reads as a real date and sorts as one in a spreadsheet.
			return '';
		}

		$zone = 'UTC';
		if (in_array($timezone, timezone_identifiers_list(), true) === true) {
			$zone = $timezone;
		}

		return (new DateTimeImmutable('@' . $timestamp))
			->setTimezone(new DateTimeZone($zone))
			->format('Y-m-d H:i');
	}//end moment()

	/**
	 * One cell, with nothing in it a spreadsheet will run.
	 *
	 * @param string $value The value.
	 *
	 * @return string The cell.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-feed-filters-by-kind-and-period-and-exports
	 */
	private function cell(string $value): string {
		if ($value === '') {
			return '';
		}

		if (in_array($value[0], self::FORMULA_STARTS, true) === true) {
			// The apostrophe is what Excel and LibreOffice both read as "this
			// is text". Stripping the character instead would change what a
			// summary says.
			return "'" . $value;
		}

		return $value;
	}//end cell()
}//end class
