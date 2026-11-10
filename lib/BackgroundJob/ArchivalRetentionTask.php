<?php

/**
 * OpenRegister ArchivalRetentionTask
 *
 * Hourly cron that sweeps every schema declaring `x-openregister-archival`
 * and deletes rows past their effective retention. The sweep is the ONLY
 * legitimate delete path on archival schemas — user-driven deletes are
 * rejected with HTTP 403 SCHEMA_ARCHIVAL_IMMUTABLE.
 *
 * For each `(register, schema)` pair with archival declared:
 *   1. Resolve the magic table name via `MagicTableHandler::getTableNameForRegisterSchema()`.
 *   2. Run a single native `SELECT _uuid, _created, …` against the table.
 *   3. For each row evaluate `RetentionEvaluator::evaluate()`.
 *   4. Rows whose `expiresAt < now()` are loaded as objects and checked for an
 *      ACTIVE LEGAL HOLD. A held row is left in place and counted; it is never
 *      deleted, and a row whose object cannot be loaded is left in place too,
 *      because a row that cannot answer is exactly the row that might be held.
 *   5. The rest are deleted via
 *      `ObjectService::deleteObject(..., _retentionSweep: true)` so the
 *      immutability gate is bypassed but the standard audit-trail entry
 *      still fires.
 *   6. Emit one structured log entry per schema:
 *      `{schemaSlug, scanned, expired, deleted, held, unresolved}`, and notify
 *      the archivist group whenever a hold stopped a deletion.
 *
 * THE HOLD CHECK IS NOT OPTIONAL ON THIS PATH. `_retentionSweep: true` is the
 * flag that waves a row past the archival immutability gate, so a hold that is
 * not asked about here is not asked about anywhere on this path. The check
 * mirrors `DestructionExecutionJob`, which re-checks the hold at execution
 * time, counts skipped holds separately and notifies the archivist group.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\OpenRegister\BackgroundJob\Support\ArchivalRetentionPlatform;
use OCA\OpenRegister\BackgroundJob\Support\ArchivalRetentionSources;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * Background job that enforces `x-openregister-archival` retention rules.
 *
 * Scheduled hourly, time-insensitive, single-instance.
 *
 * @psalm-suppress UnusedClass Registered in appinfo/info.xml <background-jobs>.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
class ArchivalRetentionTask extends TimedJob {

	/**
	 * Database connection for native row scans.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Register repository used to enumerate active registers.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema repository used to enumerate annotated schemas.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Magic-table resolver used to map registers/schemas to physical tables.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Object service used to perform the sweep deletes.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Retention service, asked whether a row is under an active legal hold.
	 *
	 * @var RetentionServiceInterface
	 */
	private readonly RetentionServiceInterface $retentionService;

	/**
	 * App container the notification collaborators are resolved from.
	 *
	 * Resolved lazily rather than injected, so the hourly cron only builds the
	 * group and notification managers on the runs that actually skipped a hold.
	 * `DestructionExecutionJob` resolves all of its collaborators the same way.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * Logger for per-schema summaries.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Expiry of each scanned row.
	 *
	 * @var ArchivalRowExpiry
	 */
	private readonly ArchivalRowExpiry $rowExpiry;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param ArchivalRetentionSources $sources Database and the register, schema and magic-table lookups.
	 * @param ArchivalRetentionPlatform $platform Delete path, legal-hold answer, notification container and logger.
	 */
	public function __construct(
		ITimeFactory $time,
		ArchivalRetentionSources $sources,
		ArchivalRetentionPlatform $platform,
	) {
		parent::__construct(time: $time);

		$this->db = $sources->db;
		$this->registerMapper = $sources->registerMapper;
		$this->schemaMapper = $sources->schemaMapper;
		$this->magicMapper = $sources->magicMapper;
		$this->objectService = $platform->objectService;
		$this->retentionService = $platform->retentionService;
		$this->container = $platform->container;
		$this->logger = $platform->logger;

		$this->rowExpiry = new ArchivalRowExpiry(logger: $platform->logger);

		// Run every hour (3600 seconds).
		$this->setInterval(seconds: 3600);

		// Delay until low-load time.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the retention sweep across every archival-annotated schema.
	 *
	 * @param mixed $argument Background-job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	protected function run($argument): void {
		$now       = new DateTimeImmutable();
		$registers = $this->loadRegisters();
		if ($registers === null) {
			return;
		}

		foreach ($registers as $register) {
			// Defensive: findAll() returns Register[] per docblock; this
			// guard simply hardens the loop against future mapper changes.
			if (($register instanceof Register) === true) {
				$this->sweepRegister(register: $register, now: $now);
			}
		}
	}//end run()

	/**
	 * Every register, read unscoped; null (with an error log) when they cannot be read.
	 *
	 * @return array|null
	 */
	private function loadRegisters(): ?array {
		try {
			return $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Exception $error) {
			$this->logger->error(
				'[ArchivalRetentionTask] Failed to load registers: '.$error->getMessage(),
				['app' => 'openregister', 'exception' => $error]
			);
			return null;
		}
	}//end loadRegisters()

	/**
	 * Sweep every archival-annotated schema of one register.
	 *
	 * @param Register          $register Register to sweep.
	 * @param DateTimeInterface $now      Sweep wall-clock anchor.
	 *
	 * @return void
	 */
	private function sweepRegister(Register $register, DateTimeInterface $now): void {
		foreach (($register->getSchemas() ?? []) as $schemaId) {
			$schema = $this->loadSchema(schemaId: $schemaId);
			if ($schema === null) {
				continue;
			}

			$annotation = $this->extractArchivalAnnotation(schema: $schema);
			if ($annotation !== null) {
				$this->sweepSchema(sweep: new ArchivalSweep(register: $register, schema: $schema, annotation: $annotation, now: $now));
			}
		}
	}//end sweepRegister()

	/**
	 * Load a schema of a register, or null (with a warning) when it cannot be loaded.
	 *
	 * @param mixed $schemaId Schema id as stored on the register.
	 *
	 * @return Schema|null
	 */
	private function loadSchema(mixed $schemaId): ?Schema {
		try {
			return $this->schemaMapper->find((int)$schemaId);
		} catch (Exception $error) {
			$this->warn(message: sprintf('Schema #%s not loadable: %s', (string)$schemaId, $error->getMessage()));
			return null;
		}
	}//end loadSchema()

	/**
	 * Return the `x-openregister-archival` annotation when present, or null.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null Annotation block or null when absent.
	 */
	private function extractArchivalAnnotation(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		if (is_array($configuration) === false) {
			return null;
		}

		$annotation = ($configuration['x-openregister-archival'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		return $annotation;
	}//end extractArchivalAnnotation()

	/**
	 * Sweep a single `(register, schema)` pair.
	 *
	 * Pulls every row from the magic table, evaluates the retention rules
	 * against each, and deletes rows past their expiry UNLESS an active legal
	 * hold keeps them. Logs a per-schema summary
	 * `{schemaSlug, scanned, expired, deleted, held, unresolved}`.
	 *
	 * @param ArchivalSweep $sweep The schema being swept and its counters.
	 *
	 * @return void
	 */
	private function sweepSchema(ArchivalSweep $sweep): void {
		// Skip a schema whose magic table has not been materialised yet.
		if ($this->tableReady(sweep: $sweep) === false) {
			return;
		}

		$this->scanTable(sweep: $sweep);
		$this->logSweepSummary(sweep: $sweep);

		if ($sweep->held > 0) {
			$this->notifySkippedHolds(sweep: $sweep);
		}
	}//end sweepSchema()

	/**
	 * Whether the schema's magic table exists; false when that cannot be checked.
	 *
	 * @param ArchivalSweep $sweep The schema being swept.
	 *
	 * @return bool
	 */
	private function tableReady(ArchivalSweep $sweep): bool {
		try {
			return $this->magicMapper->tableExistsForRegisterSchema(register: $sweep->register, schema: $sweep->schema);
		} catch (Exception $error) {
			$this->warn(
				message: sprintf('Cannot check magic-table existence for schema "%s": %s', $sweep->schemaSlug, $error->getMessage())
			);
			return false;
		}
	}//end tableReady()

	/**
	 * Read every row of the schema's table and sweep it.
	 *
	 * Pulling `*` keeps the scan table-shape-agnostic.
	 *
	 * @param ArchivalSweep $sweep The schema being swept.
	 *
	 * @return void
	 */
	private function scanTable(ArchivalSweep $sweep): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($sweep->tableName);
			$cursor = $qb->executeQuery();
			while (($row = $cursor->fetch()) !== false) {
				$this->sweepRow(sweep: $sweep, row: $row);
			}

			$cursor->closeCursor();
		} catch (Exception $error) {
			$message = sprintf('[ArchivalRetentionTask] Sweep failed on schema "%s": %s', $sweep->schemaSlug, $error->getMessage());
			$this->logger->error($message, ['app' => 'openregister', 'exception' => $error]);
		}
	}//end scanTable()

	/**
	 * Count one row and, when it is past its retention, dispose of it.
	 *
	 * @param ArchivalSweep        $sweep The schema being swept.
	 * @param array<string, mixed> $row   Raw table row.
	 *
	 * @return void
	 */
	private function sweepRow(ArchivalSweep $sweep, array $row): void {
		$sweep->scanned++;

		$expiresAt = $this->rowExpiry->expiryOf(sweep: $sweep, row: $row);
		if ($expiresAt === null || $expiresAt >= $sweep->now) {
			return;
		}

		$sweep->expired++;

		$uuid = (string)($row['_uuid'] ?? '');
		if ($uuid !== '') {
			$this->disposeExpired(sweep: $sweep, uuid: $uuid);
		}
	}//end sweepRow()

	/**
	 * Delete an expired row unless it is held or cannot be loaded.
	 *
	 * A HELD RECORD IS NEVER SWEPT. The delete hands `_retentionSweep: true`
	 * to ObjectService, which is the flag that waves the row past the archival
	 * immutability gate, so this is the last place the hold can still be asked
	 * about. A row whose object cannot be loaded FAILS CLOSED: it cannot be
	 * asked about its hold, so it is kept.
	 *
	 * @param ArchivalSweep $sweep The schema being swept.
	 * @param string        $uuid  Row uuid.
	 *
	 * @return void
	 */
	private function disposeExpired(ArchivalSweep $sweep, string $uuid): void {
		$object = $this->loadSweepTarget(sweep: $sweep, uuid: $uuid);
		if ($object === null) {
			$sweep->unresolved++;
			return;
		}

		if ($this->retentionService->hasActiveLegalHold(object: $object) === true) {
			$sweep->held++;
			$this->logHeld(sweep: $sweep, uuid: $uuid);
			return;
		}

		$this->deleteExpired(sweep: $sweep, uuid: $uuid);
	}//end disposeExpired()

	/**
	 * Log that a legal hold kept a row past its retention.
	 *
	 * @param ArchivalSweep $sweep The schema being swept.
	 * @param string        $uuid  Row uuid.
	 *
	 * @return void
	 */
	private function logHeld(ArchivalSweep $sweep, string $uuid): void {
		$this->logger->info(
			sprintf('[ArchivalRetentionTask] legal hold kept row schema="%s" uuid="%s" past retention', $sweep->schemaSlug, $uuid),
			['app' => 'openregister', 'schemaSlug' => $sweep->schemaSlug, 'uuid' => $uuid]
		);
	}//end logHeld()

	/**
	 * Delete an expired row through ObjectService, past the immutability gate.
	 *
	 * ObjectService is re-anchored at the swept `(register, schema)` first, so
	 * the delete pipeline sees the right context.
	 *
	 * @param ArchivalSweep $sweep The schema being swept.
	 * @param string        $uuid  Row uuid.
	 *
	 * @return void
	 */
	private function deleteExpired(ArchivalSweep $sweep, string $uuid): void {
		try {
			$this->objectService->setRegister(register: $sweep->register);
			$this->objectService->setSchema(schema: $sweep->schema);

			$deleteOk = $this->objectService->deleteObject(
				deletion: new ObjectDeletion(uuid: $uuid),
				_rbac: false,
				_multitenancy: false,
				_retentionSweep: true
			);
			if ($deleteOk === true) {
				$sweep->deleted++;
			}
		} catch (Exception $error) {
			$this->warn(
				message: sprintf('Delete failed on schema "%s" uuid "%s": %s', $sweep->schemaSlug, $uuid, $error->getMessage())
			);
		}
	}//end deleteExpired()

	/**
	 * Log the per-schema counters.
	 *
	 * SKIPPING MUST BE VISIBLE: `held` and `unresolved` are counted apart from
	 * `deleted`, as DestructionExecutionJob counts `skippedHolds` apart from
	 * `destroyedCount`.
	 *
	 * @param ArchivalSweep $sweep The schema that was swept.
	 *
	 * @return void
	 */
	private function logSweepSummary(ArchivalSweep $sweep): void {
		$counts = $sweep->counts();
		$this->logger->info(
			vsprintf(
				'[ArchivalRetentionTask] schema="%s" scanned=%d expired=%d deleted=%d held=%d unresolved=%d',
				[$sweep->schemaSlug, ...array_values($counts)]
			),
			['app' => 'openregister', 'schemaSlug' => $sweep->schemaSlug] + $counts
		);
	}//end logSweepSummary()

	/**
	 * Log a warning under the task's prefix.
	 *
	 * @param string $message Message after the prefix.
	 *
	 * @return void
	 */
	private function warn(string $message): void {
		$this->logger->warning('[ArchivalRetentionTask] '.$message, ['app' => 'openregister']);
	}//end warn()

	/**
	 * Load the object behind an expired row, or null when it cannot be read.
	 *
	 * Bounded by `(register, schema)` so the lookup stays a single-table read
	 * rather than a union across every magic table, and read unscoped because
	 * cron has no session at all: an RBAC- or tenant-scoped read would answer
	 * "not found" for a row that plainly exists.
	 *
	 * @param ArchivalSweep $sweep The schema being swept.
	 * @param string        $uuid  Row uuid.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be loaded.
	 */
	private function loadSweepTarget(ArchivalSweep $sweep, string $uuid): ?ObjectEntity {
		try {
			return $this->magicMapper->find($uuid, $sweep->register, $sweep->schema, false, false, false);
		} catch (\Throwable $error) {
			$this->warn(
				message: sprintf(
					'Kept row on "%s" uuid "%s": its legal-hold status could not be read: %s',
					$sweep->schemaSlug,
					$uuid,
					$error->getMessage()
				)
			);
			return null;
		}
	}//end loadSweepTarget()

	/**
	 * Tell the archivist group that a legal hold stopped a retention delete.
	 *
	 * Mirrors `DestructionExecutionJob::notifySkippedHolds()`: a skipped hold
	 * that only ever reaches the cron log is a skip nobody acts on.
	 *
	 * @param ArchivalSweep $sweep The schema whose holds were skipped.
	 *
	 * @return void
	 */
	private function notifySkippedHolds(ArchivalSweep $sweep): void {
		try {
			$notificationManager = $this->container->get(INotificationManager::class);
			foreach ($this->archivists() as $user) {
				$notification = $notificationManager->createNotification();
				$notification->setApp('openregister')->setUser($user->getUID());
				$notificationManager->notify($this->describeHoldSkip(sweep: $sweep, notification: $notification));
			}
		} catch (\Throwable $error) {
			$this->logger->warning(
				'[ArchivalRetentionTask] Hold notify error: '.$error->getMessage(),
				['app' => 'openregister']
			);
		}
	}//end notifySkippedHolds()

	/**
	 * Members of the archivist group; none when the group does not exist.
	 *
	 * @return IUser[]
	 */
	private function archivists(): array {
		$group = $this->container->get(IGroupManager::class)->get('archivaris');
		if ($group === null) {
			return [];
		}

		return $group->getUsers();
	}//end archivists()

	/**
	 * Fill in the time, object and subject of a skipped-hold notification.
	 *
	 * @param ArchivalSweep $sweep        The schema whose holds were skipped.
	 * @param INotification $notification Notification addressed to one archivist.
	 *
	 * @return INotification The same notification.
	 */
	private function describeHoldSkip(ArchivalSweep $sweep, INotification $notification): INotification {
		$notification->setDateTime(new DateTime())
			->setObject('retention_sweep', $sweep->schemaSlug)
			->setSubject('retention_holds_skipped', ['schemaSlug' => $sweep->schemaSlug, 'skippedCount' => $sweep->held]);
		return $notification;
	}//end describeHoldSkip()

}//end class
