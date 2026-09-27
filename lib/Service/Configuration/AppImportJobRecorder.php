<?php

/**
 * OpenRegister app import job recorder
 *
 * Gives every `importFromApp()` call its own import job id, stamps it on every
 * audit row the import writes, and remembers per app id which jobs created
 * objects, so an app's setup wizard can later remove the example set it
 * loaded through ImportService::softDeleteByImportJobId().
 *
 * The audit trail stays the canonical record of which object a job created
 * (see ObjectEntity::$importJobId). This class only keeps the job ids an app
 * needs to find again, in OpenRegister's own app config.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-an-app-configuration-import-must-run-under-its-own-import-job-id
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stamps app configuration imports and records the jobs that created objects.
 *
 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
 */
class AppImportJobRecorder {
	/**
	 * App config key prefix for the per-app job list (OpenRegister's namespace).
	 */
	public const KEY_PREFIX = 'import_jobs_';

	/**
	 * Most recent jobs kept per app id.
	 */
	public const MAX_JOBS = 50;

	/**
	 * Longest app config key Nextcloud accepts.
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * The import job ids that were active when each open begin() ran.
	 *
	 * A stack, because an import can run inside another import, and the outer
	 * one must get its own id back for the rows it writes afterwards.
	 *
	 * @var array<int, string|null>
	 */
	private array $outerJobIds = [];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper Holds the request-scoped stamp and counts stamped rows.
	 * @param IAppConfig       $appConfig        OpenRegister's app config, where the job lists live.
	 * @param LoggerInterface  $logger           Server-side diagnostics.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Start an import job: generate its id and stamp every audit row from now on.
	 *
	 * Always pair with end() in a `finally` block.
	 *
	 * @return string The new import job id (UUID v4).
	 *
	 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-an-app-configuration-import-must-run-under-its-own-import-job-id
	 */
	public function begin(): string {
		$this->outerJobIds[] = $this->auditTrailMapper->getRequestImportJobId();
		$importJobId = Uuid::v4()->toRfc4122();
		$this->auditTrailMapper->setRequestImportJobId(importJobId: $importJobId);

		return $importJobId;
	}//end begin()

	/**
	 * End the innermost import job and restore whatever stamp was active before it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-an-app-configuration-import-must-run-under-its-own-import-job-id
	 */
	public function end(): void {
		$outer = null;
		if ($this->outerJobIds !== []) {
			$outer = array_pop($this->outerJobIds);
		}

		$this->auditTrailMapper->setRequestImportJobId(importJobId: $outer);
	}//end end()

	/**
	 * Record a finished job for an app id when it created at least one traceable object.
	 *
	 * @param string $appId          The app id the import ran under (e.g. `learniq.demo`).
	 * @param string $importJobId    The job id begin() returned.
	 * @param string $version        The version the app imported.
	 * @param int    $objectsWritten How many objects the import reported writing.
	 *
	 * @return bool True when the job was recorded.
	 *
	 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
	 */
	public function record(string $appId, string $importJobId, string $version, int $objectsWritten): bool {
		$created = $this->auditTrailMapper->countByImportJobId(importJobId: $importJobId, action: 'create');
		if ($created === 0) {
			$this->warnWhenUntraceable(appId: $appId, importJobId: $importJobId, objectsWritten: $objectsWritten);
			return false;
		}

		$jobs = $this->jobs(appId: $appId);
		$jobs[] = [
			'jobId' => $importJobId,
			'version' => $version,
			'created' => $created,
			'importedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
		$this->store(appId: $appId, jobs: array_slice($jobs, -self::MAX_JOBS));

		return true;
	}//end record()

	/**
	 * The recorded jobs of an app id, oldest first.
	 *
	 * @param string $appId The app id the imports ran under.
	 *
	 * @return array<int, array{jobId: string, version: string, created: int, importedAt: string}>
	 *
	 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-an-app-must-be-able-to-remove-the-objects-its-recorded-imports-created
	 */
	public function jobs(string $appId): array {
		$stored = $this->read(key: $this->key(appId: $appId));
		if ($stored === null || ($stored['appId'] ?? null) !== $appId) {
			return [];
		}

		$jobs = [];
		foreach ((array)($stored['jobs'] ?? []) as $job) {
			if (is_array($job) === true && is_string($job['jobId'] ?? null) === true) {
				$jobs[] = [
					'jobId' => $job['jobId'],
					'version' => (string)($job['version'] ?? ''),
					'created' => (int)($job['created'] ?? 0),
					'importedAt' => (string)($job['importedAt'] ?? ''),
				];
			}
		}

		return $jobs;
	}//end jobs()

	/**
	 * Forget one job of an app id, after its objects were removed.
	 *
	 * @param string $appId       The app id the import ran under.
	 * @param string $importJobId The job to forget.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-an-app-must-be-able-to-remove-the-objects-its-recorded-imports-created
	 */
	public function forget(string $appId, string $importJobId): void {
		$kept = array_values(
			array_filter(
				$this->jobs(appId: $appId),
				static fn (array $job): bool => $job['jobId'] !== $importJobId
			)
		);
		$this->store(appId: $appId, jobs: $kept);
	}//end forget()

	/**
	 * The app id a recorded job belongs to, or null when no app recorded it.
	 *
	 * @param string $importJobId The job id to look up.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-the-http-rollback-route-must-refuse-an-app-imports-job-id
	 */
	public function appForJob(string $importJobId): ?string {
		foreach ($this->appConfig->getKeys('openregister') as $key) {
			if (str_starts_with($key, self::KEY_PREFIX) === false) {
				continue;
			}

			$stored = $this->read(key: $key);
			foreach ((array)($stored['jobs'] ?? []) as $job) {
				if (is_array($job) === true && ($job['jobId'] ?? null) === $importJobId) {
					return (string)($stored['appId'] ?? '');
				}
			}
		}

		return null;
	}//end appForJob()

	/**
	 * Warn when an import wrote objects and none of its audit rows carries the job id.
	 *
	 * That only happens when the audit trail is off. The import still worked,
	 * but it cannot be removed by job, and an empty list would read as
	 * "nothing to remove" rather than "nothing was traced".
	 *
	 * @param string $appId          The app id the import ran under.
	 * @param string $importJobId    The job id.
	 * @param int    $objectsWritten How many objects the import reported writing.
	 *
	 * @return void
	 */
	private function warnWhenUntraceable(string $appId, string $importJobId, int $objectsWritten): void {
		if ($objectsWritten === 0) {
			return;
		}

		if ($this->auditTrailMapper->countByImportJobId(importJobId: $importJobId, action: null) > 0) {
			return;
		}

		$this->logger->warning(
			message: sprintf(
				'[AppImportJobRecorder] The import for %s wrote %d object(s) and none carries import job %s. '
				. 'The audit trail is probably off, so this import cannot be removed by job.',
				$appId,
				$objectsWritten,
				$importJobId
			),
			context: ['app' => 'openregister', 'importJobId' => $importJobId]
		);
	}//end warnWhenUntraceable()

	/**
	 * Write an app id's job list.
	 *
	 * @param string                   $appId The app id.
	 * @param array<int, array<string, mixed>> $jobs  The jobs to keep.
	 *
	 * @return void
	 */
	private function store(string $appId, array $jobs): void {
		$key = $this->key(appId: $appId);
		if ($jobs === []) {
			$this->appConfig->deleteKey('openregister', $key);
			return;
		}

		$this->appConfig->setValueString(
			'openregister',
			$key,
			(string)json_encode(['appId' => $appId, 'jobs' => array_values($jobs)]),
			lazy: true
		);
	}//end store()

	/**
	 * Read and decode one stored job list.
	 *
	 * @param string $key The app config key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $key): ?array {
		$decoded = json_decode($this->appConfig->getValueString('openregister', $key, '', lazy: true), true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end read()

	/**
	 * The app config key for an app id's job list.
	 *
	 * Hashed when the plain key would pass Nextcloud's 64 character limit; the
	 * stored value carries the app id, so a lookup never needs to reverse it.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string
	 */
	private function key(string $appId): string {
		$key = self::KEY_PREFIX . $appId;
		if (strlen($key) <= self::MAX_KEY_LENGTH) {
			return $key;
		}

		return self::KEY_PREFIX . sha1($appId);
	}//end key()
}//end class
