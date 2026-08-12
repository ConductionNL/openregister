<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class LoopNodeTest extends TestCase {
	private LoopNode $node;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->node = new LoopNode($l, $this->createMock(IURLGenerator::class));
	}

	private function items(int $count): array {
		$out = [];
		for ($i = 0; $i < $count; $i++) {
			$out[] = FlowItems::item(json: ['n' => $i]);
		}
		return $out;
	}

	public function testItSplitsIntoBatchesOfTheConfiguredSize(): void {
		$out = $this->node->execute($this->items(7), ['batchSize' => 3], []);

		// 7 items in batches of 3 -> 3 batches (3, 3, 1).
		$this->assertCount(3, $out);
		$this->assertSame(3, $out[0]['json']['batchCount']);
		$this->assertCount(3, $out[0]['json']['items']);
		$this->assertCount(1, $out[2]['json']['items']);
		$this->assertSame(0, $out[0]['json']['batchIndex']);
		$this->assertSame(2, $out[2]['json']['batchIndex']);
	}

	public function testNoItemsYieldsNoBatches(): void {
		$this->assertSame([], $this->node->execute([], ['batchSize' => 5], []));
	}

	public function testANonPositiveBatchSizeIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['batchSize' => 0]);
	}

	public function testItIsAvailableInBothScopes(): void {
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
	}
}
