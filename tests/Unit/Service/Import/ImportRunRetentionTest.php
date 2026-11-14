<?php

/**
 * Unit tests for ImportRunRetention — the prune that keeps the run and its counts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapperInterface;
use OCA\OpenRegister\Db\ImportPreviewRowMapperInterface;
use OCA\OpenRegister\Service\Import\ImportRunRetention;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class ImportRunRetentionTest extends TestCase {

	private function appConfig(int $days): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')
			->with('openregister', ImportRunRetention::CONFIG_KEY, ImportRunRetention::DEFAULT_DAYS)
			->willReturn($days);

		return $config;
	}

	private function aRun(int $id): ImportPreview {
		$run = new ImportPreview();
		$run->setId($id);
		$run->setUuid('run-'.$id);
		$run->setToCreate(800);
		$run->setApplied(800);

		return $run;
	}

	public function testAnOldRunLosesItsRowsAndKeepsItsHeaderAndCounts(): void {
		$now = new DateTimeImmutable('2026-11-14T03:00:00+00:00');
		$old = $this->aRun(3);

		$runs = $this->createMock(ImportPreviewMapperInterface::class);
		$runs->expects($this->once())->method('findUnprunedBefore')
			->willReturnCallback(
				function (DateTime $before, int $limit) use ($old): array {
					$this->assertSame('2026-10-15T03:00:00+00:00', $before->format(DATE_ATOM));
					$this->assertSame(50, $limit);

					return [$old];
				}
			);
		$persisted = [];
		$runs->method('persist')->willReturnCallback(
			static function (ImportPreview $run) use (&$persisted): ImportPreview {
				$persisted[] = $run;

				return $run;
			}
		);

		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);
		$rows->expects($this->once())->method('deleteByPreview')->with(3);

		$pruned = (new ImportRunRetention($runs, $rows, $this->appConfig(30)))->prune($now);

		$this->assertSame(1, $pruned);
		$this->assertSame([$old], $persisted);
		$this->assertSame('2026-11-14T03:00:00+00:00', $old->getRowsPrunedAt()?->format(DATE_ATOM));
		// The header and its counts survive, and the run says its detail went.
		$json = $old->jsonSerialize();
		$this->assertTrue($json['rowsPruned']);
		$this->assertSame(800, $json['counts']['created']);
		$this->assertSame('run-3', $json['uuid']);
	}

	public function testAFullBatchIsFollowedByAnotherUntilAShortOne(): void {
		$first = [];
		for ($i = 1; $i <= 50; $i++) {
			$first[] = $this->aRun($i);
		}

		$runs = $this->createMock(ImportPreviewMapperInterface::class);
		$runs->expects($this->exactly(2))->method('findUnprunedBefore')
			->willReturnOnConsecutiveCalls($first, [$this->aRun(51)]);
		$runs->method('persist')->willReturnArgument(0);

		$deleted = [];
		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);
		$rows->method('deleteByPreview')->willReturnCallback(
			static function (int $id) use (&$deleted): void {
				$deleted[] = $id;
			}
		);

		$pruned = (new ImportRunRetention($runs, $rows, $this->appConfig(90)))->prune(new DateTimeImmutable());

		$this->assertSame(51, $pruned);
		$this->assertSame(range(1, 51), $deleted);
	}

	public function testThePassStopsAtItsBatchLimit(): void {
		$full = [];
		for ($i = 1; $i <= 50; $i++) {
			$full[] = $this->aRun($i);
		}

		$runs = $this->createMock(ImportPreviewMapperInterface::class);
		$runs->expects($this->exactly(2))->method('findUnprunedBefore')->willReturn($full);
		$runs->method('persist')->willReturnArgument(0);
		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);

		$this->assertSame(100, (new ImportRunRetention($runs, $rows, $this->appConfig(90)))->prune(new DateTimeImmutable(), 2));
	}

	public function testNothingPastThePeriodPrunesNothing(): void {
		$runs = $this->createMock(ImportPreviewMapperInterface::class);
		$runs->method('findUnprunedBefore')->willReturn([]);
		$runs->expects($this->never())->method('persist');
		$rows = $this->createMock(ImportPreviewRowMapperInterface::class);
		$rows->expects($this->never())->method('deleteByPreview');

		$this->assertSame(0, (new ImportRunRetention($runs, $rows, $this->appConfig(90)))->prune(new DateTimeImmutable()));
	}

	public function testAPeriodBelowOneDayFallsBackToTheDefault(): void {
		$retention = new ImportRunRetention(
			$this->createMock(ImportPreviewMapperInterface::class),
			$this->createMock(ImportPreviewRowMapperInterface::class),
			$this->appConfig(0)
		);

		$this->assertSame(ImportRunRetention::DEFAULT_DAYS, $retention->retentionDays());
	}

	public function testTheAdministeredPeriodIsUsed(): void {
		$retention = new ImportRunRetention(
			$this->createMock(ImportPreviewMapperInterface::class),
			$this->createMock(ImportPreviewRowMapperInterface::class),
			$this->appConfig(14)
		);

		$this->assertSame(14, $retention->retentionDays());
	}
}
