<?php

/**
 * The renamed-node-type rewrite when things go wrong or run long: no flow
 * storage, a failing read, a failing save, and paging past a full page.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Repair\MigrateRenamedFlowNodeTypes;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\MigrateRenamedFlowNodeTypes
 * @covers \OCA\OpenRegister\Repair\FlowNodeTypeRewrite
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\Flow\FlowSelection
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class MigrateRenamedFlowNodeTypesFailuresTest extends TestCase {

	/**
	 * Lines written to the output, as "info: …" / "warning: …".
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * An output that records what it is told.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->lines[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->lines[] = 'warning: ' . $message;
		});
		return $output;
	}//end recordingOutput()

	/**
	 * A container that hands out the mapper.
	 *
	 * @param FlowMapper $mapper The mapper.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWith(FlowMapper $mapper): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		return $container;
	}//end containerWith()

	/**
	 * A stored flow with one node of the given type.
	 *
	 * @param string $type The node type.
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 */
	private static function flow(string $type, string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setNodes([['id' => 'n0', 'type' => $type]]);
		return $flow;
	}//end flow()

	public function testTheNameSaysWhatItRewrites(): void {
		$step = new MigrateRenamedFlowNodeTypes($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$this->assertSame('Rewrite renamed flow node types in stored flow definitions', $step->getName());
	}//end testTheNameSaysWhatItRewrites()

	public function testWithoutFlowStorageTheStepIsSkippedQuietly(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no flows app'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('[MigrateRenamedFlowNodeTypes] flow storage unavailable, skipping: no flows app');

		(new MigrateRenamedFlowNodeTypes($container, $logger))->run($this->recordingOutput());

		$this->assertSame([], $this->lines);
	}//end testWithoutFlowStorageTheStepIsSkippedQuietly()

	public function testAFailingReadWarnsAndReportsNothing(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willThrowException(new RuntimeException('table locked'));
		$mapper->expects($this->never())->method('update');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[MigrateRenamedFlowNodeTypes] read failed: table locked');

		(new MigrateRenamedFlowNodeTypes($this->containerWith($mapper), $logger))->run($this->recordingOutput());

		$this->assertSame(['warning: OpenRegister: could not read flows to rewrite node types: table locked'], $this->lines);
	}//end testAFailingReadWarnsAndReportsNothing()

	public function testAFlowThatCannotBeSavedIsWarnedAboutAndNotCounted(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			static fn (FlowSelection $selection, ListQuery $query): array => ($query->offset === 0) ? [self::flow('openregister.stop', 'flow-x')] : []
		);
		$mapper->expects($this->once())->method('update')->willThrowException(new RuntimeException('read-only'));

		(new MigrateRenamedFlowNodeTypes($this->containerWith($mapper), $this->createMock(LoggerInterface::class)))->run($this->recordingOutput());

		$this->assertSame(
			[
				'warning: OpenRegister: could not rewrite node types on flow "flow-x": read-only',
				'info: OpenRegister: 1 flow(s) checked, no renamed node types stored.',
			],
			$this->lines
		);
	}//end testAFlowThatCannotBeSavedIsWarnedAboutAndNotCounted()

	public function testAFullPageIsFollowedByTheNextUntilAnEmptyOne(): void {
		$offsets = [];
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			static function (FlowSelection $selection, ListQuery $query) use (&$offsets): array {
				$offsets[] = [$query->offset, $query->limit];
				if ($query->offset >= 200) {
					return [];
				}

				return array_map(static fn (int $i): Flow => self::flow('openregister.end', 'f-' . ($query->offset + $i)), range(1, 100));
			}
		);
		$mapper->expects($this->never())->method('update');

		(new MigrateRenamedFlowNodeTypes($this->containerWith($mapper), $this->createMock(LoggerInterface::class)))->run($this->recordingOutput());

		$this->assertSame([[0, 100], [100, 100], [200, 100]], $offsets);
		$this->assertSame(['info: OpenRegister: 200 flow(s) checked, no renamed node types stored.'], $this->lines);
	}//end testAFullPageIsFollowedByTheNextUntilAnEmptyOne()
}//end class
