<?php

/**
 * Progress and elapsed time of the import jobs that are running.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes `import_objects_processed_total{job_id,phase}` and `import_duration_seconds{job_id}`.
 *
 * A bulk import is an `openregister_import_previews` job: it first decides every row
 * (`previewing`, progress in `processed`) and then writes the decisions (`committing`,
 * progress in `applied` + `failed`). Only jobs in one of those two states are published, so
 * the series end with the job and the label set stays as small as the number of running
 * imports. The duration is the time since the job was created. Jobs that cannot be read
 * publish no samples, with a warning.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */
class ImportJobMetricsProvider implements IMetricsProvider {

	/**
	 * The import job table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_import_previews';

	/**
	 * The running import states; equal to ImportPreview::STATE_PREVIEWING and STATE_COMMITTING (pinned by a test).
	 *
	 * @var string
	 */
	public const STATE_PREVIEWING = 'previewing';

	/**
	 * The committing import state.
	 *
	 * @var string
	 */
	public const STATE_COMMITTING = 'committing';

	/**
	 * The phase label of each running state.
	 *
	 * @var array<string, string>
	 */
	private const PHASES = [
		self::STATE_PREVIEWING => 'preview',
		self::STATE_COMMITTING => 'commit',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Reads the running jobs.
	 * @param ITimeFactory    $time   The clock the elapsed time ends at.
	 * @param LoggerInterface $logger Logs jobs that cannot be read.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The progress counter, then the elapsed time gauge.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
	 */
	public function metrics(): array {
		$processed = [];
		$durations = [];
		$now       = $this->time->getTime();
		foreach ($this->runningJobs() as $job) {
			$jobId = (string) ($job['uuid'] ?? '');
			$phase = (self::PHASES[(string) ($job['state'] ?? '')] ?? null);
			if ($jobId === '' || $phase === null) {
				continue;
			}

			$done = (int) ($job['processed'] ?? 0);
			if ($phase === 'commit') {
				$done = ((int) ($job['applied'] ?? 0) + (int) ($job['failed'] ?? 0));
			}

			$processed[] = ['labels' => ['job_id' => $jobId, 'phase' => $phase], 'value' => $done];

			$started = self::timestampOf(value: ($job['created'] ?? null));
			if ($started !== null) {
				$durations[] = ['labels' => ['job_id' => $jobId], 'value' => max(0, ($now - $started))];
			}
		}

		return [
			new MetricSample(
				name: 'import_objects_processed_total',
				type: 'counter',
				help: 'Rows a running import job has decided (phase preview) or written (phase commit)',
				samples: $processed
			),
			new MetricSample(
				name: 'import_duration_seconds',
				type: 'gauge',
				help: 'Seconds since a running import job was created',
				samples: $durations
			),
		];
	}//end metrics()

	/**
	 * The jobs in a running state, or none when they cannot be read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function runningJobs(): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('uuid', 'state', 'processed', 'applied', 'failed', 'created')
				->from(self::TABLE)
				->where($qb->expr()->in('state', $qb->createNamedParameter(array_keys(self::PHASES), IQueryBuilder::PARAM_STR_ARRAY)))
				->orderBy('id');
			$result = $qb->executeQuery();
			$rows   = $result->fetchAll();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] import job progress could not be read: '.$e->getMessage());
			return [];
		}

		return $rows;
	}//end runningJobs()

	/**
	 * The Unix time of a stored creation time (UTC), or null when there is none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function timestampOf(mixed $value): ?int {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
		} catch (Throwable $e) {
			return null;
		}
	}//end timestampOf()
}//end class
