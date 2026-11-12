<?php

/**
 * BackfillFlowVersions publishes version 1, pins runs, and sweeps orphans.
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
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Repair\BackfillFlowVersions;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPinInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The branches the paging test leaves: the skip, the empty instance, the
 * published-version row itself, the run pinning, and the orphan sweep.
 */
class BackfillFlowVersionsBehaviourTest extends TestCase {

	private FlowMapperInterface&MockObject $flows;
	private FlowVersionMapperInterface&MockObject $versions;
	private FlowDefinitionPinInterface&MockObject $pin;
	private FlowRunMapperInterface&MockObject $runs;
	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	/** @var list<string> */
	private array $info = [];

	/** @var list<string> */
	private array $warnings = [];

	/** @var list<FlowVersion> */
	private array $inserted = [];

	protected function setUp(): void {
		$this->flows = $this->createMock(FlowMapperInterface::class);
		$this->versions = $this->createMock(FlowVersionMapperInterface::class);
		$this->versions->method('insert')->willReturnCallback(function (FlowVersion $v): FlowVersion {
			$this->inserted[] = $v;
			return $v;
		});
		$this->pin = $this->createMock(FlowDefinitionPinInterface::class);
		$this->runs = $this->createMock(FlowRunMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(function (string $m): void {
			$this->info[] = $m;
		});
		$this->output->method('warning')->willReturnCallback(function (string $m): void {
			$this->warnings[] = $m;
		});
	}

	private function step(): BackfillFlowVersions {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				FlowMapperInterface::class => $this->flows,
				FlowVersionMapperInterface::class => $this->versions,
				FlowDefinitionPinInterface::class => $this->pin,
				FlowRunMapperInterface::class => $this->runs,
			}
		);

		return new BackfillFlowVersions($container, $this->logger);
	}

	private function flow(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setOwner('alice');
		$flow->setOrganisation('org-1');
		$flow->setNodes([['id' => 'n1']]);

		return $flow;
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$this->assertSame('Publish version 1 of every existing flow and pin in-flight runs to it', $this->step()->getName());
	}

	public function testAMissingFlowTableIsASkip(): void {
		$this->flows->method('findAllFlows')->willThrowException(new RuntimeException('no table'));

		$this->step()->run($this->output);

		$this->assertSame(['Flow version backfill skipped: no table'], $this->info);
	}

	public function testAnEmptyInstanceHasNothingToPublishAndSweepsNothing(): void {
		$this->flows->method('findAllFlows')->willReturn([]);
		$this->versions->expects($this->never())->method('deleteOrphaned');

		$this->step()->run($this->output);

		$this->assertSame(['Flow versions: no flows to publish.'], $this->info);
	}

	public function testPublishesVersionOneWithTheFlowsOwnershipAndPinsItsRuns(): void {
		$flow = $this->flow('f-new');
		$this->flows->method('findAllFlows')->willReturn([$flow]);
		$this->versions->method('highestVersion')->with('f-new')->willReturn(0);
		$this->pin->expects($this->once())->method('pin')
			->with(['nodes' => [['id' => 'n1']], 'edges' => [], 'limits' => [], 'executionMode' => Flow::MODE_ASYNC], 'f-new')
			->willReturn('hash-1');
		$this->flows->expects($this->once())->method('update')->with($flow);
		$this->runs->expects($this->once())->method('pinUnversionedActive')->with('f-new', 1)->willReturn(3);
		$this->versions->method('deleteOrphaned')->willReturn(0);

		$this->step()->run($this->output);

		$this->assertCount(1, $this->inserted);
		$version = $this->inserted[0];
		$this->assertSame('f-new', $version->getFlowUuid());
		$this->assertSame(1, $version->getVersion());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $version->getStatus());
		$this->assertSame('hash-1', $version->getDefinitionHash());
		$this->assertSame('alice', $version->getOwner());
		$this->assertSame('org-1', $version->getOrganisation());
		$this->assertSame(1, $flow->getVersion());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $flow->getLifecycleStatus());
		$this->assertSame(
			['Flow versions: published version 1 for 1 flow(s), 0 already versioned, pinned 3 in-flight run(s).'],
			$this->info
		);
	}

	public function testAnAlreadyVersionedFlowIsCountedAsSkippedAndOrphansAreReported(): void {
		$this->flows->method('findAllFlows')->willReturn([$this->flow('f-old')]);
		$this->versions->method('highestVersion')->willReturn(2);
		$this->pin->expects($this->never())->method('pin');
		$this->versions->method('deleteOrphaned')->willReturn(4);

		$this->step()->run($this->output);

		$this->assertSame([], $this->inserted);
		$this->assertSame(
			[
				'Flow versions: published version 1 for 0 flow(s), 1 already versioned, pinned 0 in-flight run(s).',
				'Flow versions: removed 4 orphaned version row(s).',
			],
			$this->info
		);
	}

	public function testAnUnstorableDefinitionIsWarnedAboutAndInsertsNothing(): void {
		$this->flows->method('findAllFlows')->willReturn([$this->flow('f-bad')]);
		$this->versions->method('highestVersion')->willReturn(0);
		$this->pin->method('pin')->willReturn(null);
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Could not publish flow "f-bad": the definition could not be stored'));

		$this->step()->run($this->output);

		$this->assertSame([], $this->inserted);
		$this->assertSame(['Flow "f-bad" could not be versioned: the definition could not be stored'], $this->warnings);
	}

	public function testAFailingOrphanSweepIsAWarningNotAFailure(): void {
		$this->flows->method('findAllFlows')->willReturn([$this->flow('f-old')]);
		$this->versions->method('highestVersion')->willReturn(1);
		$this->versions->method('deleteOrphaned')->willThrowException(new RuntimeException('locked'));

		$this->step()->run($this->output);

		$this->assertSame(['Orphaned flow versions could not be swept: locked'], $this->warnings);
	}
}
