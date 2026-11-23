<?php

/**
 * QualityAuditService — declares, runs and keeps data quality audits.
 *
 * A quality audit is the per-object scorer run over a declared population,
 * with the result kept (D-3). This service saves the declaration (refusing a
 * faulty one by name), executes the population query a page at a time, hands
 * the members to {@see QualityAuditEvaluator}, and stores the run with the
 * tolerance as it stood and the failing records beside it (D-4). Runs are
 * never changed after they are written, so the trend is a sequence of
 * verdicts as they were reached.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use DateTime;
use DateTimeImmutable;
use Generator;
use OCA\OpenRegister\Db\QualityAudit;
use OCA\OpenRegister\Db\QualityAuditRun;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Save, run, trend and export of quality audits.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditService {

	/**
	 * The population members read per page (ADR-009).
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * The failing records written per batch.
	 *
	 * @var int
	 */
	private const FAILURE_BATCH = 100;

	/**
	 * Who a scheduled run was started by.
	 *
	 * @var string
	 */
	public const STARTED_BY_SCHEDULE = 'schedule';

	/**
	 * The verdict of a run that could not finish.
	 *
	 * @var string
	 */
	public const VERDICT_ERROR = 'error';

	/**
	 * Constructor.
	 *
	 * @param QualityAuditStores        $stores    The audit definitions and their runs.
	 * @param QualityAuditEvaluator     $evaluator The definition check and the run, over the save-time scorer.
	 * @param ObjectOperationsInterface $objects   The population query.
	 * @param QualityScopeMappers       $scope     The register and schema lookups a population names.
	 * @param LoggerInterface           $logger    The logger.
	 */
	public function __construct(
		private readonly QualityAuditStores $stores,
		private readonly QualityAuditEvaluator $evaluator,
		private readonly ObjectOperationsInterface $objects,
		private readonly QualityScopeMappers $scope,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Save an audit declaration, new or changed. A faulty one is refused naming each fault.
	 *
	 * Changing an audit never touches its runs: each run keeps the tolerance
	 * it was judged against (D-4).
	 *
	 * @param array<string, mixed> $input    The declaration: name, population {register, schema, filters}, rules, tolerance, schedule.
	 * @param QualityAudit|null    $existing The audit being changed, or null for a new one.
	 * @param string|null          $actor    The user saving it.
	 *
	 * @return QualityAudit The saved audit.
	 *
	 * @throws QualityAuditRefusedException When the declaration has faults.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function save(array $input, ?QualityAudit $existing, ?string $actor): QualityAudit {
		$faults = $this->faultsOf(definition: $input);
		if ($faults !== []) {
			throw new QualityAuditRefusedException(faults: $faults);
		}

		$audit = ($existing ?? new QualityAudit());
		$population = (array)$input['population'];
		$audit->setName(trim((string)$input['name']));
		$audit->setRegisterRef((string)$population['register']);
		$audit->setSchemaRef((string)$population['schema']);
		$audit->setFilters((array)($population['filters'] ?? []));
		$audit->setRules(array_values((array)$input['rules']));
		$audit->setTolerance((float)$input['tolerance']);
		$audit->setSchedule($this->scheduleOf(input: $input));
		if ($existing === null) {
			$audit->setCreatedBy($actor);
		}

		return $this->stores->audits->persist(audit: $audit);
	}//end save()

	/**
	 * Run an audit now and keep the run.
	 *
	 * The run row is written first and its outcome after, so its failing
	 * records can be written in batches while the population is read in pages.
	 *
	 * @param QualityAudit      $audit     The audit.
	 * @param string            $startedBy The user id, or `schedule`.
	 * @param DateTimeImmutable $now       The moment of the run.
	 *
	 * @return QualityAuditRun The kept run.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function run(QualityAudit $audit, string $startedBy, DateTimeImmutable $now): QualityAuditRun {
		$run = new QualityAuditRun();
		$run->setAuditId((int)$audit->getId());
		$run->setStartedAt(DateTime::createFromImmutable($now));
		$run->setStartedBy($startedBy);
		$run->setTolerance((float)$audit->getTolerance());
		$run = $this->stores->runs->persist(run: $run);

		try {
			$result = $this->evaluated(audit: $audit, run: $run, now: $now);
		} catch (Throwable $e) {
			// A run that could not finish says so, rather than sitting in the
			// trend as a run with no verdict that reads as still going.
			$run->setVerdict(self::VERDICT_ERROR);
			$this->stores->runs->persist(run: $run);

			throw $e;
		}

		$run->setEvaluated($result['evaluated']);
		$run->setScore($result['score']);
		$run->setVerdict($result['verdict']);
		$run->setFailed($result['failed']);
		$run = $this->stores->runs->persist(run: $run);

		$audit->setLastRunAt(DateTime::createFromImmutable($now));
		$this->stores->audits->persist(audit: $audit);

		return $run;
	}//end run()

	/**
	 * Evaluate the population into a stored run, writing its failures in batches.
	 *
	 * @param QualityAudit      $audit The audit.
	 * @param QualityAuditRun   $run   The stored run.
	 * @param DateTimeImmutable $now   The moment of the run.
	 *
	 * @return array{evaluated: int, score: float, verdict: string, failed: int} The outcome.
	 */
	private function evaluated(QualityAudit $audit, QualityAuditRun $run, DateTimeImmutable $now): array {
		$runId = (int)$run->getId();
		$buffer = [];
		$result = $this->evaluator->evaluate(
			audit: $audit->definition(),
			population: $this->population(audit: $audit),
			now: $now,
			startedBy: (string)$run->getStartedBy(),
			onFailure: function (array $failure) use (&$buffer, $runId): void {
				$buffer[] = $failure;
				if (count($buffer) >= self::FAILURE_BATCH) {
					$this->stores->runs->addFailures(runId: $runId, failures: $buffer);
					$buffer = [];
				}
			}
		);
		if ($buffer !== []) {
			$this->stores->runs->addFailures(runId: $runId, failures: $buffer);
		}

		return $result;
	}//end evaluated()

	/**
	 * Run every scheduled audit that is due. One audit failing does not stop the rest.
	 *
	 * @param DateTimeImmutable $now The moment of the pass.
	 *
	 * @return int The number of audits run.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function runDue(DateTimeImmutable $now): int {
		$ran = 0;
		foreach ($this->stores->audits->findScheduled() as $audit) {
			if ($audit->isDue(now: DateTime::createFromImmutable($now)) === false) {
				continue;
			}

			try {
				$this->run(audit: $audit, startedBy: self::STARTED_BY_SCHEDULE, now: $now);
				$ran++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'[QualityAudit] Scheduled run of audit '.(string)$audit->getUuid().' failed: '.$e->getMessage(),
					['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		return $ran;
	}//end runDue()

	/**
	 * An audit's runs in sequence, oldest first: the trend.
	 *
	 * @param QualityAudit $audit The audit.
	 * @param ListQuery    $query The page.
	 *
	 * @return QualityAuditRun[] The runs.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function trend(QualityAudit $audit, ListQuery $query): array {
		return $this->stores->runs->findByAudit(auditId: (int)$audit->getId(), query: $query);
	}//end trend()

	/**
	 * The failing records of a run as CSV, read a page at a time.
	 *
	 * @param QualityAuditRun $run The run.
	 *
	 * @return string The CSV: `record,rules`, one row per failing record.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function failuresCsv(QualityAuditRun $run): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, ['record', 'rules'], ',', '"', '');
		$offset = 0;
		do {
			$page = $this->stores->runs->findFailures(runId: (int)$run->getId(), query: new ListQuery(limit: self::PAGE, offset: $offset));
			foreach ($page as $failure) {
				fputcsv($handle, [(string)$failure->getRecord(), implode('; ', ($failure->getRules() ?? []))], ',', '"', '');
			}

			$offset += self::PAGE;
		} while (count($page) === self::PAGE);

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end failuresCsv()

	/**
	 * Remove an audit with its runs and their failing records.
	 *
	 * @param QualityAudit $audit The audit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function delete(QualityAudit $audit): void {
		$this->stores->runs->deleteByAudit(auditId: (int)$audit->getId());
		$this->stores->audits->remove(audit: $audit);
	}//end delete()

	/**
	 * Every fault of a declaration: the evaluator's, the schedule's, and a
	 * population naming a register or schema that does not exist.
	 *
	 * @param array<string, mixed> $definition The declaration.
	 *
	 * @return array<int, string> The faults.
	 */
	private function faultsOf(array $definition): array {
		$faults = $this->evaluator->definitionFaults(audit: $definition);

		$schedule = ($definition['schedule'] ?? null);
		if ($schedule !== null && $schedule !== '' && in_array($schedule, QualityAudit::SCHEDULES, true) === false) {
			$faults[] = 'schedule: one of '.implode(', ', QualityAudit::SCHEDULES).', or none for on demand.';
		}

		if ($faults !== []) {
			return $faults;
		}

		// Only a well-formed population is looked up: a lookup of a missing
		// reference would name the same fault twice.
		return $this->populationTargetFaults(population: (array)$definition['population']);
	}//end faultsOf()

	/**
	 * The faults of a population whose register or schema does not resolve.
	 *
	 * @param array<string, mixed> $population The population.
	 *
	 * @return array<int, string> The faults.
	 */
	private function populationTargetFaults(array $population): array {
		$faults = [];
		try {
			$this->scope->registerMapper->find(id: $population['register'], _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			$faults[] = sprintf('population.register: no register "%s".', (string)$population['register']);
		}

		try {
			$this->scope->schemaMapper->find(id: $population['schema'], _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			$faults[] = sprintf('population.schema: no schema "%s".', (string)$population['schema']);
		}

		return $faults;
	}//end populationTargetFaults()

	/**
	 * The declared schedule, or null for on demand.
	 *
	 * @param array<string, mixed> $input The declaration.
	 *
	 * @return string|null The schedule.
	 */
	private function scheduleOf(array $input): ?string {
		$schedule = ($input['schedule'] ?? null);
		if ($schedule === null || $schedule === '') {
			return null;
		}

		return (string)$schedule;
	}//end scheduleOf()

	/**
	 * The population of an audit, read a page at a time.
	 *
	 * The query runs without the caller's RBAC: an audit is declared and run
	 * by an administrator over the whole population it names, and a scheduled
	 * run has no caller at all. What it returns is identities and rule labels,
	 * never the records' contents.
	 *
	 * @param QualityAudit $audit The audit.
	 *
	 * @return Generator<int, mixed> The members.
	 */
	private function population(QualityAudit $audit): Generator {
		$filters = array_merge(
			($audit->getFilters() ?? []),
			['register' => $audit->getRegisterRef(), 'schema' => $audit->getSchemaRef()]
		);

		$offset = 0;
		do {
			$page = $this->objects->findAll(
				config: ['filters' => $filters, 'limit' => self::PAGE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($page as $member) {
				yield $member;
			}

			$offset += self::PAGE;
		} while (count($page) === self::PAGE);
	}//end population()
}//end class
