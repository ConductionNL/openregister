<?php

/**
 * How a sweep marks due export runs: the batch size it asks for, each run
 * expired with its file cleared and stamped now, and a run that cannot be
 * saved logged and left out of the count.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ExportRunRecorder::sweep() marking.
 *
 * @covers \OCA\OpenRegister\Service\Export\ExportRunRecorder
 * @uses \OCA\OpenRegister\Db\ExportRun
 */
class ExportRunSweepMarkingTest extends TestCase {

	private ExportRunMapper&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private function recorder(): ExportRunRecorder {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('@1800000000'));

		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		return new ExportRunRecorder($this->mapper, $root, $time, $this->logger);
	}//end recorder()

	private function dueRun(string $uuid): ExportRun {
		$run = new ExportRun();
		$run->setUuid($uuid);
		$run->setStatus(ExportRun::STATUS_AVAILABLE);
		$run->setFilePath('/exports/'.$uuid.'.csv');

		return $run;
	}//end dueRun()

	protected function setUp(): void {
		$this->mapper = $this->createMock(ExportRunMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testTheSweepAsksForTheGivenBatchAtTheCurrentTime(): void {
		$this->mapper->expects($this->once())->method('findDueForSweep')->with(
			$this->callback(static fn (DateTime $now): bool => $now->getTimestamp() === 1800000000),
			25
		)->willReturn([]);

		$this->assertSame(0, $this->recorder()->sweep(25));
	}//end testTheSweepAsksForTheGivenBatchAtTheCurrentTime()

	public function testEachDueRunIsExpiredWithItsFileCleared(): void {
		$run = $this->dueRun('a');
		$this->mapper->method('findDueForSweep')->willReturn([$run]);
		$this->mapper->expects($this->once())->method('update')->with($run)->willReturnArgument(0);

		$this->assertSame(1, $this->recorder()->sweep());
		$this->assertSame(ExportRun::STATUS_EXPIRED, $run->getStatus());
		$this->assertNull($run->getFilePath());
		$this->assertNull($run->getFileId());
		$this->assertSame(1800000000, $run->getUpdated()->getTimestamp());
	}//end testEachDueRunIsExpiredWithItsFileCleared()

	public function testARunThatCannotBeSavedIsLoggedAndNotCounted(): void {
		$runs = [$this->dueRun('a'), $this->dueRun('b'), $this->dueRun('c')];
		$this->mapper->method('findDueForSweep')->willReturn($runs);
		$this->mapper->method('update')->willReturnCallback(
			static function (ExportRun $run): ExportRun {
				if ($run->getUuid() === 'b') {
					throw new RuntimeException('deadlock');
				}

				return $run;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[ExportRunRecorder] Could not mark an expired export run',
			$this->callback(static fn (array $context): bool => $context['uuid'] === 'b' && $context['error'] === 'deadlock')
		);

		$this->assertSame(2, $this->recorder()->sweep());
	}//end testARunThatCannotBeSavedIsLoggedAndNotCounted()
}//end class
