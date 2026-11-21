<?php

/**
 * The five-minute request alert job (production-observability, "Error rate threshold").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\BackgroundJob\RequestAlertJob;
use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\RequestAlertJob
 */
final class RequestAlertJobTest extends TestCase {

	public function testRunsEveryFiveMinutesAndJudgesTheWindow(): void {
		$evaluator = $this->createMock(RequestAlertEvaluator::class);
		$evaluator->expects($this->once())->method('evaluate')->willReturn(['errorRateExceeded' => false, 'responseTimeExceeded' => false]);

		$job = new RequestAlertJob(time: $this->createMock(ITimeFactory::class), evaluator: $evaluator, logger: $this->createMock(LoggerInterface::class));
		$this->runJob(job: $job);

		$this->assertSame(300, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}//end testRunsEveryFiveMinutesAndJudgesTheWindow()

	public function testAFailedRunIsLoggedNotThrown(): void {
		$evaluator = $this->createMock(RequestAlertEvaluator::class);
		$evaluator->method('evaluate')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('db down'));

		$this->runJob(job: new RequestAlertJob(time: $this->createMock(ITimeFactory::class), evaluator: $evaluator, logger: $logger));
	}//end testAFailedRunIsLoggedNotThrown()

	private function runJob(RequestAlertJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()
}//end class
