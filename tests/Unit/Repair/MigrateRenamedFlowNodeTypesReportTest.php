<?php

/**
 * What the renamed-node-type rewrite says when it is done, and when a save fails.
 *
 * Pins the summary line and the per-flow failure warning, so the step can be
 * restructured without changing what an administrator reads during an upgrade.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Repair\MigrateRenamedFlowNodeTypes;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\MigrateRenamedFlowNodeTypes
 * @uses \OCA\OpenRegister\Db\Flow
 */
final class MigrateRenamedFlowNodeTypesReportTest extends TestCase {

	/**
	 * Everything the step printed, as "info: ..." / "warning: ...".
	 *
	 * @var array<int, string>
	 */
	private array $printed = [];

	/**
	 * Run the step over the flows, failing the save of the uuids listed.
	 *
	 * @param array<int, Flow>   $flows   The stored flows.
	 * @param array<int, string> $failing Uuids whose save throws.
	 *
	 * @return void
	 */
	private function runOver(array $flows, array $failing = []): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			static function (FlowSelection $selection, ListQuery $query) use ($flows): array {
				return array_slice($flows, (int)$query->offset, $query->limit);
			}
		);
		$mapper->method('update')->willReturnCallback(
			static function (Flow $flow) use ($failing): Flow {
				if (in_array($flow->getUuid(), $failing, true) === true) {
					throw new RuntimeException('disk full');
				}

				return $flow;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->printed[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->printed[] = 'warning: ' . $message;
			}
		);

		(new MigrateRenamedFlowNodeTypes($container, new NullLogger()))->run($output);
	}//end runOver()

	/**
	 * A flow whose nodes carry these types.
	 *
	 * @param array<int, string> $types The node types.
	 * @param string             $uuid  The flow uuid.
	 *
	 * @return Flow The flow.
	 */
	private function flowWith(array $types, string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$nodes = [];
		foreach ($types as $index => $type) {
			$nodes[] = ['id' => 'n' . $index, 'type' => $type];
		}

		$flow->setNodes($nodes);

		return $flow;
	}//end flowWith()

	/**
	 * The renames, as the summary line lists them.
	 *
	 * @return string The pairs.
	 */
	private function pairs(): string {
		$pairs = [];
		foreach (FlowNodeRegistryInterface::RENAMED_TYPES as $old => $new) {
			$pairs[] = $old . ' → ' . $new;
		}

		return implode(', ', $pairs);
	}//end pairs()

	/**
	 * Nothing to rewrite: the count of flows checked, and nothing else.
	 *
	 * @return void
	 */
	public function testNothingStoredSaysHowManyWereChecked(): void {
		$this->runOver([$this->flowWith(['openregister.batch'], 'a'), $this->flowWith([], 'b')]);

		$this->assertSame(['info: OpenRegister: 2 flow(s) checked, no renamed node types stored.'], $this->printed);
	}//end testNothingStoredSaysHowManyWereChecked()

	/**
	 * A rewrite: nodes, flows written and flows seen, with every rename named.
	 *
	 * @return void
	 */
	public function testARewriteCountsNodesFlowsAndNamesTheRenames(): void {
		$old = array_key_first(FlowNodeRegistryInterface::RENAMED_TYPES);
		$this->runOver(
			[
				$this->flowWith([$old, $old], 'a'),
				$this->flowWith(['openregister.batch'], 'b'),
				$this->flowWith([$old], 'c'),
			]
		);

		$this->assertSame(
			['info: OpenRegister: rewrote 3 node(s) across 2 flow(s) of 3 to their current type ids (' . $this->pairs() . ').'],
			$this->printed
		);
	}//end testARewriteCountsNodesFlowsAndNamesTheRenames()

	/**
	 * A failed save is named, and its nodes are not counted as rewritten.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsWarnedAndNotCounted(): void {
		$old = array_key_first(FlowNodeRegistryInterface::RENAMED_TYPES);
		$this->runOver([$this->flowWith([$old], 'a'), $this->flowWith([$old, $old], 'b')], ['b']);

		$this->assertSame(
			[
				'warning: OpenRegister: could not rewrite node types on flow "b": disk full',
				'info: OpenRegister: rewrote 1 node(s) across 1 flow(s) of 2 to their current type ids (' . $this->pairs() . ').',
			],
			$this->printed
		);
	}//end testAFailedSaveIsWarnedAndNotCounted()

	/**
	 * Only failed saves: the step says it checked them and stored nothing.
	 *
	 * @return void
	 */
	public function testOnlyFailedSavesReportNothingStored(): void {
		$old = array_key_first(FlowNodeRegistryInterface::RENAMED_TYPES);
		$this->runOver([$this->flowWith([$old], 'a')], ['a']);

		$this->assertSame(
			[
				'warning: OpenRegister: could not rewrite node types on flow "a": disk full',
				'info: OpenRegister: 1 flow(s) checked, no renamed node types stored.',
			],
			$this->printed
		);
	}//end testOnlyFailedSavesReportNothingStored()
}//end class
