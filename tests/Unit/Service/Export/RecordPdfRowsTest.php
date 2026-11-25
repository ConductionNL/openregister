<?php

/**
 * Unit tests for RecordPdfRows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Service\Export\RecordPdfRows;
use OCA\OpenRegister\Service\Export\RowsTable;
use PHPUnit\Framework\TestCase;

/**
 * One record's readable fields become label and value rows.
 *
 * @covers \OCA\OpenRegister\Service\Export\RecordPdfRows
 * @uses   \OCA\OpenRegister\Service\Export\RowsTable
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */
final class RecordPdfRowsTest extends TestCase {

	/**
	 * Fields in schema order with their titles, the id first; metadata, invisible and unreadable fields left out.
	 *
	 * @return void
	 */
	public function testReadableFieldsBecomeLabelledRows(): void {
		$asked = [];
		$table = (new RecordPdfRows())->table(
			title: 'Z-2026-0042',
			uuid: 'uuid-42',
			properties: [
				'uuid' => ['type' => 'string'],
				'omschrijving' => ['type' => 'string', 'title' => 'Omschrijving'],
				'status' => ['type' => 'string', 'title' => '  '],
				'bsn' => ['type' => 'string', 'title' => 'BSN'],
				'intern' => ['type' => 'string', 'visible' => false],
				'startdatum' => 'not a definition',
			],
			readable: static function (string $name) use (&$asked): bool {
				$asked[] = $name;
				return $name !== 'bsn';
			},
			value: static fn (string $name): ?string => ['omschrijving' => 'Bouwvergunning', 'status' => 'open'][$name] ?? null
		);

		$this->assertInstanceOf(RowsTable::class, $table);
		$this->assertSame('Z-2026-0042', $table->title);
		$this->assertSame(['field' => 'Field', 'value' => 'Value'], $table->columns);
		$this->assertSame(
			[
				['field' => 'ID', 'value' => 'uuid-42'],
				['field' => 'Omschrijving', 'value' => 'Bouwvergunning'],
				['field' => 'status', 'value' => 'open'],
				['field' => 'startdatum', 'value' => null],
			],
			$table->rows
		);
		$this->assertSame(['omschrijving', 'status', 'bsn', 'startdatum'], $asked, 'Metadata and invisible fields are not even asked about.');
	}//end testReadableFieldsBecomeLabelledRows()

	/**
	 * A record without an id and a reader that allows nothing give an empty table.
	 *
	 * @return void
	 */
	public function testNothingReadableGivesNoRows(): void {
		$table = (new RecordPdfRows())->table(
			title: 'Record',
			uuid: null,
			properties: ['a' => ['type' => 'string']],
			readable: static fn (string $name): bool => false,
			value: static fn (string $name): ?string => 'x'
		);

		$this->assertSame([], $table->rows);
	}//end testNothingReadableGivesNoRows()
}//end class
