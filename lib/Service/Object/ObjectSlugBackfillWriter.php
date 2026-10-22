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
	 * Each write only applies when the row still holds the slug the plan was
	 * made against; a row a normal save changed meanwhile is skipped and logged,
	 * and so is every later step that wanted the slug that row still holds. The
	 * plan lists a row that vacates a slug before the row that takes it.
	 * A chunk that fails is rolled back, the chunks before it stay written and
	 * the audit entry lists exactly those.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The planned writes.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param string   $mode     `fill` or `reslug`.
	 * @param array    $summary  The run's counters (copied into the audit entry).
	 *
	 * @return int The number of planned writes skipped because the row changed.
	 *
	 * @throws SlugBackfillWriteException When a write fails (that chunk is rolled back first; the
	 *                                     earlier chunks are audited and evicted before it is thrown).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function write(array $plan, Register $register, Schema $schema, string $mode, array $summary): int {
		$skipped = 0;
		$applied = [];
		$failure = null;
		$stillHeld = [];
		try {
			foreach (array_chunk($plan, self::WRITE_CHUNK) as $chunk) {
				$this->writeChunk(chunk: $chunk, register: $register, schema: $schema, applied: $applied, skipped: $skipped, stillHeld: $stillHeld);
			}
		} catch (Throwable $e) {
			$failure = $e;
		}

		// Also after a failed chunk: what the earlier chunks wrote is real, so the
		// entry must list it and the cache must not keep serving the old slugs. A run
		// that changed nothing leaves no entry (the console summary still says so).
		if ($applied !== []) {
			$this->recordAudit(applied: $applied, register: $register, schema: $schema, mode: $mode, summary: array_merge($summary, ['skipped' => $skipped]));
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
	 * @param array    $stillHeld slug => uuid of a skipped step that still holds it, by reference.
	 *
	 * @return void
	 *
	 * @throws Throwable When a write fails (the chunk is rolled back first).
	 */
	private function writeChunk(array $chunk, Register $register, Schema $schema, array &$applied, int &$skipped, array &$stillHeld): void {
		$written = [];
		$this->db->beginTransaction();
		try {
			foreach ($chunk as $step) {
				// A step that takes a slug whose holder was not moved would put two
				// objects on it: skip it too, and keep what it would have vacated reserved.
				if ($step['new'] !== null && isset($stillHeld[$step['new']]) === true) {
					$skipped++;
					if ($step['old'] !== null) {
						$stillHeld[$step['old']] = $step['uuid'];
					}

					$this->logger->warning(
						sprintf(
							'[ObjectSlug] Backfill skipped object %s in register %s / schema %s: slug "%s" is still held by object %s, whose write was skipped',
							$step['uuid'],
							(string)$register->getSlug(),
							(string)$schema->getSlug(),
							$step['new'],
							$stillHeld[$step['new']]
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
				if ($step['old'] !== null) {
					$stillHeld[$step['old']] = $step['uuid'];
				}

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
	 * @param string   $mode     `fill` or `reslug`.
	 * @param array    $summary  The run's counters.
	 *
	 * @return void
	 */
	private function recordAudit(array $applied, Register $register, Schema $schema, string $mode, array $summary): void {
		$this->logLargeRun(applied: $applied, register: $register, schema: $schema);
		try {
			$this->auditTrail->createSlugBackfillEntry(
				mode: $mode,
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
					(json_encode($applied, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[unencodable]')
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end recordAudit()

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
			$this->logger->info(
				message: sprintf(
					'[ObjectSlug] Slug backfill changes (register %s, schema %s, part %d of %d): %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					($index + 1),
					count($parts),
					(json_encode($part, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[unencodable]')
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end logLargeRun()

	/**
	 * The audit action suffix of a run.
	 *
	 * @param bool $reslug Whether the run was a `--reslug`.
	 *
	 * @return string `reslug` or `fill`.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Maps the command option to a name.
	 */
	public function modeName(bool $reslug): string {
		if ($reslug === true) {
			return 'reslug';
		}

		return 'fill';
	}//end modeName()
}//end class
