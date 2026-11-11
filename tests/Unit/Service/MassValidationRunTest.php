<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\MassValidationRun;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\MassValidationRun
 */
class MassValidationRunTest extends TestCase {

	/**
	 * Split objects into batch jobs through the private createBatchJobs().
	 *
	 * @param int $totalObjects Number of objects.
	 * @param int $batchSize    Objects per batch.
	 *
	 * @return array The batch jobs.
	 */
	private function createBatchJobs(int $totalObjects, int $batchSize): array {
		$run = new MassValidationRun(
			$this->createMock(MagicMapper::class),
			$this->createMock(AppSettingsInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class)
		);
		return (new ReflectionMethod(MassValidationRun::class, 'createBatchJobs'))->invoke($run, $totalObjects, $batchSize);
	}

	public function testCreateBatchJobs(): void {
		$this->assertSame(
			[
				['batchNumber' => 1, 'offset' => 0, 'limit' => 100],
				['batchNumber' => 2, 'offset' => 100, 'limit' => 100],
				['batchNumber' => 3, 'offset' => 200, 'limit' => 50],
			],
			$this->createBatchJobs(250, 100)
		);
	}

	public function testCreateBatchJobsZero(): void {
		$this->assertSame([], $this->createBatchJobs(0, 100));
	}

	public function testCreateBatchJobsExact(): void {
		$this->assertSame([['batchNumber' => 1, 'offset' => 0, 'limit' => 100]], $this->createBatchJobs(100, 100));
	}
}
