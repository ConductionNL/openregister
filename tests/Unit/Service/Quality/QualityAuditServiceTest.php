<?php

/**
 * Unit tests for QualityAuditService — save, run, schedule, trend and export of quality audits.
 *
 * Runs against the real scorer and the real evaluator, so the rule a run
 * judges is the rule a save scores (D-3); only the stores and the object
 * query are mocked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\QualityAudit;
use OCA\OpenRegister\Db\QualityAuditFailure;
use OCA\OpenRegister\Db\QualityAuditMapperInterface;
use OCA\OpenRegister\Db\QualityAuditRun;
use OCA\OpenRegister\Db\QualityAuditRunMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Quality\QualityAuditEvaluator;
use OCA\OpenRegister\Service\Quality\QualityAuditRefusedException;
use OCA\OpenRegister\Service\Quality\QualityAuditService;
use OCA\OpenRegister\Service\Quality\QualityAuditStores;
use OCA\OpenRegister\Service\Quality\QualityScopeMappers;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class QualityAuditServiceTest extends TestCase {

	private const DECLARATION = [
		'name' => 'Address register',
		'population' => ['register' => 'bag', 'schema' => 'address', 'filters' => ['status' => 'active']],
		'rules' => [
			['type' => 'required', 'field' => 'postcode'],
			['type' => 'required', 'field' => 'houseNumber', 'name' => 'house number present'],
		],
		'tolerance' => 0.95,
		'schedule' => 'monthly',
	];

	private QualityAuditMapperInterface $audits;

	private QualityAuditRunMapperInterface $runs;

	private ObjectOperationsInterface $objects;

	private RegisterMapperInterface $registers;

	private SchemaMapperInterface $schemas;

	private LoggerInterface $logger;

	/** @var array<int, array<int, array{record: string, rules: array<int, string>}>> */
	private array $failureBatches = [];

	/** @var array<int, QualityAuditRun> */
	private array $persistedRuns = [];

	protected function setUp(): void {
		parent::setUp();

		$this->audits = $this->createMock(QualityAuditMapperInterface::class);
		$this->audits->method('persist')->willReturnCallback(
			static function (QualityAudit $audit): QualityAudit {
				if ($audit->getId() === null) {
					$audit->setId(3);
					$audit->setUuid('audit-3');
				}

				return $audit;
			}
		);

		$this->runs = $this->createMock(QualityAuditRunMapperInterface::class);
		$this->runs->method('persist')->willReturnCallback(
			function (QualityAuditRun $run): QualityAuditRun {
				if ($run->getId() === null) {
					$run->setId(11);
					$run->setUuid('run-11');
				}

				$this->persistedRuns[] = clone $run;

				return $run;
			}
		);
		$this->runs->method('addFailures')->willReturnCallback(
			function (int $runId, array $failures): void {
				$this->assertSame(11, $runId);
				$this->failureBatches[] = $failures;
			}
		);

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->registers->method('find')->willReturn(new Register());
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willReturn(new Schema());
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): QualityAuditService {
		return new QualityAuditService(
			new QualityAuditStores($this->audits, $this->runs),
			new QualityAuditEvaluator(new QualityScorer()),
			$this->objects,
			new QualityScopeMappers($this->schemas, $this->registers),
			$this->logger
		);
	}

	private function audit(float $tolerance = 0.95): QualityAudit {
		$audit = new QualityAudit();
		$audit->setId(3);
		$audit->setUuid('audit-3');
		$audit->setName('Address register');
		$audit->setRegisterRef('bag');
		$audit->setSchemaRef('address');
		$audit->setFilters(['status' => 'active']);
		$audit->setRules(self::DECLARATION['rules']);
		$audit->setTolerance($tolerance);
		$audit->setSchedule('monthly');

		return $audit;
	}

	/**
	 * Twenty addresses; the given numbers miss their postcode.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function addresses(int $count, array $noPostcode): array {
		$rows = [];
		for ($i = 1; $i <= $count; $i++) {
			$rows[] = [
				'id' => 'addr-'.$i,
				'postcode' => in_array($i, $noPostcode, true) ? '' : '1234AB',
				'houseNumber' => $i,
			];
		}

		return $rows;
	}

	public function testADeclarationIsSavedWithItsPopulationRulesToleranceAndSchedule(): void {
		$saved = $this->service()->save(self::DECLARATION, null, 'steward');

		$this->assertSame('audit-3', $saved->getUuid());
		$this->assertSame('bag', $saved->getRegisterRef());
		$this->assertSame('address', $saved->getSchemaRef());
		$this->assertSame(['status' => 'active'], $saved->getFilters());
		$this->assertSame(self::DECLARATION['rules'], $saved->getRules());
		$this->assertSame(0.95, $saved->getTolerance());
		$this->assertSame('monthly', $saved->getSchedule());
		$this->assertSame('steward', $saved->getCreatedBy());
		$this->assertSame(self::DECLARATION['population'], $saved->jsonSerialize()['population']);
	}

	public function testAnEmptyRuleSetIsRefusedNamingTheMissingRules(): void {
		$this->audits->expects($this->never())->method('persist');

		try {
			$this->service()->save(array_merge(self::DECLARATION, ['rules' => []]), null, 'steward');
			$this->fail('An audit without rules was saved');
		} catch (QualityAuditRefusedException $exception) {
			$this->assertSame(['rules: an audit needs at least one rule.'], $exception->faults());
			$this->assertStringContainsString('rules: an audit needs at least one rule.', $exception->getMessage());
		}
	}

	public function testAPopulationNamingNoSchemaThatExistsIsRefused(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('no'));
		$this->audits->expects($this->never())->method('persist');

		$this->expectException(QualityAuditRefusedException::class);
		$this->expectExceptionMessage('population.schema: no schema "address".');
		$this->service()->save(self::DECLARATION, null, 'steward');
	}

	public function testAnUnknownScheduleIsRefused(): void {
		$this->audits->expects($this->never())->method('persist');

		$this->expectException(QualityAuditRefusedException::class);
		$this->expectExceptionMessage('schedule: one of daily, weekly, monthly, or none for on demand.');
		$this->service()->save(array_merge(self::DECLARATION, ['schedule' => 'hourly']), null, 'steward');
	}

	public function testNoScheduleMeansOnDemand(): void {
		$saved = $this->service()->save(array_merge(self::DECLARATION, ['schedule' => '']), null, 'steward');

		$this->assertNull($saved->getSchedule());
		$this->assertFalse($saved->isDue(new DateTime()));
	}

	public function testChangingAnAuditKeepsWhoDeclaredIt(): void {
		$existing = $this->audit();
		$existing->setCreatedBy('steward');

		$saved = $this->service()->save(array_merge(self::DECLARATION, ['tolerance' => 0.98]), $existing, 'other');

		$this->assertSame($existing, $saved);
		$this->assertSame(0.98, $saved->getTolerance());
		$this->assertSame('steward', $saved->getCreatedBy());
	}

	public function testARunScoresThePopulationAndKeepsTheVerdictAndTheFailures(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(
				['filters' => ['status' => 'active', 'register' => 'bag', 'schema' => 'address'], 'limit' => 200, 'offset' => 0],
				false,
				false
			)
			->willReturn(self::addresses(20, [4, 9]));

		$now = new DateTimeImmutable('2026-11-01T09:00:00+00:00');
		$audit = $this->audit();
		$run = $this->service()->run($audit, 'steward', $now);

		// 18 records score 1, two score 0.5 on two rules: (18 + 1) / 20.
		$this->assertSame(20, $run->getEvaluated());
		$this->assertSame(0.95, $run->getScore());
		$this->assertSame(0.95, $run->getTolerance());
		$this->assertSame(QualityAuditEvaluator::VERDICT_PASS, $run->getVerdict());
		$this->assertSame(2, $run->getFailed());
		$this->assertSame('steward', $run->getStartedBy());
		$this->assertSame('2026-11-01T09:00:00+00:00', $run->getStartedAt()?->format(DATE_ATOM));
		$this->assertSame(
			[[
				['record' => 'addr-4', 'rules' => ['required:postcode']],
				['record' => 'addr-9', 'rules' => ['required:postcode']],
			]],
			$this->failureBatches
		);
		// Written once to get an id, once with the outcome.
		$this->assertCount(2, $this->persistedRuns);
		$this->assertNull($this->persistedRuns[0]->getVerdict());
		$this->assertSame('2026-11-01T09:00:00+00:00', $audit->getLastRunAt()?->format(DATE_ATOM));
	}

	public function testThePopulationIsReadAPageAtATimeAndFailuresWrittenInBatches(): void {
		$offsets = [];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$offsets): array {
				$offsets[] = $config['offset'];
				$all = self::addresses(450, range(1, 150));

				return array_slice($all, $config['offset'], $config['limit']);
			}
		);

		$run = $this->service()->run($this->audit(), 'steward', new DateTimeImmutable());

		$this->assertSame([0, 200, 400], $offsets);
		$this->assertSame(450, $run->getEvaluated());
		$this->assertSame(150, $run->getFailed());
		$this->assertSame([100, 50], array_map('count', $this->failureBatches));
		$this->assertSame(QualityAuditEvaluator::VERDICT_FAIL, $run->getVerdict());
	}

	public function testARunKeepsTheToleranceItWasJudgedAgainst(): void {
		$this->objects->method('findAll')->willReturn(self::addresses(10, [1]));
		$audit = $this->audit(0.90);

		$past = $this->service()->run($audit, 'steward', new DateTimeImmutable());
		$audit->setTolerance(0.98);

		$this->assertSame(0.90, $past->getTolerance());
		$this->assertSame(QualityAuditEvaluator::VERDICT_PASS, $past->getVerdict());
		$this->assertSame(0.95, $past->getScore());
	}

	public function testOnlyDueScheduledAuditsRunAndAFailingOneDoesNotStopTheRest(): void {
		$now = new DateTimeImmutable('2026-11-14T03:00:00+00:00');

		$due = $this->audit();
		$due->setLastRunAt(new DateTime('2026-10-01T03:00:00+00:00'));
		$recent = $this->audit();
		$recent->setId(4);
		$recent->setLastRunAt(new DateTime('2026-11-10T03:00:00+00:00'));
		$broken = $this->audit();
		$broken->setId(5);
		$broken->setRegisterRef('broken');

		$this->audits->method('findScheduled')->willReturn([$broken, $recent, $due]);
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if ($config['filters']['register'] === 'broken') {
					throw new \RuntimeException('register gone');
				}

				return self::addresses(2, []);
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('register gone'));

		$this->assertSame(1, $this->service()->runDue($now));
		// The broken audit's run is kept, and says it did not finish.
		$this->assertSame(QualityAuditService::VERDICT_ERROR, $this->persistedRuns[1]->getVerdict());
		$this->assertNull($broken->getLastRunAt());
		$this->assertSame('schedule', end($this->persistedRuns)->getStartedBy());
		$this->assertSame('2026-11-14T03:00:00+00:00', $due->getLastRunAt()?->format(DATE_ATOM));
		$this->assertSame('2026-11-10T03:00:00+00:00', $recent->getLastRunAt()?->format(DATE_ATOM));
	}

	public function testTheTrendIsTheAuditsRunsInSequence(): void {
		$query = new ListQuery(limit: 100, offset: 0);
		$runs = [new QualityAuditRun(), new QualityAuditRun(), new QualityAuditRun()];
		$this->runs->expects($this->once())->method('findByAudit')->with(3, $query)->willReturn($runs);

		$this->assertSame($runs, $this->service()->trend($this->audit(), $query));
	}

	public function testTheFailuresExportNamesEachRecordAndItsRules(): void {
		$failure = new QualityAuditFailure();
		$failure->setRecord('addr-4');
		$failure->setRules(['required:postcode', 'house number present']);
		$this->runs->expects($this->once())->method('findFailures')
			->with(11, $this->callback(static fn (ListQuery $q): bool => $q->offset === 0 && $q->limit === 200))
			->willReturn([$failure]);

		$run = new QualityAuditRun();
		$run->setId(11);

		$this->assertSame(
			"record,rules\naddr-4,\"required:postcode; house number present\"\n",
			$this->service()->failuresCsv($run)
		);
	}

	public function testDeletingAnAuditRemovesItsRunsFirst(): void {
		$order = [];
		$this->runs->expects($this->once())->method('deleteByAudit')->with(3)->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'runs';
			}
		);
		$this->audits->expects($this->once())->method('remove')->willReturnCallback(
			static function (QualityAudit $audit) use (&$order): QualityAudit {
				$order[] = 'audit';

				return $audit;
			}
		);

		$this->service()->delete($this->audit());

		$this->assertSame(['runs', 'audit'], $order);
	}
}
