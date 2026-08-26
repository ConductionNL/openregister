<?php

/**
 * Unit tests for DbalIntrospectionJob.
 *
 * Covers:
 *  - run() re-introspects every `type: database` source (real work, no stub)
 *  - a failure for one source is caught and logged so the remaining sources
 *    are still processed (per-source failure isolation)
 *  - drift reported by the introspection service is logged
 *  - a mapper failure degrades to a no-op instead of a fatal
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DbalIntrospectionJob;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for DbalIntrospectionJob.
 */
class DbalIntrospectionJobTest extends TestCase {
	/**
	 * Build a database Source with the given uuid.
	 *
	 * @param string $uuid The source uuid.
	 *
	 * @return Source The source.
	 */
	private function source(string $uuid): Source {
		$source = new Source();
		$source->setUuid($uuid);
		$source->setType('database');
		return $source;
	}//end source()

	/**
	 * Invoke the protected run() method.
	 *
	 * @param DbalIntrospectionJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(DbalIntrospectionJob $job): void {
		$method = (new \ReflectionClass($job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * run() introspects every database source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testRunIntrospectsEveryDatabaseSource(): void {
		$sources = [$this->source('s-1'), $this->source('s-2')];

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findAll')->willReturn($sources);

		$introspection = $this->createMock(DatabaseIntrospectionService::class);
		$introspection->expects($this->exactly(2))
			->method('introspect')
			->willReturn(['register' => 1, 'created' => [], 'updated' => ['t'], 'drift' => []]);

		$job = new DbalIntrospectionJob(
			time: $this->createMock(ITimeFactory::class),
			sourceMapper: $sourceMapper,
			introspectionService: $introspection,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->runJob(job: $job);
	}//end testRunIntrospectsEveryDatabaseSource()

	/**
	 * One unreachable source is logged and does NOT block the remaining sources.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testOneFailingSourceDoesNotBlockTheRest(): void {
		$failing = $this->source('s-down');
		$healthy = $this->source('s-up');

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findAll')->willReturn([$failing, $healthy]);

		$processed = [];
		$introspection = $this->createMock(DatabaseIntrospectionService::class);
		$introspection->method('introspect')->willReturnCallback(
			function (Source $source) use (&$processed) {
				$processed[] = $source->getUuid();
				if ($source->getUuid() === 's-down') {
					throw new DbalConnectionException('unreachable');
				}

				return ['register' => 1, 'created' => [], 'updated' => [], 'drift' => []];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())
			->method('warning')
			->with($this->stringContains('s-down'));

		$job = new DbalIntrospectionJob(
			time: $this->createMock(ITimeFactory::class),
			sourceMapper: $sourceMapper,
			introspectionService: $introspection,
			logger: $logger
		);

		$this->runJob(job: $job);

		$this->assertSame(['s-down', 's-up'], $processed);
	}//end testOneFailingSourceDoesNotBlockTheRest()

	/**
	 * Drift reported by the introspection service is logged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testDriftIsLogged(): void {
		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findAll')->willReturn([$this->source('s-drift')]);

		$introspection = $this->createMock(DatabaseIntrospectionService::class);
		$introspection->method('introspect')->willReturn(
			[
				'register' => 1,
				'created' => [],
				'updated' => ['permits'],
				'drift' => [['table' => 'permits', 'changes' => [['type' => 'added', 'property' => 'note']]]],
			]
		);

		$driftLogged = false;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message) use (&$driftLogged) {
				if (str_contains($message, 'drift') === true && str_contains($message, 's-drift') === true) {
					$driftLogged = true;
				}
			}
		);

		$job = new DbalIntrospectionJob(
			time: $this->createMock(ITimeFactory::class),
			sourceMapper: $sourceMapper,
			introspectionService: $introspection,
			logger: $logger
		);

		$this->runJob(job: $job);

		$this->assertTrue($driftLogged);
	}//end testDriftIsLogged()

	/**
	 * A mapper failure yields a logged no-op run, not a fatal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testMapperFailureDegradesToNoop(): void {
		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findAll')->willThrowException(new \RuntimeException('db down'));

		$introspection = $this->createMock(DatabaseIntrospectionService::class);
		$introspection->expects($this->never())->method('introspect');

		$job = new DbalIntrospectionJob(
			time: $this->createMock(ITimeFactory::class),
			sourceMapper: $sourceMapper,
			introspectionService: $introspection,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->runJob(job: $job);
		$this->addToAssertionCount(1);
	}//end testMapperFailureDegradesToNoop()
}//end class
