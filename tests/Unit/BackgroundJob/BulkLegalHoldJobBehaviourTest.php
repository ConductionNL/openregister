<?php

/**
 * BulkLegalHoldJob refuses a run without a schema and contains collaborator failures.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the job's run().
 */
class BulkLegalHoldJobBehaviourTest extends TestCase {

	/**
	 * Invoke the protected run() with the given argument.
	 *
	 * @param BulkLegalHoldJob $job      The job.
	 * @param mixed            $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(BulkLegalHoldJob $job, mixed $argument): void {
		$method = new ReflectionMethod(BulkLegalHoldJob::class, 'run');
		$method->invoke($job, $argument);
	}

	public function testMissingSchemaIdLogsErrorAndResolvesNothingElse(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[BulkLegalHoldJob] No schemaId provided');
		$logger->expects($this->never())->method('info');

		$requested = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger, &$requested) {
				$requested[] = $id;
				return $logger;
			}
		);

		$job = new BulkLegalHoldJob($this->createMock(ITimeFactory::class), $container);
		$this->runJob($job, ['reason' => 'audit']);

		$this->assertSame([LoggerInterface::class], $requested);
	}

	public function testExplicitNullSchemaIdIsRefusedLikeAMissingOne(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[BulkLegalHoldJob] No schemaId provided');

		$requested = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger, &$requested) {
				$requested[] = $id;
				return $logger;
			}
		);

		$job = new BulkLegalHoldJob($this->createMock(ITimeFactory::class), $container);
		$this->runJob($job, ['schemaId' => null]);

		$this->assertSame([LoggerInterface::class], $requested);
	}

	public function testRetentionServiceResolutionFailureIsLoggedNotThrown(): void {
		$failure = new RuntimeException('retention service unavailable');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('[BulkLegalHoldJob] Placing legal holds on schema: 42');
		$logger->expects($this->once())->method('error')
			->with('[BulkLegalHoldJob] Error: retention service unavailable', ['exception' => $failure]);

		$requested = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger, $failure, &$requested) {
				$requested[] = $id;
				if ($id === RetentionServiceInterface::class) {
					throw $failure;
				}

				return $logger;
			}
		);

		$job = new BulkLegalHoldJob($this->createMock(ITimeFactory::class), $container);
		$this->runJob($job, ['schemaId' => 42, 'reason' => 'audit']);

		$this->assertSame([LoggerInterface::class, RetentionServiceInterface::class], $requested);
	}

	public function testMapperResolutionFailureIsLoggedNotThrown(): void {
		$failure = new RuntimeException('mapper unavailable');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('[BulkLegalHoldJob] Placing legal holds on schema: zaken');
		$logger->expects($this->once())->method('error')
			->with('[BulkLegalHoldJob] Error: mapper unavailable', ['exception' => $failure]);

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->expects($this->never())->method('placeLegalHold');

		$requested = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger, $retention, $failure, &$requested) {
				$requested[] = $id;
				if ($id === MagicMapperInterface::class) {
					throw $failure;
				}

				if ($id === RetentionServiceInterface::class) {
					return $retention;
				}

				return $logger;
			}
		);

		$job = new BulkLegalHoldJob($this->createMock(ITimeFactory::class), $container);
		$this->runJob($job, ['schemaId' => 'zaken']);

		$this->assertSame(
			[LoggerInterface::class, RetentionServiceInterface::class, MagicMapperInterface::class],
			$requested
		);
	}

	public function testPlacesAHoldOnEveryObjectOfTheSchema(): void {
		$first  = new ObjectEntity();
		$second = new ObjectEntity();

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->once())->method('findBySchema')->with(42)->willReturn([$first, $second]);
		$mapper->expects($this->exactly(2))->method('update');

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->expects($this->exactly(2))->method('placeLegalHold')
			->willReturnCallback(fn (ObjectEntity $object, string $reason): ObjectEntity => $object);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				MagicMapperInterface::class => $mapper,
				RetentionServiceInterface::class => $retention,
				default => $logger,
			}
		);

		$job = new BulkLegalHoldJob($this->createMock(ITimeFactory::class), $container);
		$this->runJob($job, ['schemaId' => '42', 'reason' => 'audit']);
	}
}
