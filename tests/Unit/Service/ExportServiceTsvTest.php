<?php

/**
 * ExportService TSV export test.
 *
 * Pins that exportToTsv() writes a tab-delimited document whose values survive
 * a round trip through a spreadsheet reader, including a value that itself
 * holds a tab, a line break and a double quote. From the change
 * export-open-formats-and-public-download (task 1.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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
 * @spec openspec/specs/data-import-export/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectExportRequest;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExportService
 */
class ExportServiceTsvTest extends TestCase {

	private ExportService $service;

	private ObjectService $objectService;

	private PropertyRbacHandler $propertyRbacHandler;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

		$this->service = new ExportService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->objectService,
			$this->createMock(CacheHandler::class),
			$this->propertyRbacHandler,
		);
	}//end setUp()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('notes');
		$schema->setProperties(['title' => ['type' => 'string'], 'body' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	public function testTsvRefusesMultipleSchemas(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot export multiple schemas to TSV');

		$this->service->exportToTsv(request: new ObjectExportRequest(register: new Register()));
	}//end testTsvRefusesMultipleSchemas()

	public function testTsvRoundTripsAwkwardValue(): void {
		$awkward = "line one\tstill one\nline \"two\"";
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setName('First');
		$object->setObject(['title' => 'Alpha', 'body' => $awkward]);

		$this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
		$this->objectService->method('searchObjects')->willReturn([$object]);

		$tsv = $this->service->exportToTsv(request: new ObjectExportRequest(schema: $this->schema()));

		$rows = $this->readTsv(tsv: $tsv);

		// Row 1 is the header; the body column holds the awkward value verbatim.
		$header = $rows[0];
		$bodyColumn = array_search('body', $header, true);
		$this->assertNotFalse($bodyColumn, 'the body column is present');
		$this->assertSame($awkward, $rows[1][$bodyColumn]);
		$this->assertSame('Alpha', $rows[1][array_search('title', $header, true)]);
	}//end testTsvRoundTripsAwkwardValue()

	/**
	 * Read a TSV string back into rows through PhpSpreadsheet's reader, set to
	 * the tab delimiter, exactly as a spreadsheet opening the file would.
	 *
	 * @param string $tsv The TSV content.
	 *
	 * @return array<int, array<int, string>> The rows.
	 */
	private function readTsv(string $tsv): array {
		$path = tempnam(sys_get_temp_dir(), 'tsv');
		file_put_contents($path, $tsv);

		$reader = new CsvReader();
		$reader->setDelimiter("\t");
		$reader->setEnclosure('"');
		$spreadsheet = $reader->load($path);
		unlink($path);

		$rows = [];
		foreach ($spreadsheet->getActiveSheet()->toArray() as $row) {
			$rows[] = array_map(static fn ($value): string => (string) $value, $row);
		}

		return $rows;
	}//end readTsv()
}//end class
