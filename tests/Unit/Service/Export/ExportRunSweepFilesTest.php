<?php

/**
 * Characterisation of which files an export sweep deletes, and how it survives a missing one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One case per branch of the file deletion inside a sweep.
 */
class ExportRunSweepFilesTest extends TestCase {

	/**
	 * A due run.
	 */
	private function run(?int $fileId, ?string $actor): ExportRun {
		$run = new ExportRun();
		$run->setUuid('run-' . (string)$fileId . '-' . (string)$actor);
		$run->setFileId($fileId);
		$run->setActor($actor);
		$run->setStatus(ExportRun::STATUS_AVAILABLE);

		return $run;
	}

	/**
	 * Sweep these due runs over this root folder and logger.
	 *
	 * @param array<int, ExportRun> $runs The due runs.
	 */
	private function sweep(array $runs, IRootFolder $root, ?LoggerInterface $logger = null): int {
		$mapper = $this->createMock(ExportRunMapper::class);
		$mapper->method('findDueForSweep')->willReturn($runs);
		$mapper->method('update')->willReturnArgument(0);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('@1800000000'));
		$time->method('getTime')->willReturn(1800000000);

		$recorder = new ExportRunRecorder(
			mapper: $mapper,
			rootFolder: $root,
			time: $time,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);

		return $recorder->sweep();
	}

	public function testARunWithoutAFileOrAnOwnerTouchesNoFolder(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$runs = [$this->run(null, 'alice'), $this->run(7, null), $this->run(7, '')];

		$this->assertSame(3, $this->sweep($runs, $root));
		foreach ($runs as $run) {
			$this->assertSame(ExportRun::STATUS_EXPIRED, $run->getStatus());
		}
	}

	public function testEveryNodeOfTheFileIsDeletedInTheOwnersFolder(): void {
		$deleted = 0;
		$file    = $this->createMock(File::class);
		$file->method('delete')->willReturnCallback(
			static function () use (&$deleted): void {
				$deleted++;
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('getById')->with(7)->willReturn([$file, $file]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);

		$this->assertSame(1, $this->sweep([$this->run(7, 'alice')], $root));
		$this->assertSame(2, $deleted);
	}

	public function testAMissingFolderIsLoggedAtDebugAndTheRunIsStillMarked(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new RuntimeException('no such user'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[ExportRunRecorder] Nothing to delete for an expired export run',
			$this->callback(static fn (array $context): bool => $context['uuid'] === 'run-7-bob')
		);

		$run = $this->run(7, 'bob');

		$this->assertSame(1, $this->sweep([$run], $root, $logger));
		$this->assertSame(ExportRun::STATUS_EXPIRED, $run->getStatus());
		$this->assertNull($run->getFileId());
	}
}
