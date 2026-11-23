<?php

/**
 * SearchIndexRebuildJob turns a refused or failed rebuild into a failed run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\Search\BackgroundJob\SearchIndexRebuildJob;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;

/**
 * Behaviour of the search index rebuild job body.
 */
class SearchIndexRebuildJobTest extends TestCase {

	/**
	 * The rebuild service.
	 *
	 * @var SearchIndexMaintenance&MockObject
	 */
	private SearchIndexMaintenance&MockObject $index;

	protected function setUp(): void {
		$this->index = $this->createMock(SearchIndexMaintenance::class);
	}

	private function runJob(mixed $argument): void {
		$job = new SearchIndexRebuildJob(
			new RecordedJobRuntime($this->createMock(ITimeFactory::class), $this->createMock(JobRunRecorderInterface::class)),
			$this->index
		);
		(new ReflectionMethod(SearchIndexRebuildJob::class, 'runRecorded'))->invoke($job, $argument);
	}

	public function testRebuildsOneRegisterWithApplyWhenTheArgumentNamesIt(): void {
		$this->index->expects($this->once())->method('rebuild')->with(12, new SearchIndexPass(apply: true, progress: null))
			->willReturn(['state' => 'completed', 'failed' => 0]);

		$this->runJob(['registerId' => '12', 'actor' => 'admin']);
	}

	public function testRebuildsEverythingWhenTheArgumentIsNotAnArray(): void {
		$this->index->expects($this->once())->method('rebuild')->with(null, new SearchIndexPass(apply: true, progress: null))
			->willReturn(['state' => 'completed']);

		$this->runJob(null);
	}

	public function testRebuildsEverythingWhenTheArrayHasNoRegister(): void {
		$this->index->expects($this->once())->method('rebuild')->with(null, new SearchIndexPass(apply: true, progress: null))
			->willReturn([]);

		$this->runJob(['actor' => 'admin']);
	}

	public function testRefusedRebuildThrowsItsReason(): void {
		$this->index->method('rebuild')->willReturn(['state' => 'refused', 'reason' => 'No concurrent reindex.']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No concurrent reindex.');

		$this->runJob([]);
	}

	public function testRefusedRebuildWithoutReasonThrowsTheDefault(): void {
		$this->index->method('rebuild')->willReturn(['state' => 'refused']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The rebuild was refused.');

		$this->runJob([]);
	}

	public function testFailedIndexesThrowWithTheirCount(): void {
		$this->index->method('rebuild')->willReturn(['state' => 'completed', 'failed' => 2]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The rebuild finished with 2 failed index(es).');

		$this->runJob([]);
	}
}
