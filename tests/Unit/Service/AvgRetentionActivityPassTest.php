<?php

declare(strict_types=1);

/**
 * AvgRetentionService per-activity pass characterisation tests.
 *
 * Pins what the AVG retention pass reports for each published processing
 * activity: an activity without a retention period and one with an
 * unparseable period are skipped (the latter with a warning), a dry run
 * counts the overdue objects without erasing them, an activity without
 * overdue objects erases nothing, and a live run erases what is overdue.
 *
 * The cut-off depends on the clock, so it is checked for shape and then
 * left out of the compared report.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup;
use OCA\OpenRegister\Service\AvgRetentionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterisation tests for the per-activity step of the AVG retention pass.
 */
class AvgRetentionActivityPassTest extends TestCase {

	/**
	 * Object mapper the pass writes its soft deletes through.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * Warnings the pass logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A live run skips the activities without a usable period and erases the overdue object of the third.
	 *
	 * @return void
	 */
	public function testALiveRunSkipsUnusablePeriodsAndErasesWhatIsOverdue(): void {
		$service = $this->service(overdueRows: [$this->overdueRow()]);
		$this->objectMapper->expects($this->once())->method('update');

		$summary = $this->withoutCutoffs(summary: $service->runRetentionPass());

		self::assertSame(
			[
				'evaluatedActivities' => 1,
				'skippedActivities' => 2,
				'objectsErased' => 1,
				'objectsWithheld' => 0,
				'withheld' => [],
				'dryRun' => false,
				'perActivity' => [$this->report(matched: 1, erased: 1)],
			],
			$summary
		);
		self::assertSame(
			[
				[
					'[AVG retention] Unparseable retentionPeriod — skipping activity',
					['activity' => 'activity-bad', 'retentionPeriod' => 'not-a-duration'],
				],
			],
			$this->warnings
		);
	}//end testALiveRunSkipsUnusablePeriodsAndErasesWhatIsOverdue()

	/**
	 * A dry run counts the overdue object but erases nothing.
	 *
	 * @return void
	 */
	public function testADryRunCountsButDoesNotErase(): void {
		$service = $this->service(overdueRows: [$this->overdueRow()]);
		$this->objectMapper->expects($this->never())->method('update');

		$summary = $this->withoutCutoffs(summary: $service->runRetentionPass(true));

		self::assertSame(0, $summary['objectsErased']);
		self::assertTrue($summary['dryRun']);
		self::assertSame([$this->report(matched: 1, erased: 0)], $summary['perActivity']);
	}//end testADryRunCountsButDoesNotErase()

	/**
	 * An activity without overdue objects is evaluated and erases nothing.
	 *
	 * @return void
	 */
	public function testAnActivityWithoutOverdueObjectsErasesNothing(): void {
		$service = $this->service(overdueRows: []);
		$this->objectMapper->expects($this->never())->method('update');
		$this->objectMapper->expects($this->never())->method('find');

		$summary = $this->withoutCutoffs(summary: $service->runRetentionPass());

		self::assertSame(1, $summary['evaluatedActivities']);
		self::assertSame(2, $summary['skippedActivities']);
		self::assertSame([$this->report(matched: 0, erased: 0)], $summary['perActivity']);
	}//end testAnActivityWithoutOverdueObjectsErasesNothing()

	/**
	 * The report the evaluated activity produces, without its cut-off.
	 *
	 * @param int $matched Overdue objects matched.
	 * @param int $erased  Objects erased.
	 *
	 * @return array<string, mixed>
	 */
	private function report(int $matched, int $erased): array {
		return [
			'uuid' => 'activity-1',
			'name' => 'Klachtafhandeling',
			'retentionPeriod' => 'P1Y',
			'matchedObjects' => $matched,
			'erased' => $erased,
			'withheld' => [],
		];
	}//end report()

	/**
	 * One overdue audit-trail row.
	 *
	 * @return array<string, mixed>
	 */
	private function overdueRow(): array {
		return [
			'object' => 7,
			'object_uuid' => 'candidate-1',
			'register' => 1,
			'schema' => 42,
		];
	}//end overdueRow()

	/**
	 * Check every per-activity cut-off is an ISO-8601 time before now, then drop it.
	 *
	 * @param array<string, mixed> $summary The pass summary.
	 *
	 * @return array<string, mixed> The summary without cut-offs.
	 */
	private function withoutCutoffs(array $summary): array {
		foreach ($summary['perActivity'] as $index => $report) {
			$keys = array_keys($report);
			self::assertSame('cutoff', $keys[3]);
			$cutoff = \DateTime::createFromFormat(\DateTimeInterface::ATOM, $report['cutoff']);
			self::assertNotFalse($cutoff);
			self::assertLessThan(time(), $cutoff->getTimestamp());
			unset($summary['perActivity'][$index]['cutoff']);
		}

		return $summary;
	}//end withoutCutoffs()

	/**
	 * Build a service over three published activities: no period, an unparseable period and P1Y.
	 *
	 * @param array<int, array<string, mixed>> $overdueRows Rows the overdue query answers with.
	 *
	 * @return AvgRetentionService
	 */
	private function service(array $overdueRows): AvgRetentionService {
		$this->warnings = [];

		$db = $this->createMock(IDBConnection::class);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$functionBuilder = $this->createMock(IFunctionBuilder::class);
		$result = $this->createMock(IResult::class);
		$expressionBuilder = $this->createMock(IExpressionBuilder::class);
		$expressionBuilder->method('eq')->willReturn('object = :p');
		$functionBuilder->method('max')->willReturn($this->createMock(IQueryFunction::class));
		foreach (['select', 'selectAlias', 'from', 'where', 'groupBy', 'having'] as $method) {
			$queryBuilder->method($method)->willReturnSelf();
		}

		$queryBuilder->method('func')->willReturn($functionBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('createNamedParameter')->willReturn(':p');
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAll')->willReturn($overdueRows);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);

		$activityMapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		$activityMapper->method('findAll')->willReturn(
			[
				$this->activity(uuid: 'activity-none', period: null),
				$this->activity(uuid: 'activity-bad', period: 'not-a-duration'),
				$this->activity(uuid: 'activity-1', period: 'P1Y'),
			]
		);

		$candidate = new ObjectEntity();
		$candidate->setUuid('candidate-1');
		$candidate->setRegister('1');
		$candidate->setSchema('klacht');
		$candidate->setRetention([]);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('find')->willReturn($candidate);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (): Schema {
				$schema = new Schema();
				$schema->setSlug('klacht');
				$schema->setConfiguration([]);

				return $schema;
			}
		);
		$guard = new ArchivalRetentionGuard(new ArchivalSchemaLookup($schemaMapper, $this->createMock(LoggerInterface::class)));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new AvgRetentionService($db, $activityMapper, $this->objectMapper, $logger, $guard);
	}//end service()

	/**
	 * One published processing activity.
	 *
	 * @param string      $uuid   The activity uuid.
	 * @param string|null $period The retention period, or null for none.
	 *
	 * @return Verwerkingsactiviteit
	 */
	private function activity(string $uuid, ?string $period): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid($uuid);
		$activity->setName('Klachtafhandeling');
		if ($period !== null) {
			$activity->setRetentionPeriod($period);
		}

		return $activity;
	}//end activity()
}//end class
