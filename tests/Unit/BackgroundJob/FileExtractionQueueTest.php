<?php

/**
 * Tests for FileExtractionQueue: the job and argument one queued file extraction is.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\FileExtractionQueue;
use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Kernel\Contracts\FileExtractionQueueInterface;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileExtractionQueue::class)]
final class FileExtractionQueueTest extends TestCase {

	public function testItQueuesTheFileTextExtractionJobWithTheArgumentTheJobReads(): void {
		$queued = [];
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function ($job, $argument = null) use (&$queued): void {
			$queued[] = [$job, $argument];
		});

		$queue = new FileExtractionQueue($jobs);
		$queue->queueFileExtraction(41);

		$this->assertInstanceOf(FileExtractionQueueInterface::class, $queue);
		$this->assertSame([[FileTextExtractionJob::class, ['file_id' => 41]]], $queued);
	}
}
