<?php

/**
 * Golden test for SchemaVersioningService's changelog and invalid-count reads.
 *
 * Pins, on the code before it was split into small units, the exact
 * changelog record and the warning for each breaking/acknowledged
 * combination, the null on a failed write, and which revalidation run the
 * gate's invalid count is read from (report first, side table second).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaPopulationRuns;
use DateTime;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCA\OpenRegister\Service\Schema\SchemaChangeRecord;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SchemaVersioningServiceGoldenTest extends TestCase {
	private SchemaVersioningService $service;
	private SchemaChangelogMapper&MockObject $changelogMapper;
	private SchemaRunMapper&MockObject $runMapper;
	private SchemaRunEntryMapper&MockObject $runEntryMapper;
	private LoggerInterface&MockObject $logger;

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->changelogMapper = $this->createMock(SchemaChangelogMapper::class);
		$this->runMapper = $this->createMock(SchemaRunMapper::class);
		$this->runEntryMapper = $this->createMock(SchemaRunEntryMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->service = new SchemaVersioningService(
			$this->changelogMapper,
			new SchemaPopulationRuns($this->runMapper, $this->runEntryMapper, $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), $this->createMock(\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::class)),
			$userSession,
			$this->logger
		);
	}

	private function breaking(): SchemaChangeSet {
		return new SchemaChangeSet([['property' => 'email', 'kind' => 'removed']], SchemaChangeSet::CLASS_BREAKING, 'major');
	}

	/**
	 * Capture the createFromArray payload.
	 *
	 * @param array<string, mixed>|null $data Filled with the payload.
	 *
	 * @return SchemaChangelog The stored entry.
	 */
	private function captureChangelog(?array &$data): SchemaChangelog {
		$stored = new SchemaChangelog();
		$this->changelogMapper->method('createFromArray')->willReturnCallback(
			function (array $payload) use (&$data, $stored): SchemaChangelog {
				$data = $payload;
				return $stored;
			}
		);
		return $stored;
	}

	public function testAnUnacknowledgedBreakingChangeIsRecordedAndWarnedAbout(): void {
		$data = null;
		$stored = $this->captureChangelog($data);

		$this->assertSame($stored, $this->service->recordChange(3, new SchemaChangeRecord($this->breaking(), false, '2.0.0', 'import')));

		$changes = [['property' => 'email', 'kind' => 'removed']];
		$this->assertSame(
			['schemaId' => 3, 'version' => '2.0.0', 'classification' => 'breaking', 'changes' => $changes, 'actor' => 'alice'],
			$data
		);
		$this->assertSame(
			[[
				'[SchemaVersioningService] An unacknowledged breaking schema change was recorded, not refused.',
				['schema_id' => 3, 'version' => '2.0.0', 'origin' => 'import', 'actor' => 'alice', 'changes' => $changes],
			]],
			$this->warnings
		);
	}

	public function testAnAcknowledgedBreakingChangeCarriesWhoAcknowledgedIt(): void {
		$data = null;
		$this->captureChangelog($data);

		$this->service->recordChange(3, new SchemaChangeRecord($this->breaking(), true, '2.0.0'));

		$this->assertSame(
			['schemaId', 'version', 'classification', 'changes', 'actor', 'acknowledgedBy', 'acknowledgedAt'],
			array_keys($data)
		);
		$this->assertSame('alice', $data['acknowledgedBy']);
		$this->assertInstanceOf(DateTime::class, $data['acknowledgedAt']);
		$this->assertSame([], $this->warnings);
	}

	public function testACompatibleChangeIsRecordedWithoutAcknowledgement(): void {
		$data = null;
		$this->captureChangelog($data);
		$changeSet = new SchemaChangeSet([['property' => 'x', 'kind' => 'added']], SchemaChangeSet::CLASS_COMPATIBLE, 'minor');

		$this->service->recordChange(3, new SchemaChangeRecord($changeSet, true, '1.1.0'));

		$this->assertSame(['schemaId', 'version', 'classification', 'changes', 'actor'], array_keys($data));
		$this->assertSame([], $this->warnings);
	}

	public function testAFailedWriteIsLoggedAndReturnsNull(): void {
		$this->changelogMapper->method('createFromArray')->willThrowException(new RuntimeException('db down'));

		$this->assertNull($this->service->recordChange(3, new SchemaChangeRecord($this->breaking(), true, '1.1.0')));
		$this->assertSame(
			[['[SchemaVersioningService] Failed to record changelog', ['schema_id' => 3, 'error' => 'db down']]],
			$this->warnings
		);
	}

	private function schemaRun(string $type, string $state, ?array $report, int $id = 1): SchemaRun {
		$run = new SchemaRun();
		$run->setId($id);
		$run->setType($type);
		$run->setState($state);
		$run->setReport($report);
		return $run;
	}

	private function gateCount(): ?int {
		try {
			$this->service->gateChange(3, new SchemaChangeRecord($this->breaking(), false));
		} catch (BreakingSchemaChangeException $e) {
			return $e->toResponse()['invalidCount'] ?? null;
		}

		$this->fail('The gate did not refuse.');
	}

	public function testTheGateReadsTheFirstCompletedRevalidationReport(): void {
		$this->runMapper->expects($this->once())->method('findBySchema')->with(3, new ListQuery(limit: 25))->willReturn(
			[
				$this->schemaRun(SchemaRun::TYPE_MIGRATION, SchemaRun::STATE_COMPLETED, ['invalid' => 9]),
				$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_RUNNING, ['invalid' => 8]),
				$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_COMPLETED, ['invalid' => '4']),
				$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_COMPLETED, ['invalid' => 2]),
			]
		);

		$this->assertSame(4, $this->gateCount());
	}

	public function testTheGateCountsSideTableEntriesWhenTheReportHasNoCount(): void {
		$this->runMapper->method('findBySchema')->willReturn(
			[
				$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_COMPLETED, ['valid' => 1], 12),
				$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_COMPLETED, ['invalid' => 2]),
			]
		);
		$this->runEntryMapper->expects($this->once())->method('findByRun')
			->with($this->identicalTo(12), $this->equalTo(new OutcomeFilter(outcome: SchemaRunEntry::OUTCOME_INVALID)))
			->willReturn([new SchemaRunEntry(), new SchemaRunEntry(), new SchemaRunEntry()]);

		$this->assertSame(3, $this->gateCount());
	}

	public function testTheGateHasNoCountWhenReadingFails(): void {
		$this->runMapper->method('findBySchema')->willThrowException(new RuntimeException('down'));
		$this->assertNull($this->gateCount());
	}

	public function testTheGateHasNoCountWhenTheSideTableFails(): void {
		$this->runMapper->method('findBySchema')->willReturn(
			[$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_COMPLETED, null, 12)]
		);
		$this->runEntryMapper->method('findByRun')->willThrowException(new RuntimeException('down'));
		$this->assertNull($this->gateCount());
	}

	public function testTheGateHasNoCountWithoutACompletedRevalidation(): void {
		$this->runMapper->method('findBySchema')->willReturn(
			[$this->schemaRun(SchemaRun::TYPE_REVALIDATION, SchemaRun::STATE_FAILED, ['invalid' => 1])]
		);
		$this->assertNull($this->gateCount());
	}
}
