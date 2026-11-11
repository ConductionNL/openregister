<?php

/**
 * BackfillFlowTriggerIndex reports skips, failures and every flow left on its trigger columns.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Repair\BackfillFlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndexInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The output branches of the trigger-index backfill: the existing paging test
 * covers the derivation itself.
 */
class BackfillFlowTriggerIndexReportTest extends TestCase {

	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	/** @var list<string> */
	private array $info = [];

	/** @var list<string> */
	private array $warnings = [];

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(function (string $m): void {
			$this->info[] = $m;
		});
		$this->output->method('warning')->willReturnCallback(function (string $m): void {
			$this->warnings[] = $m;
		});
	}

	private function step(array $report): BackfillFlowTriggerIndex {
		$flow = new Flow();
		$flow->setUuid('f-1');
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willReturn([$flow, new Flow()]);
		$index = $this->createMock(FlowTriggerIndexInterface::class);
		$index->method('rebuild')->willReturn($report);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[[FlowMapperInterface::class, $mapper], [FlowTriggerIndexInterface::class, $index]]
		);

		return new BackfillFlowTriggerIndex($container, $this->logger);
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$this->assertSame(
			'Derive the flow trigger index from each flow\'s trigger nodes',
			$this->step(['indexed' => 0, 'rows' => 0, 'unconverted' => []])->getName()
		);
	}

	public function testAContainerFailureIsASkip(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no flow table'));

		(new BackfillFlowTriggerIndex($container, $this->logger))->run($this->output);

		$this->assertSame(['Flow trigger index backfill skipped: no flow table'], $this->info);
	}

	public function testAFullyConvertedInstanceReportsOnlyTheTotals(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->step(['indexed' => 2, 'rows' => 3, 'unconverted' => []])->run($this->output);

		$this->assertSame(['Flow trigger index: 2 of 2 flows derived 3 trigger rows from their nodes.'], $this->info);
	}

	public function testEveryUnconvertedFlowIsListedAndLogged(): void {
		$this->logger->expects($this->exactly(2))->method('warning');

		$this->step(
			[
				'indexed' => 0,
				'rows' => 0,
				'unconverted' => [
					['uuid' => 'f-1', 'reason' => 'no trigger node', 'columns' => ['event' => 'created', 'register' => 5, 'schema' => 'zaak']],
					['uuid' => 'f-2', 'reason' => 'ambiguous', 'columns' => null],
				],
			]
		)->run($this->output);

		$this->assertSame(
			[
				'Flow trigger index: 0 of 2 flows derived 0 trigger rows from their nodes.',
				'2 flow(s) still match through their trigger COLUMNS and need re-authoring on the canvas:',
				'  f-1 — no trigger node (event=created register=5 schema=zaak)',
				'  f-2 — ambiguous (event=? register=? schema=?)',
			],
			$this->info
		);
		$this->assertSame([], $this->warnings);
	}
}
