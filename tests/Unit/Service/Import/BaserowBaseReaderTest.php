<?php

/**
 * Unit tests for BaserowBaseReader: a Baserow database export read into a neutral set of tables.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\BaseColumn;
use OCA\OpenRegister\Service\Import\BaserowBaseReader;
use OCA\OpenRegister\Service\Import\BaserowCells;
use OCA\OpenRegister\Service\Import\BaseSet;
use OCA\OpenRegister\Service\Import\BaseSetReader;
use OCA\OpenRegister\Service\Import\BaseTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(BaserowBaseReader::class)]
#[CoversClass(BaseSetReader::class)]
#[UsesClass(BaseSet::class)]
#[UsesClass(BaseTable::class)]
#[UsesClass(BaseColumn::class)]
#[CoversClass(BaserowCells::class)]
final class BaserowBaseReaderTest extends TestCase {

	private const FIXTURE = __DIR__.'/../../../Fixtures/import/baserow-projecten-taken.json';

	/** @var array<int, string> */
	private array $temporary = [];

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
	}

	public function testTheJsonExportReadsAsTwoTablesWithTypedColumns(): void {
		$set = (new BaserowBaseReader())->read(self::FIXTURE);

		$this->assertSame('Projectbeheer', $set->name);
		$this->assertSame('baserow', $set->source);
		$this->assertSame(['Projecten', 'Taken'], array_keys($set->tables));

		$taken = $set->table('Taken');
		$types = [];
		foreach ($taken->columns as $column) {
			$types[$column->name] = $column->type;
		}

		$this->assertSame(
			['Titel' => 'string', 'Project' => 'link', 'Status' => 'string', 'Deadline' => 'date', 'Afgerond' => 'boolean'],
			$types
		);
		$this->assertSame('Projecten', $taken->columns[1]->linkTable);
		$this->assertFalse($taken->columns[1]->multiple);
		$this->assertTrue($taken->columns[0]->primary);
	}

	public function testComputedFieldsAreLeftOutAndNumbersAreNumbers(): void {
		$projecten = (new BaserowBaseReader())->read(self::FIXTURE)->table('Projecten');

		$this->assertSame(['Naam', 'Budget'], array_map(static fn (BaseColumn $c): string => $c->name, $projecten->columns));
		$this->assertSame(BaseColumn::TYPE_INTEGER, $projecten->columns[1]->type);
		$this->assertSame(['Naam' => 'Riolering', 'Budget' => 12000], $projecten->rows['1']);
	}

	public function testRowValuesCarryLinkIdsAndSelectLabels(): void {
		$taken = (new BaserowBaseReader())->read(self::FIXTURE)->table('Taken');

		$this->assertSame(
			['Titel' => 'Graven', 'Project' => ['1'], 'Status' => 'nieuw', 'Deadline' => '2026-12-01', 'Afgerond' => false],
			$taken->rows['1']
		);
		$this->assertSame('klaar', $taken->rows['2']['Status']);
		$this->assertNull($taken->rows['3']['Status']);
		$this->assertTrue($taken->hasRow('3'));
		$this->assertFalse($taken->hasRow('4'));
	}

	public function testAZipArchiveReadsTheSameAsItsJson(): void {
		$zip = $this->zipWith(['export.json' => (string) file_get_contents(self::FIXTURE), 'README.txt' => 'not json']);

		$set = (new BaserowBaseReader())->read($zip);

		$this->assertEquals((new BaserowBaseReader())->read(self::FIXTURE), $set);
	}

	public function testAnExportWithTwoDatabasesIsRefusedNamingThem(): void {
		$applications = json_decode((string) file_get_contents(self::FIXTURE), true);
		$second       = $applications[0];
		$second['name'] = 'Tweede';
		$applications[] = $second;

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('2 databases ("Projectbeheer", "Tweede")');

		(new BaserowBaseReader())->read($this->fileWith(json_encode($applications)));
	}

	public function testAFileWithoutADatabaseIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not a Baserow export');

		(new BaserowBaseReader())->read($this->fileWith('[{"type": "builder", "name": "Site"}]'));
	}

	public function testAMissingFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be read');

		(new BaserowBaseReader())->read('/nonexistent/base.zip');
	}

	public function testTheFacadeReadsByFormatAndRefusesAnUnknownOneNamingTheKnownOnes(): void {
		$reader = new BaseSetReader(new BaserowBaseReader());

		$this->assertSame(['baserow'], $reader->formats());
		$this->assertSame('Projectbeheer', $reader->read(' Baserow ', self::FIXTURE)->name);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('format "grist" cannot be read; readable formats: baserow.');
		$reader->read('grist', self::FIXTURE);
	}

	private function fileWith(string $contents): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'base');
		file_put_contents($path, $contents);
		$this->temporary[] = $path;
		return $path;
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function zipWith(array $entries): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'base');
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($entries as $name => $contents) {
			$zip->addFromString($name, $contents);
		}

		$zip->close();
		$this->temporary[] = $path;
		return $path;
	}
}
