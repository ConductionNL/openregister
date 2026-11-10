<?php

/**
 * Unit tests for the CSV of import errors.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use OCA\OpenRegister\Service\Import\ImportErrorsCsv;
use PHPUnit\Framework\TestCase;

/**
 * One row per error under a BOM and a header; scalars as text, structures as JSON, anything else blank.
 *
 * @covers \OCA\OpenRegister\Service\Import\ImportErrorsCsv
 */
class ImportErrorsCsvTest extends TestCase {

	/**
	 * Parse a rendered CSV back into rows, checking the BOM.
	 *
	 * @param string $csv The CSV.
	 *
	 * @return list<list<string>> The rows, header first.
	 */
	private function rows(string $csv): array {
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
		$lines = array_filter(explode("\n", substr($csv, 3)), static fn (string $l): bool => $l !== '');

		return array_values(array_map(static fn (string $l): array => str_getcsv($l, ',', '"', '\\'), $lines));

	}//end rows()

	/**
	 * Every error becomes a row under the header, with the sheet title as fallback.
	 *
	 * @return void
	 */
	public function testEveryErrorBecomesARow(): void {
		$stream = fopen('php://memory', 'r');
		$csv    = (new ImportErrorsCsv())->render(
			[
				'Personen' => [
					'errors' => [
						['row' => 3, 'field' => 'bsn', 'error' => 'Too short', 'original_value' => '12'],
						['row' => 4, 'type' => 'validation', 'error' => 'Invalid', 'object' => ['naam' => 'Jansen/Ä']],
						'Sheet unreadable',
						['row' => 5, 'error' => 'Odd value', 'object' => $stream],
					],
				],
				'Leeg' => ['errors' => 'not a list'],
			]
		);
		fclose($stream);

		$this->assertSame(
			[
				['sheet', 'row', 'field', 'error_message', 'original_value'],
				['Personen', '3', 'bsn', 'Too short', '12'],
				['Personen', '4', 'validation', 'Invalid', '{"naam":"Jansen/Ä"}'],
				['Personen', '', '', 'Sheet unreadable', ''],
				['Personen', '5', '', 'Odd value', ''],
			],
			$this->rows($csv)
		);

	}//end testEveryErrorBecomesARow()

	/**
	 * Without errors there is no CSV at all.
	 *
	 * @return void
	 */
	public function testNoErrorsIsNoCsv(): void {
		$this->assertSame('', (new ImportErrorsCsv())->render(['Personen' => ['errors' => []], 'Leeg' => []]));

	}//end testNoErrorsIsNoCsv()
}//end class
