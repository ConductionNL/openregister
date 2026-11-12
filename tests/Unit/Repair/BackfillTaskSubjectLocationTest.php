<?php

/**
 * Unit tests for BackfillTaskSubjectLocation: guards, batched location and per-subject updates.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Repair\BackfillTaskSubjectLocation;
use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\BackfillTaskSubjectLocation
 */
final class BackfillTaskSubjectLocationTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var TaskSubjectLocatorInterface&MockObject */
	private TaskSubjectLocatorInterface $locator;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$this->locator = $this->createMock(TaskSubjectLocatorInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function step(?\Throwable $containerFailure = null): BackfillTaskSubjectLocation {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($containerFailure) {
				if ($containerFailure !== null) {
					throw $containerFailure;
				}
				return $id === IDBConnection::class ? $this->db : $this->locator;
			}
		);
		return new BackfillTaskSubjectLocation($container, $this->logger);
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	public function testNameDescribesTheBackfill(): void {
		$this->assertSame("Record the register and schema of each task's subject", $this->step()->getName());
	}

	public function testUnresolvableServicesSkipTheStep(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Task subject location backfill skipped: no container');

		$this->step(new RuntimeException('no container'))->run($output);
	}

	public function testMissingTaskTableIsReportedAndNothingIsQueried(): void {
		$this->db->method('tableExists')->with('openregister_tasks')->willReturn(false);
		$this->locator->expects($this->never())->method('locateMany');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Task subject location: no task table yet.');

		$this->step()->run($output);
		$this->assertSame([], $this->queryLog);
	}

	public function testLocatedSubjectsAreWrittenOnlyToUnlocatedRows(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->queuedRows = [['u-1', 'u-2'], [['n' => '1']]];
		$this->locator->expects($this->once())->method('locateMany')->with(['u-1', 'u-2'])
			->willReturn(['u-1' => ['registerId' => 4, 'schemaId' => 8]]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Task subject location: 1 of 2 tasks located; the rest keep the cross-table lookup.');

		$this->step()->run($output);

		$log = $this->log();
		$this->assertStringContainsString('selectDistinct(object_uuid)', $log);
		$this->assertStringContainsString('isNotNull(object_uuid)', $log);
		$this->assertStringContainsString('orX(isNull(register_id), isNull(schema_id))', $log);
		$this->assertStringContainsString('update(openregister_tasks', $log);
		$this->assertMatchesRegularExpression('/param (:p\d+) = 4, 1\n.*set\(register_id, \1/s', $log);
		$this->assertMatchesRegularExpression('/param (:p\d+) = 8, 1\n.*set\(schema_id, \1/s', $log);
		$this->assertMatchesRegularExpression('/param (:p\d+) = u-1, 2\n.*eq\(object_uuid, \1/s', $log);
		$this->assertStringContainsString('select(count(*, ))', $log);
	}

	public function testNoUnlocatedSubjectsNeverCallsTheLocator(): void {
		$this->db->method('tableExists')->willReturn(true);
		$this->queuedRows = [[], [['n' => '0']]];
		$this->locator->expects($this->never())->method('locateMany');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Task subject location: 0 of 0 tasks located; the rest keep the cross-table lookup.');

		$this->step()->run($output);
	}

	public function testSubjectsAreLocatedInBatchesOfFiveHundred(): void {
		$this->db->method('tableExists')->willReturn(true);
		$uuids = array_map(static fn (int $i): string => 'u-' . $i, range(1, 501));
		$this->queuedRows = [$uuids, [['n' => '501']]];
		$sizes = [];
		$this->locator->method('locateMany')->willReturnCallback(
			function (array $uuids) use (&$sizes): array {
				$sizes[] = count($uuids);
				return [];
			}
		);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([500, 1], $sizes);
	}

	public function testAFailingBatchIsLoggedAndTheNextBatchStillRuns(): void {
		$this->db->method('tableExists')->willReturn(true);
		$uuids = array_map(static fn (int $i): string => 'u-' . $i, range(1, 501));
		$this->queuedRows = [$uuids, [['n' => '500']]];
		$calls = 0;
		$this->locator->method('locateMany')->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('index offline');
				}
				return ['u-501' => ['registerId' => 1, 'schemaId' => 2]];
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[BackfillTaskSubjectLocation] A batch could not be located: index offline', $this->arrayHasKey('exception'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Task subject location: 1 of 501 tasks located; the rest keep the cross-table lookup.');

		$this->step()->run($output);
	}
}
