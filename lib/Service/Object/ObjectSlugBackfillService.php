<?php

/**
 * OpenRegister ObjectSlugBackfillService
 *
 * Fills object slugs for existing objects of one register and schema, or
 * recomputes them after the slug field was switched (#4037). Behind
 * `occ openregister:backfill-slugs`.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Plans and applies a slug backfill for one register+schema table.
 *
 * Default mode only touches objects without a slug, oldest first; a stored
 * slug and every existing duplicate stay as they are (BC2). `reslug` recomputes
 * every live object's slug from the current source field; an object whose
 * source is empty keeps its slug, which stays reserved, and so does an object
 * whose new slug another object already holds. Writes go straight to the slug
 * column: no per-object version bump, audit-trail entry or object event (design
 * D11); instead one audit-trail entry per run lists every old to new slug. A
 * run in the same mode a second time changes nothing.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugBackfillService {

	/**
	 * Rows per page when reading the table.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Rows per write transaction, so a large table does not hold its row locks
	 * for the whole write and one failure does not roll back every row.
	 */
	private const WRITE_CHUNK = 500;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper       $magicMapper  Reads and writes the magic table.
	 * @param ObjectSlugHandler $slugHandler  Candidate, suffix and collision rules.
	 * @param CacheHandler      $cacheHandler Object cache, evicted after writing.
	 * @param IDBConnection     $db           Wraps each chunk of a write in a transaction.
	 * @param AuditTrailMapper  $auditTrail   Records the one audit entry of a run.
	 * @param LoggerInterface   $logger       Receives the duplicate warnings.
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly ObjectSlugHandler $slugHandler,
		private readonly CacheHandler $cacheHandler,
		private readonly IDBConnection $db,
		private readonly AuditTrailMapper $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the backfill.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema; must set `objectSlugField`.
	 * @param bool     $reslug   Recompute every slug instead of filling empty ones.
	 * @param bool     $dryRun   Plan only, write nothing.
	 *
	 * @return array{
	 *     duplicates: array<string, list<string>>,
	 *     plan: list<array{uuid: string, old: ?string, new: ?string}>,
	 *     kept: list<array{uuid: string, slug: string, wanted: string, holder: string}>,
	 *     summary: array{scanned: int, assigned: int, changed: int, collisions: int, kept: int, unchanged: int, duplicates: int, skipped: int}
	 * } What was found and what was (or would be) written.
	 *
	 * @throws InvalidArgumentException When the schema has no `objectSlugField`.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `reslug` and `dryRun` are the two documented command options.
	 */
	public function run(Register $register, Schema $schema, bool $reslug=false, bool $dryRun=false): array {
		if ($this->slugHandler->isManaged(schema: $schema) === false) {
			throw new InvalidArgumentException(
				sprintf('Schema %s has no objectSlugField; there is nothing to backfill', (string)($schema->getSlug() ?? $schema->getId()))
			);
		}

		$duplicates = $this->magicMapper->findDuplicateSlugs(register: $register, schema: $schema);
		foreach ($duplicates as $slug => $uuids) {
			$this->logger->warning(
				message: sprintf(
					'[ObjectSlug] Duplicate slug "%s" in register %s (%s) / schema %s (%s) held by objects %s',
					$slug,
					(string)$register->getSlug(),
					(string)$register->getId(),
					(string)$schema->getSlug(),
					(string)$schema->getId(),
					implode(', ', $uuids)
				),
				context: [
					'app' => 'openregister',
					'path' => 'backfill',
					'registerId' => $register->getId(),
					'schemaId' => $schema->getId(),
					'slug' => $slug,
					'uuids' => $uuids,
				]
			);
		}

		$rows = $this->loadRows(register: $register, schema: $schema, onlyEmpty: ($reslug === false));
		$summary = [
			'scanned' => count($rows),
			'assigned' => 0,
			'changed' => 0,
			'collisions' => 0,
			'kept' => 0,
			'unchanged' => 0,
			'duplicates' => count($duplicates),
			'skipped' => 0,
		];

		$kept = [];
		$log = ($dryRun === false);
		$plan = [];
		if ($reslug === false) {
			$plan = $this->planFill(rows: $rows, register: $register, schema: $schema, summary: $summary, log: $log);
		}

		if ($reslug === true) {
			$plan = $this->planReslug(rows: $rows, register: $register, schema: $schema, summary: $summary, log: $log, kept: $kept);
		}

		if ($dryRun === false) {
			$summary['skipped'] = $this->write(plan: $plan, register: $register, schema: $schema, mode: $this->modeName(reslug: $reslug), summary: $summary);
		}

		return ['duplicates' => $duplicates, 'plan' => $plan, 'kept' => $kept, 'summary' => $summary];
	}//end run()

	/**
	 * Read every row the run looks at, oldest first (`_created`, then `_id`).
	 *
	 * The candidate is computed while each page is read and only the small
	 * {id, uuid, slug, created, candidate} tuple is kept, so a large table does
	 * not hold every object body in memory.
	 *
	 * @param Register $register  The register.
	 * @param Schema   $schema    The schema.
	 * @param bool     $onlyEmpty Only rows without a slug.
	 *
	 * @return list<array{id: int, uuid: string, slug: string|null, created: string|null, candidate: string|null}>
	 */
	private function loadRows(Register $register, Schema $schema, bool $onlyEmpty): array {
		$rows = [];
		$afterId = 0;
		do {
			$result = $this->magicMapper->findSlugBackfillPage(
				register: $register,
				schema: $schema,
				afterId: $afterId,
				pageSize: self::PAGE_SIZE,
				onlyEmpty: $onlyEmpty
			);
			foreach ($result['rows'] as $row) {
				$rows[] = [
					'id' => $row['id'],
					'uuid' => $row['uuid'],
					'slug' => $row['slug'],
					'created' => $row['created'] ?? null,
					'candidate' => $this->slugHandler->candidate(objectData: $row['object'], schema: $schema, clientSlug: null),
				];
			}

			// Page on the rows the database returned, not on the convertible ones: a
			// row that could not be converted must not end the scan early. The cursor
			// moves past it too.
			$afterId = $result['lastId'];
		} while ($result['fetched'] === self::PAGE_SIZE);

		// Oldest first as the spec says: `_created`, then `_id`. A row without
		// `_created` sorts after the dated ones, by `_id`.
		usort(
			$rows,
			static fn (array $a, array $b): int => [(int)($a['created'] === null), (string)$a['created'], $a['id']]
				<=> [(int)($b['created'] === null), (string)$b['created'], $b['id']]
		);

		return $rows;
	}//end loadRows()

	/**
	 * Default mode: give objects without a slug their candidate when it is free.
	 *
	 * @param list<array{id: int, uuid: string, slug: string|null, created: string|null, candidate: string|null}> $rows Rows without a slug.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $summary  Counters, by reference.
	 * @param bool     $log      Log collisions (false on a dry run).
	 *
	 * @return list<array{uuid: string, old: ?string, new: ?string}>
	 */
	private function planFill(array $rows, Register $register, Schema $schema, array &$summary, bool $log): array {
		$taken = $this->magicMapper->findAllSlugs(register: $register, schema: $schema);
		$plan = [];
		foreach ($rows as $row) {
			$candidate = $row['candidate'];
			if ($candidate === null) {
				$summary['unchanged']++;
				continue;
			}

			$uuid = $row['uuid'];
			$slug = $this->slugHandler->decide(
				candidate: $candidate,
				register: $register,
				schema: $schema,
				objectUuid: $uuid,
				holderOf: static function (string $wanted) use (&$taken, $uuid): ?string {
					foreach ($taken[$wanted] ?? [] as $holder) {
						if ($holder !== $uuid) {
							return $holder;
						}
					}

					return null;
				},
				path: 'backfill',
				log: $log
			);
			if ($slug === null) {
				$summary['collisions']++;
				continue;
			}

			$taken[$slug][] = $uuid;
			$plan[] = ['uuid' => $uuid, 'old' => null, 'new' => $slug];
			$summary['assigned']++;
		}//end foreach

		return $plan;
	}//end planFill()

	/**
	 * `--reslug`: recompute every live object's slug from the current source field.
	 *
	 * Every stored slug is reserved for its holder until that holder moves to a
	 * new one, so no object is given a slug another object still carries. An
	 * object whose source is empty, and an object whose new slug is held by
	 * another object, keeps its current slug (the latter is reported as kept
	 * because of a collision; with `objectSlugSuffix` it gets the suffixed slug
	 * instead). The rows are walked oldest first, repeatedly, until a pass
	 * moves nothing: an object that has to wait for another one to vacate its
	 * slug is placed in a later pass, so a second run finds nothing to do.
	 *
	 * @param list<array{id: int, uuid: string, slug: string|null, created: string|null, candidate: string|null}> $rows All live rows.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $summary  Counters, by reference.
	 * @param bool     $log      Log collisions (false on a dry run).
	 * @param array    $kept     Objects that keep their slug because of a collision, by reference.
	 *
	 * @return list<array{uuid: string, old: ?string, new: ?string}> Only the rows whose slug changes.
	 */
	private function planReslug(array $rows, Register $register, Schema $schema, array &$summary, bool $log, array &$kept): array {
		$current = [];
		$taken = [];
		foreach ($rows as $row) {
			$current[$row['uuid']] = $row['slug'];
			if ($row['slug'] !== null) {
				$taken[$row['slug']][] = $row['uuid'];
			}
		}

		do {
			$moved = false;
			foreach ($rows as $row) {
				$moved = ($this->moveRow(row: $row, register: $register, schema: $schema, current: $current, taken: $taken) || $moved);
			}
		} while ($moved === true);

		$plan = [];
		foreach ($rows as $row) {
			$uuid = $row['uuid'];
			if ($current[$uuid] !== $row['slug']) {
				$plan[] = ['uuid' => $uuid, 'old' => $row['slug'], 'new' => $current[$uuid]];
				$summary['changed']++;
				continue;
			}

			$summary['unchanged']++;
			$candidate = $row['candidate'];
			if ($candidate === null || $current[$uuid] === $candidate) {
				continue;
			}

			// A suffixed variant from an earlier run is the settled outcome, not a new collision.
			$holder = $this->holderOther(taken: $taken, slug: $candidate, uuid: $uuid);
			if ($holder === null || ($row['slug'] !== null && $this->slugHandler->isSuffixOf(slug: $row['slug'], candidate: $candidate) === true)) {
				continue;
			}

			// Wanted a slug it could not get: it keeps what it has, or stays without.
			$summary['collisions']++;
			if ($row['slug'] !== null) {
				$summary['kept']++;
				$kept[] = ['uuid' => $uuid, 'slug' => $row['slug'], 'wanted' => $candidate, 'holder' => $holder];
			}

			if ($log === true) {
				$this->logUnplaced(row: $row, register: $register, schema: $schema, holder: $holder);
			}
		}//end foreach

		return $plan;
	}//end planReslug()

	/**
	 * Log a row that wanted a slug another object holds.
	 *
	 * @param array    $row      The row (its `candidate` is the wanted slug).
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param string   $holder   The uuid that holds the wanted slug.
	 *
	 * @return void
	 */
	private function logUnplaced(array $row, Register $register, Schema $schema, string $holder): void {
		if ($row['slug'] === null) {
			$this->slugHandler->logCollision(
				register: $register,
				schema: $schema,
				slug: (string)$row['candidate'],
				holderUuid: $holder,
				objectUuid: $row['uuid'],
				suffixed: null,
				path: 'backfill'
			);
			return;
		}

		$this->slugHandler->logKeptSlug(
			register: $register,
			schema: $schema,
			wanted: (string)$row['candidate'],
			kept: $row['slug'],
			holderUuid: $holder,
			objectUuid: $row['uuid']
		);
	}//end logUnplaced()

	/**
	 * One step of the reslug walk: try to give a row the slug it wants.
	 *
	 * @param array    $row      The row.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $current  uuid => slug the object holds right now, by reference.
	 * @param array    $taken    slug => uuids holding it right now, by reference.
	 *
	 * @return bool True when the row moved to another slug.
	 */
	private function moveRow(array $row, Register $register, Schema $schema, array &$current, array &$taken): bool {
		$candidate = $row['candidate'];
		$uuid = $row['uuid'];
		$own = $current[$uuid];
		if ($candidate === null || $own === $candidate) {
			return false;
		}

		$holderOf = fn (string $wanted): ?string => $this->holderOther(taken: $taken, slug: $wanted, uuid: $uuid);

		// Idempotence with the suffix on: an object that already carries a
		// suffixed variant of its taken candidate keeps it, instead of getting
		// a fresh stamp on every run.
		$suffixOn = ((($schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true);
		if ($suffixOn === true
			&& $own !== null
			&& $this->slugHandler->isSuffixOf(slug: $own, candidate: $candidate) === true
			&& $holderOf($candidate) !== null
			&& $holderOf($own) === null
		) {
			return false;
		}

		$slug = $this->slugHandler->decide(
			candidate: $candidate,
			register: $register,
			schema: $schema,
			objectUuid: $uuid,
			holderOf: $holderOf,
			path: 'backfill',
			log: false
		);
		if ($slug === null || $slug === $own) {
			return false;
		}

		if ($own !== null) {
			$taken[$own] = array_values(array_diff($taken[$own], [$uuid]));
		}

		$taken[$slug][] = $uuid;
		$current[$uuid] = $slug;
		return true;
	}//end moveRow()

	/**
	 * The uuid of an object other than `$uuid` that holds `$slug`.
	 *
	 * @param array<string, list<string>> $taken slug => holders.
	 * @param string                      $slug  The slug.
	 * @param string                      $uuid  The asking object.
	 *
	 * @return string|null The other holder, or null when the slug is free for $uuid.
	 */
	private function holderOther(array $taken, string $slug, string $uuid): ?string {
		foreach ($taken[$slug] ?? [] as $holder) {
			if ($holder !== $uuid) {
				return $holder;
			}
		}

		return null;
	}//end holderOther()

	/**
	 * The audit action suffix of a run.
	 *
	 * @param bool $reslug Whether the run was a `--reslug`.
	 *
	 * @return string `reslug` or `fill`.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Maps the command option to a name.
	 */
	private function modeName(bool $reslug): string {
		if ($reslug === true) {
			return 'reslug';
		}

		return 'fill';
	}//end modeName()

	/**
	 * Write a plan in chunked transactions, evict the schema's cached objects and
	 * record the run in the audit trail.
	 *
	 * Each write only applies when the row still holds the slug the plan was
	 * made against; a row a normal save changed meanwhile is skipped and logged.
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
	 * @throws Throwable When a write fails (that chunk is rolled back first).
	 */
	private function write(array $plan, Register $register, Schema $schema, string $mode, array $summary): int {
		$skipped = 0;
		$applied = [];
		try {
			foreach (array_chunk($plan, self::WRITE_CHUNK) as $chunk) {
				$this->writeChunk(chunk: $chunk, register: $register, schema: $schema, applied: $applied, skipped: $skipped);
			}
		} finally {
			// Written even when a chunk failed: the entry must list what changed.
			$this->recordAudit(applied: $applied, register: $register, schema: $schema, mode: $mode, summary: ($summary + ['skipped' => $skipped]));
		}

		if ($plan !== []) {
			$this->cacheHandler->invalidateForObjectChange(
				object: null,
				operation: 'bulk_update',
				registerId: (int)$register->getId(),
				schemaId: (int)$schema->getId()
			);
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
		$written = [];
		$this->db->beginTransaction();
		try {
			foreach ($chunk as $step) {
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
	 * One audit-trail entry for the run, with the old to new list.
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
					json_encode($applied)
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end recordAudit()
}//end class
