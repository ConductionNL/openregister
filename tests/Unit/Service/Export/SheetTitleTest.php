<?php

/**
 * Unit tests for SheetTitle.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Export\SheetTitle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SheetTitle::class)]
class SheetTitleTest extends TestCase {

	/**
	 * Slugs and the title each becomes.
	 *
	 * @return array<string, array{0: ?string, 1: string}>
	 */
	public static function slugs(): array {
		return [
			'a short slug is kept'                   => ['test-schema', 'test-schema'],
			'exactly 31 characters is kept'          => [str_repeat('a', 31), str_repeat('a', 31)],
			'a long slug is cut to 31'               => ['e2e-export-profile-schema-k3j9x2a1', 'e2e-export-profile-schema-k3j9x'],
			'forbidden characters become a dash'     => ['a:b\\c/d?e*f[g]h', 'a-b-c-d-e-f-g-h'],
			'apostrophes at either end are dropped'  => ["'quoted'", 'quoted'],
			'a multibyte slug is cut by character'   => [str_repeat('é', 40), str_repeat('é', 31)],
			'no schema is data'                      => [null, 'data'],
			'an empty slug is data'                  => ['', 'data'],
			'a slug of only apostrophes is data'     => ["'''", 'data'],
		];
	}

	#[DataProvider('slugs')]
	public function testTheTitleIsOneTheWorkbookAccepts(?string $slug, string $expected): void {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex(0);

		$title = SheetTitle::for($spreadsheet, $slug);

		$this->assertSame($expected, $title);
		// The workbook itself is the judge: setTitle() throws on a refused title.
		$this->assertSame($expected, $spreadsheet->createSheet()->setTitle($title)->getTitle());
	}

	public function testATakenTitleGetsANumberWithinTheLimit(): void {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex(0);
		$slug = 'e2e-export-profile-schema-k3j9x2a1';

		$titles = [];
		for ($i = 0; $i < 3; $i++) {
			$titles[] = $spreadsheet->createSheet()->setTitle(SheetTitle::for($spreadsheet, $slug))->getTitle();
		}

		$this->assertSame(
			['e2e-export-profile-schema-k3j9x', 'e2e-export-profile-schema-k (2)', 'e2e-export-profile-schema-k (3)'],
			$titles
		);
		foreach ($titles as $title) {
			$this->assertLessThanOrEqual(SheetTitle::MAX_LENGTH, mb_strlen($title));
		}
	}

	public function testATitleIsComparedWithoutCase(): void {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->removeSheetByIndex(0);
		$spreadsheet->createSheet()->setTitle('Cases');

		$this->assertSame('cases (2)', SheetTitle::for($spreadsheet, 'cases'));
	}
}
