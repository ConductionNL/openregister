<?php

/**
 * OpenRegister ObjectSlugBackfillWriter
 *
 * Writes a slug backfill plan in chunked transactions and hands what was
 * written to the ledger (#4037).
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SlugBackfillWriteException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The write side of the slug backfill.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
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
	 * @param MagicMapperInterface              $magicMapper Writes the slug column.
	 * @param IDBConnection            $db          Wraps each chunk in a transaction.
	 * @param ObjectSlugBackfillLedger $ledger      Audits the run and evicts the cache.
	 * @param LoggerInterface          $logger      Receives the skipped-row warnings.
	 */
	public function __construct(
		private readonly MagicMapperInterface $magicMapper,
		private readonly IDBConnection $db,
		private readonly ObjectSlugBackfillLedger $ledger,
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function write(array $plan, Register $register, Schema $schema, array $summary): int {
		$tally = ['applied' => [], 'skipped' => 0];
		$failure = null;
		try {
			foreach (array_chunk($plan, self::WRITE_CHUNK) as $chunk) {
				$this->writeChunk(chunk: $chunk, register: $register, schema: $schema, tally: $tally);
			}
		} catch (Throwable $e) {
			$failure = $e;
		}

		['applied' => $applied, 'skipped' => $skipped] = $tally;

		// Also after a failed chunk: what the earlier chunks wrote is real, so the
		// entry must list it and the cache must not keep serving the old slugs. A run
		// that changed nothing leaves no entry (the console summary still says so).
		if ($applied !== []) {
			$this->ledger->record(applied: $applied, register: $register, schema: $schema, summary: array_merge($summary, ['skipped' => $skipped]));
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
	 * @param Register                                  $register The register.
	 * @param Schema                                    $schema   The schema.
	 * @param array{applied: list<array>, skipped: int} $tally    Steps written, and steps skipped because
	 *                                                            the row changed, by reference.
	 *
	 * @return void
	 *
	 * @throws Throwable When a write fails (the chunk is rolled back first).
	 */
	private function writeChunk(array $chunk, Register $register, Schema $schema, array &$tally): void {
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
					$tally['skipped']++;
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
					slug: (string)$step['new']
				);
				if ($updated > 0) {
					$written[] = $step;
					continue;
				}

				$tally['skipped']++;
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

		array_push($tally['applied'], ...$written);
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
}//end class
