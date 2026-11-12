<?php

/**
 * Unit tests for FileListFilter: the page of a filtered listing.
 *
 * The filters themselves are pinned by FileFiltersGoldenTest and
 * FileFilterParametersGoldenTest.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileListFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\FileListFilter
 */
class FileListFilterTest extends TestCase {

	public function testPageReadsUnderscoreParametersFirstAndReindexes(): void {
		$files = [3 => ['id' => 'a'], 5 => ['id' => 'b'], 8 => ['id' => 'c']];

		$page = (new FileListFilter())->page(files: $files, requestParams: ['_page' => 2, 'page' => 9, '_limit' => 2, 'limit' => 1]);

		$this->assertSame(
			['results' => [['id' => 'c']], 'total' => 3, 'page' => 2, 'pages' => 2, 'limit' => 2, 'offset' => 2],
			$page
		);
	}//end testPageReadsUnderscoreParametersFirstAndReindexes()

	public function testPageFloorsAtOneAndDefaultsToThirty(): void {
		$filter = new FileListFilter();

		$this->assertSame(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 30, 'offset' => 0], $filter->page(files: [], requestParams: []));
		$this->assertSame(1, $filter->page(files: [], requestParams: ['page' => -4, 'limit' => 0])['limit']);
		$this->assertSame(1, $filter->page(files: [], requestParams: ['page' => -4, 'limit' => 0])['page']);
	}//end testPageFloorsAtOneAndDefaultsToThirty()

	public function testNoFiltersKeepsEveryFile(): void {
		$files = [['title' => 'a'], ['title' => 'b']];

		$this->assertSame($files, (new FileListFilter())->apply(files: $files, filters: []));
	}//end testNoFiltersKeepsEveryFile()
}//end class
