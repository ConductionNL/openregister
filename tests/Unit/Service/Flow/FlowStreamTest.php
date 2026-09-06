<?php

/**
 * FlowStream path arithmetic: the run log's canonical order is a function of
 * the path taken, never of the timing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use LengthException;
use OCA\OpenRegister\Db\FlowStream;
use PHPUnit\Framework\TestCase;

/**
 * Ordinal paths.
 */
class FlowStreamTest extends TestCase {

	public function testChildPathsFollowDeclarationOrderAndSortAsATree(): void {
		$root = FlowStream::ROOT_PATH;
		$first = FlowStream::childPath(parentPath: $root, index: 1);
		$second = FlowStream::childPath(parentPath: $root, index: 2);
		$grandchild = FlowStream::childPath(parentPath: $first, index: 1);

		$this->assertSame('0001.0001', $first);
		$this->assertSame('0001.0002', $second);
		$this->assertSame('0001.0001.0001', $grandchild);

		// Lexicographic order IS tree order: a parent's own steps come before
		// its children's, and siblings sort by the author's declaration index.
		$paths = [$second, $grandchild, $root, $first];
		sort($paths, SORT_STRING);
		$this->assertSame([$root, $first, $grandchild, $second], $paths);
	}//end testChildPathsFollowDeclarationOrderAndSortAsATree()

	public function testTwoRunsWithOppositeTimingProduceIdenticalCanonicalOrder(): void {
		// Run 1: branch two finishes first; run 2: branch one finishes first.
		// The canonical key is (ordinal path, position within the stream) and
		// carries no trace of which branch returned first.
		$run1 = [
			['0001.0002', 1, 'hearing'],
			['0001.0001', 1, 'advice'],
			['0001', 1, 'split'],
		];
		$run2 = [
			['0001', 1, 'split'],
			['0001.0001', 1, 'advice'],
			['0001.0002', 1, 'hearing'],
		];
		$canonical = static function (array $rows): array {
			usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
			return $rows;
		};

		$this->assertSame($canonical($run1), $canonical($run2));
		$this->assertSame(['split', 'advice', 'hearing'], array_column($canonical($run1), 2));
	}//end testTwoRunsWithOppositeTimingProduceIdenticalCanonicalOrder()

	public function testAJoinFoldsBackToTheLongestCommonPrefix(): void {
		$this->assertSame('0002', FlowStream::commonPrefix(paths: ['0002.0001', '0002.0002']));
		$this->assertSame('0001.0003', FlowStream::commonPrefix(paths: ['0001.0003.0001', '0001.0003.0002.0001']));
		// Branches from different splits share the root, deterministically.
		$this->assertSame('0001', FlowStream::commonPrefix(paths: ['0001.0001', '0002.0001']));
		$this->assertSame(FlowStream::ROOT_PATH, FlowStream::commonPrefix(paths: []));
	}//end testAJoinFoldsBackToTheLongestCommonPrefix()

	public function testAPathThatWouldExceedTheColumnIsRefusedNotTruncated(): void {
		$path = FlowStream::ROOT_PATH;
		while (strlen($path) + 5 <= FlowStream::MAX_PATH_LENGTH) {
			$path = FlowStream::childPath(parentPath: $path, index: 1);
		}

		$this->expectException(LengthException::class);
		FlowStream::childPath(parentPath: $path, index: 1);
	}//end testAPathThatWouldExceedTheColumnIsRefusedNotTruncated()

	public function testStreamStatusReusesTheRunVocabulary(): void {
		$stream = new FlowStream();
		$stream->setStatus('failed');
		$this->assertTrue($stream->isTerminal());
		$stream->setStatus('suspended');
		$this->assertFalse($stream->isTerminal());
	}//end testStreamStatusReusesTheRunVocabulary()
}//end class
