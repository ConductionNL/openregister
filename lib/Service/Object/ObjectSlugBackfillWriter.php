<?php

/**
 * OpenRegister ObjectSlugBackfillWriter
 *
 * Writes a slug backfill plan in chunked transactions, evicts the cache and
 * records the run in the audit trail (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SlugBackfillWriteException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The write side of the slug backfill.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugBackfillWriter {

	/**
	 * Rows per write transaction, so a large table does not hold its row locks
	 * for the whole write and one failure does not roll back every row.
	 */
	private const WRITE_CHUNK = 500;

	/**
	 * The audit action suffix of a run: the backfill only fills empty slugs.
	 */
	public const MODE = 'fill';

	/**
	 * Constructor.
	 *
	 * @param MagicMapper      $magicMapper  Writes the slug column.
	 * @param CacheHandler     $cacheHandler Object cache, evicted after writing.
	 * @param IDBConnection    $db           Wraps each chunk in a transaction.
	 * @param AuditTrailMapper $auditTrail   Records the audit entry of a run that changed slugs.
	 * @param LoggerInterface  $logger       Receives the skipped-row warnings.
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly CacheHandler $cacheHandler,
		private readonly IDBConnection $db,
		private readonly AuditTrailMapper $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write a plan in chunked transactions, evict the schema's cached objects and
	 * record the run in the audit trail (one entry when at least one slug changed).
	 *
	 * Each write only applies when the row still has no slug, as the plan was
	 * made against; a row a normal save gave a slug meanwhile is skipped and
	 * logged. A chunk that fails is rolled back, the chunks before it stay
	 * written and the audit entry lists exactly those.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The planned writes.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $summary  The run's counters (copied into the audit entry).
	 *
	 * @return int The number of planned writes skipped because the row changed.
	 *
	 * @throws SlugBackfillWriteException When a write fails (that chunk is rolled back first; the
	 *                                     earlier chunks are audited and evicted before it is thrown).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function write(array $plan, Register $register, Schema $schema, array $summary): int {
		$skipped = 0;
		$applied = [];
		$failure = null;
		try {
			foreach (array_chunk($plan, self::WRITE_CHUNK) as $chunk) {
				$this->writeChunk(chunk: $chunk, register: $register, schema: $schema, applied: $applied, skipped: $skipped);
			}
		} catch (Throwable $e) {
			$failure = $e;
		}

		// Also after a failed chunk: what the earlier chunks wrote is real, so the
		// entry must list it and the cache must not keep serving the old slugs. A run
		// that changed nothing leaves no entry (the console summary still says so).
		if ($applied !== []) {
			$this->recordAudit(applied: $applied, register: $register, schema: $schema, summary: array_merge($summary, ['skipped' => $skipped]));
			$this->evictCache(register: $register, schema: $schema);
		}

		if ($failure !== null) {
			throw new SlugBackfillWriteException(applied: count($applied), skipped: $skipped, previous: $failure);
		}

		return $skipped;
	}//end write()

	/**
	 * Write one chunk of the plan in one transaction.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $chunk    The chunk.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $applied  Steps that were written, by reference.
	 * @param int      $skipped  Steps skipped because the row changed, by reference.
	 *
	 * @return void
	 *
	 * @throws Throwable When a write fails (the chunk is rolled back first).
	 */
	private function writeChunk(array $chunk, Register $register, Schema $schema, array &$applied, int &$skipped): void {
		// The plan was made against the slugs held when the run started; a save
		// during a long run may have taken one since. Re-check the chunk's slugs
		// (one query) right before writing it, so the window is one chunk, not the run.
		$holders = $this->magicMapper->findSlugState(
			register: $register,
			schema: $schema,
			slugs: array_values(array_filter(array_column($chunk, 'new'), static fn ($slug): bool => is_string($slug) && $slug !== '')),
			uuids: []
		)['holders'] ?? [];

		$written = [];
		$this->db->beginTransaction();
		try {
			foreach ($chunk as $step) {
				if ($this->takenMeanwhile(step: $step, holders: $holders) === true) {
					$skipped++;
					$this->logger->warning(
						sprintf(
							'[ObjectSlug] Backfill skipped object %s in register %s / schema %s: slug "%s" was taken by another object while the backfill ran',
							$step['uuid'],
							(string)$register->getSlug(),
							(string)$schema->getSlug(),
							(string)$step['new']
						),
						['app' => 'openregister', 'path' => 'backfill', 'objectUuid' => $step['uuid']]
					);
					continue;
				}

				$updated = $this->magicMapper->updateSlugColumn(
					register: $register,
					schema: $schema,
					uuid: $step['uuid'],
					slug: $step['new'],
					expected: $step['old']
				);
				if ($updated > 0) {
					$written[] = $step;
					continue;
				}

				$skipped++;
				$this->logger->warning(
					sprintf(
						'[ObjectSlug] Backfill skipped object %s in register %s / schema %s: its slug changed while the backfill ran',
						$step['uuid'],
						(string)$register->getSlug(),
						(string)$schema->getSlug()
					),
					['app' => 'openregister', 'path' => 'backfill', 'objectUuid' => $step['uuid']]
				);
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		array_push($applied, ...$written);
	}//end writeChunk()

	/**
	 * Whether another live object took the planned slug after the plan was made.
	 *
	 * @param array{uuid: string, old: ?string, new: ?string} $step    The planned write.
	 * @param array<string, list<string>>                     $holders Slug => live holder uuids, read just now.
	 *
	 * @return bool True when someone else holds it now.
	 */
	private function takenMeanwhile(array $step, array $holders): bool {
		foreach ($holders[(string)$step['new']] ?? [] as $holder) {
			if ($holder !== $step['uuid']) {
				return true;
			}
		}

		return false;
	}//end takenMeanwhile()

	/**
	 * Evict the schema's cached objects; a failure is logged, not thrown, so it
	 * cannot hide the run's own outcome.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return void
	 */
	private function evictCache(Register $register, Schema $schema): void {
		try {
			$this->cacheHandler->invalidateForObjectChange(
				object: null,
				operation: 'bulk_update',
				registerId: (int)$register->getId(),
				schemaId: (int)$schema->getId()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf(
					'[ObjectSlug] Could not evict the cache after the slug backfill (register %s, schema %s): %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					$e->getMessage()
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end evictCache()

	/**
	 * One audit-trail entry for the run, with the old to new list (capped, see logLargeRun()).
	 *
	 * A failure to write the entry is logged with the full list, so the change
	 * stays reconstructable, and does not undo the slugs already written.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $applied The writes that were applied.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $summary  The run's counters.
	 *
	 * @return void
	 */
	private function recordAudit(array $applied, Register $register, Schema $schema, array $summary): void {
		$this->logLargeRun(applied: $applied, register: $register, schema: $schema);
		try {
			$this->auditTrail->createSlugBackfillEntry(
				mode: self::MODE,
				changes: $applied,
				summary: $summary,
				register: (int)$register->getId(),
				schema: (int)$schema->getId()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf(
					'[ObjectSlug] Could not write the audit entry of the slug backfill (register %s, schema %s): %s; changes: %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					$e->getMessage(),
					$this->encodeChanges(changes: $applied)
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end recordAudit()

	/**
	 * A change list as JSON for a log line; invalid UTF-8 in a slug is replaced, not fatal.
	 *
	 * @param array $changes The changes.
	 *
	 * @return string The JSON, or a marker when it cannot be encoded.
	 */
	private function encodeChanges(array $changes): string {
		$json = json_encode($changes, JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return '[unencodable]';
		}

		return $json;
	}//end encodeChanges()

	/**
	 * Put the full change list in the log when the audit entry cannot hold it.
	 *
	 * The entry lists the first {@see AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES}
	 * changes; the rest of a large run is reconstructable from these lines.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $applied  The writes that were applied.
	 * @param Register                                              $register The register.
	 * @param Schema                                                $schema   The schema.
	 *
	 * @return void
	 */
	private function logLargeRun(array $applied, Register $register, Schema $schema): void {
		if (count($applied) <= AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES) {
			return;
		}

		$parts = array_chunk($applied, AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES);
		foreach ($parts as $index => $part) {
			// Warning, not info: Nextcloud's default loglevel (2) drops info lines,
			// and these are the only record of the changes beyond the audit entry.
			$this->logger->warning(
				message: sprintf(
					'[ObjectSlug] Slug backfill changes (register %s, schema %s, part %d of %d): %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					($index + 1),
					count($parts),
					$this->encodeChanges(changes: $part)
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end logLargeRun()
}//end class
