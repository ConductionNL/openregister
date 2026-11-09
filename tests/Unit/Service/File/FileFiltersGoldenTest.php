<?php

/**
 * Golden record of which files FileListFilter::apply() (formerly FileFormattingHandler::applyFileFilters()) keeps for each filter set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\File;

use OCA\OpenRegister\Service\File\FileListFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\FileListFilter
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class FileFiltersGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/file-filters-golden.json';

	private static function files(): array {
		return [
			'a' => ['title' => 'Annual Report.pdf', 'extension' => 'pdf', 'size' => 1000, 'labels' => ['public', 'finance']],
			'b' => ['title' => 'photo.JPG', 'extension' => 'JPG', 'size' => 50, 'labels' => []],
			'c' => ['title' => 'notes.txt', 'extension' => 'txt', 'size' => 5000],
			'd' => ['labels' => ['draft']],
			'e' => ['title' => 'report-final.PDF', 'extension' => 'PDF', 'size' => 0, 'labels' => ['finance']],
		];
	}//end files()

	private static function filterSets(): array {
		return [
			'none' => [],
			'hasLabels true' => ['_hasLabels' => true],
			'hasLabels false' => ['_hasLabels' => false],
			'noLabels true' => ['_noLabels' => true],
			'noLabels false' => ['_noLabels' => false],
			'noLabels truthy' => ['_noLabels' => 1],
			'labels' => ['labels' => ['finance', 'missing']],
			'labels none match' => ['labels' => ['missing']],
			'labels empty' => ['labels' => []],
			'extension' => ['extension' => 'PdF'],
			'extension empty' => ['extension' => ''],
			'extensions' => ['extensions' => ['jpg', 'TXT']],
			'extensions empty' => ['extensions' => []],
			'minSize' => ['minSize' => 50],
			'maxSize' => ['maxSize' => 1000],
			'size range' => ['minSize' => 1, 'maxSize' => 1000],
			'minSize zero' => ['minSize' => 0],
			'title' => ['title' => 'REPORT'],
			'title empty' => ['title' => ''],
			'search' => ['search' => 'final'],
			'search zero string' => ['search' => '0'],
			'combined' => ['labels' => ['finance'], 'extension' => 'pdf', 'maxSize' => 999, 'title' => 'report'],
			'null values ignored' => ['extension' => null, 'minSize' => null, '_hasLabels' => null],
		];
	}//end filterSets()

	public function testFiltersMatchGolden(): void {
		$handler = new FileListFilter();
		$method = new \ReflectionMethod($handler, 'apply');
		$actual = [];
		foreach (self::filterSets() as $name => $filters) {
			$actual[$name] = array_keys($method->invoke($handler, self::files(), $filters));
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testFiltersMatchGolden()
}//end class
