<?php

/**
 * BackfillFlowSemanticVersions pages every flow, stamps only blanks and mirrors the live number.
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
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Repair\BackfillFlowSemanticVersions;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersionInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of the semantic-version backfill with the numbering stubbed, so
 * the step's own decisions are what is asserted.
 */
class BackfillFlowSemanticVersionsBehaviourTest extends TestCase {

	private FlowMapperInterface&MockObject $flowMapper;
	private FlowVersionMapperInterface&MockObject $versionMapper;
	private FlowSemanticVersionInterface&MockObject $semver;
	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	/** @var list<string> */
	private array $info = [];

	/** @var array<string, list<FlowVersion>> */
	private array $rows = [];

	/** @var list<FlowVersion> */
	private array $updatedRows = [];

	/** @var list<Flow> */
	private array $updatedFlows = [];

	/** @var list<array{0: int, 1: int}> */
	private array $pages = [];

	protected function setUp(): void {
		$this->flowMapper = $this->createMock(FlowMapperInterface::class);
		$this->flowMapper->method('update')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->updatedFlows[] = $flow;
				return $flow;
			}
		);
		$this->versionMapper = $this->createMock(FlowVersionMapperInterface::class);
		$this->versionMapper->method('findAllForFlow')->willReturnCallback(
			fn (string $uuid): array => $this->rows[$uuid] ?? []
		);
		$this->versionMapper->method('update')->willReturnCallback(
			function (FlowVersion $row): FlowVersion {
				$this->updatedRows[] = $row;
				return $row;
			}
		);
		$this->semver = $this->createMock(FlowSemanticVersionInterface::class);
		$this->semver->method('backfilled')->willReturnCallback(static fn (int $position): string => 'v' . $position);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);
	}

	/**
	 * @param list<Flow> $flows Every flow the mapper holds.
	 */
	private function runStep(array $flows): void {
		$this->flowMapper->method('findAllFlows')->willReturnCallback(
			function (FlowSelection $selection, ListQuery $query) use ($flows): array {
				$this->pages[] = [$query->limit, $query->offset];
				return array_slice($flows, (int)$query->offset, $query->limit);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				FlowMapperInterface::class => $this->flowMapper,
				FlowVersionMapperInterface::class => $this->versionMapper,
				FlowSemanticVersionInterface::class => $this->semver,
			}
		);

		(new BackfillFlowSemanticVersions($container, $this->logger))->run($this->output);
	}

	private function flow(string $uuid, ?string $semver = null): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setSemver($semver);

		return $flow;
	}

	private function row(int $ordinal, string $status, ?string $semver = null): FlowVersion {
		$row = new FlowVersion();
		$row->setVersion($ordinal);
		$row->setStatus($status);
		$row->setSemver($semver);

		return $row;
	}

	public function testPagesPastFiveHundredFlowsAndStopsOnAShortPage(): void {
		$flows = [];
		for ($i = 0; $i < 501; $i++) {
			$flows[] = $this->flow('f' . $i);
		}

		$this->runStep($flows);

		$this->assertSame([[500, 0], [500, 500]], $this->pages);
		$this->assertSame(['Numbered 0 published flow version(s) as back-filled; 0 flow(s) could not be read.'], $this->info);
	}

	public function testAnExactlyFullPageAsksForOneMoreEmptyPage(): void {
		$flows = [];
		for ($i = 0; $i < 500; $i++) {
			$flows[] = $this->flow('f' . $i);
		}

		$this->runStep($flows);

		$this->assertSame([[500, 0], [500, 500]], $this->pages);
	}

	public function testStampsBlanksOldestFirstSkipsDraftsAndKeepsExistingNumbers(): void {
		$third = $this->row(3, FlowVersion::STATUS_PUBLISHED);
		$draft = $this->row(4, FlowVersion::STATUS_DRAFT);
		$second = $this->row(2, FlowVersion::STATUS_DEPRECATED, '  ');
		$first = $this->row(1, FlowVersion::STATUS_DEPRECATED, '1.0.0');
		$this->rows['a'] = [$draft, $third, $second, $first];
		$flow = $this->flow('a');

		$this->runStep([$flow]);

		$this->assertSame([$second, $third], $this->updatedRows);
		$this->assertSame('v1', $second->getSemver());
		$this->assertSame('v2', $third->getSemver());
		$this->assertSame(FlowSemanticVersionInterface::SOURCE_BACKFILL, $third->getSemverSource());
		$this->assertSame('1.0.0', $first->getSemver());
		$this->assertNull($draft->getSemver());
		$this->assertSame([$flow], $this->updatedFlows);
		$this->assertSame('v2', $flow->getSemver());
		$this->assertSame(FlowSemanticVersionInterface::SOURCE_BACKFILL, $flow->getSemverSource());
		$this->assertSame(['Numbered 2 published flow version(s) as back-filled; 0 flow(s) could not be read.'], $this->info);
	}

	public function testAFlowThatAlreadyCarriesANumberIsNotMirroredOnto(): void {
		$this->rows['a'] = [$this->row(1, FlowVersion::STATUS_PUBLISHED)];

		$this->runStep([$this->flow('a', '2.0.0')]);

		$this->assertCount(1, $this->updatedRows);
		$this->assertSame([], $this->updatedFlows);
	}

	public function testAFlowWithoutAPublishedVersionIsNotMirroredOnto(): void {
		$this->rows['a'] = [$this->row(1, FlowVersion::STATUS_DEPRECATED)];

		$this->runStep([$this->flow('a')]);

		$this->assertCount(1, $this->updatedRows);
		$this->assertSame([], $this->updatedFlows);
	}

	public function testALiveVersionLeftWithoutANumberIsNotMirroredOnto(): void {
		$semver = $this->createMock(FlowSemanticVersionInterface::class);
		$semver->method('backfilled')->willReturn('');
		$this->semver = $semver;
		$this->rows['a'] = [$this->row(1, FlowVersion::STATUS_PUBLISHED)];

		$this->runStep([$this->flow('a')]);

		$this->assertSame([], $this->updatedFlows);
	}

	public function testAnUnreadableFlowIsCountedAndLoggedByUuid(): void {
		$versions = $this->createMock(FlowVersionMapperInterface::class);
		$versions->method('findAllForFlow')->willThrowException(new RuntimeException('gone'));
		$this->versionMapper = $versions;
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not stamp flow "bad": gone'));

		$this->runStep([$this->flow('bad')]);

		$this->assertSame(['Numbered 0 published flow version(s) as back-filled; 1 flow(s) could not be read.'], $this->info);
	}

	public function testAMissingServiceIsReportedAsASkip(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such table'));

		(new BackfillFlowSemanticVersions($container, $this->logger))->run($this->output);

		$this->assertSame(['Flow semantic-version backfill skipped: no such table'], $this->info);
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$step = new BackfillFlowSemanticVersions($this->createMock(ContainerInterface::class), $this->logger);

		$this->assertSame('Number existing flow versions semantically', $step->getName());
	}
}
