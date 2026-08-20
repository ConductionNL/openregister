<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class MergeNodeTest extends TestCase {
	private MergeNode $node;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->node = new MergeNode($l, $this->createMock(IURLGenerator::class));
	}

	private function items(array $records): array {
		return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
	}

	public function testAppendKeepsEveryItem(): void {
		$out = $this->node->execute($this->items([['n' => 1], ['n' => 2]]), ['mode' => 'append'], []);
		$this->assertSame([1, 2], array_column(array_column($out, 'json'), 'n'));
	}

	public function testMergeByKeyGroupsAndEnriches(): void {
		$out = $this->node->execute(
			$this->items([
				['id' => 'x', 'a' => 1],
				['id' => 'y', 'a' => 9],
				['id' => 'x', 'b' => 2],
			]),
			['mode' => 'mergeByKey', 'key' => 'id'],
			[]
		);

		$this->assertCount(2, $out);
		// x's two records merged; the later branch's fields are present.
		$this->assertSame(['id' => 'x', 'a' => 1, 'b' => 2], $out[0]['json']);
		$this->assertSame(['id' => 'y', 'a' => 9], $out[1]['json']);
	}

	public function testUniqueDropsLaterDuplicates(): void {
		$out = $this->node->execute(
			$this->items([['id' => 'x', 'v' => 1], ['id' => 'x', 'v' => 2], ['id' => 'y', 'v' => 3]]),
			['mode' => 'unique', 'key' => 'id'],
			[]
		);

		$this->assertCount(2, $out);
		$this->assertSame(1, $out[0]['json']['v']); // first x kept
		$this->assertSame('y', $out[1]['json']['id']);
	}

	public function testAnUnknownModeIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['mode' => 'sideways']);
	}

	public function testAKeyedModeWithoutAKeyIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['mode' => 'mergeByKey']);
	}
}
