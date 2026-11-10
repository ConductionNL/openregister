<?php

/**
 * Unit tests for ResolvedSelection: the selection a job stores.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;
use OCA\OpenRegister\Service\BulkJob\ResolvedSelection;
use PHPUnit\Framework\TestCase;

class ResolvedSelectionTest extends TestCase {

	public function testAQuerySelectionStoresItsQueryNotItsUuids(): void {
		$selection = new BulkJobSelection(['query' => ['status' => 'open']], 1, 2);
		$this->assertSame(['query' => ['status' => 'open']], (new ResolvedSelection($selection, BulkJob::SELECTION_QUERY, ['a']))->stored());
	}//end testAQuerySelectionStoresItsQueryNotItsUuids()

	public function testAQuerySelectionWithoutAQueryStoresAnEmptyOne(): void {
		$this->assertSame(['query' => []], (new ResolvedSelection(new BulkJobSelection([], 1, 2), BulkJob::SELECTION_QUERY, []))->stored());
	}//end testAQuerySelectionWithoutAQueryStoresAnEmptyOne()

	public function testAnIdSelectionStoresTheResolvedUuids(): void {
		$selection = new BulkJobSelection(['ids' => ['a', 'a', 'b']], 1, 2);
		$this->assertSame(['ids' => ['a', 'b']], (new ResolvedSelection($selection, BulkJob::SELECTION_IDS, ['a', 'b']))->stored());
	}//end testAnIdSelectionStoresTheResolvedUuids()
}//end class
