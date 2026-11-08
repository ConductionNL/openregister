<?php

/**
 * ObjectSlugBatchRun unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatchRun;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope;
use PHPUnit\Framework\TestCase;

/**
 * The state a bulk group decides against, extended row by row.
 */
final class ObjectSlugBatchRunTest extends TestCase {

	private function batchRun(array $holders, array $stored): ObjectSlugBatchRun {
		return new ObjectSlugBatchRun(
			new ObjectSlugScope(null, new Schema()),
			['holders' => $holders, 'stored' => $stored],
			static fn (string $slug): string => mb_strtolower(trim($slug))
		);
	}//end batchRun()

	public function testHoldersAreComparedCaseFolded(): void {
		$run = $this->batchRun(['Hello' => ['a']], []);

		$this->assertSame('a', $run->otherHolder(' hello', 'b'));
		$this->assertNull($run->otherHolder('HELLO', 'a'));
		$this->assertNull($run->otherHolder('other', 'b'));
	}

	public function testStoredSlugNeedsAUuid(): void {
		$run = $this->batchRun([], ['a' => 'kept', 'b' => null]);

		$this->assertSame('kept', $run->storedSlug('a'));
		$this->assertNull($run->storedSlug('b'));
		$this->assertNull($run->storedSlug('c'));
		$this->assertNull($run->storedSlug(null));
	}

	public function testAClaimedSlugIsTakenForLaterRows(): void {
		$run = $this->batchRun([], []);

		$run->claim(['uuid' => 'a', 'batchHolder' => 'batch-0'], 'Taken');
		$run->claim(['uuid' => null, 'batchHolder' => 'batch-1'], 'fresh');
		$run->claim(['uuid' => 'c', 'batchHolder' => 'batch-2'], null);

		$this->assertSame('a', $run->otherHolder('taken', 'b'));
		$this->assertSame('Taken', $run->storedSlug('a'));
		$this->assertSame('batch-1', $run->otherHolder('fresh', null));
		$this->assertNull($run->storedSlug('c'));
	}
}//end class
