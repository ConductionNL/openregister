<?php

/**
 * Unit tests for the effective `next` hint of a finished macro run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\MacroActionResolver
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNextHint
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\FlowRun
 */
class MacroActionResolverNextForRunTest extends TestCase {

	private const TRIGGER = ['id' => 't', 'type' => 'openregister.trigger-manual', 'config' => ['next' => 'next']];

	/**
	 * Flows, run logs and the hint each must give.
	 *
	 * @return array<string, array{0: array<int, mixed>|null, 1: array<int, mixed>|null, 2: string}>
	 */
	public static function cases(): array {
		$endList = ['id' => 'e1', 'type' => 'openregister.end', 'config' => ['next' => 'list']];
		$endSilent = ['id' => 'e2', 'type' => 'openregister.end', 'config' => []];
		$endStay = ['id' => 'e3', 'type' => 'openregister.end', 'config' => ['next' => 'STAY ']];
		$endBlank = ['type' => 'openregister.end', 'config' => ['next' => 'list']];
		$nodes = [self::TRIGGER, $endList, $endSilent, $endStay];
		$reach = static fn (string $id): array => ['type' => 'openregister.end', 'transition' => $id];
		return [
			'unreadable flow' => [null, [$reach('e1')], 'stay'],
			'no log' => [$nodes, null, 'next'],
			'empty log' => [$nodes, [], 'next'],
			'no end reached' => [$nodes, [['type' => 'openregister.write', 'transition' => 'e1']], 'next'],
			'end with a hint' => [$nodes, [$reach('e1')], 'list'],
			'silent end' => [$nodes, [$reach('e2')], 'next'],
			'end hint read loosely' => [$nodes, [$reach('e3')], 'stay'],
			'last end wins' => [$nodes, [$reach('e1'), $reach('e2')], 'next'],
			'earlier end ignored' => [$nodes, [$reach('e2'), $reach('e1')], 'list'],
			'non-array entries skipped' => [$nodes, ['x', 3, $reach('e1')], 'list'],
			'unknown end id' => [$nodes, [$reach('zz')], 'next'],
			'end without transition matches an id-less node' => [[self::TRIGGER, $endBlank], [['type' => 'openregister.end']], 'list'],
			'end without transition and no id-less node' => [$nodes, [['type' => 'openregister.end']], 'next'],
			'non-array nodes skipped' => [['junk', self::TRIGGER, $endList], [$reach('e1')], 'list'],
			'no trigger, silent end' => [[$endSilent], [$reach('e2')], 'stay'],
			'no nodes' => [[], [$reach('e1')], 'stay'],
			'null nodes' => [[null], [$reach('e1')], 'stay'],
		];
	}

	/**
	 * @param array<int, mixed>|null $nodes The flow's nodes; null when the flow cannot be read.
	 * @param array<int, mixed>|null $log   The run's log.
	 * @param string                 $hint  The expected hint.
	 */
	#[DataProvider('cases')]
	public function testTheEffectiveHint(?array $nodes, ?array $log, string $hint): void {
		$flows = $this->createMock(FlowService::class);
		if ($nodes === null) {
			$flows->method('find')->willThrowException(new RuntimeException('gone'));
		} else {
			$flow = new Flow();
			$flow->setNodes($nodes === [null] ? null : $nodes);
			$flows->method('find')->with('flow-1')->willReturn($flow);
		}

		$run = new FlowRun();
		$run->setLog($log);

		$this->assertSame($hint, $this->resolver(flows: $flows)->nextForRun(flowUuid: 'flow-1', run: $run));
	}

	/**
	 * A resolver reading flows from the given service.
	 */
	private function resolver(FlowService $flows): MacroActionResolver {
		return new MacroActionResolver(
			$this->createMock(SchemaMapper::class),
			$flows
		);
	}
}
