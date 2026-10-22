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
 * source is empty keeps its slug, which stays reserved. Writes go straight to
 * the slug column: no version bump, audit-trail entry or object event (design
 * D11). A run in the same mode a second time changes nothing.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugBackfillService {

	/**
	 * Rows per page when reading the table.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper       $magicMapper  Reads and writes the magic table.
	 * @param ObjectSlugHandler $slugHandler  Candidate, suffix and collision rules.
	 * @param CacheHandler      $cacheHandler Object cache, evicted after writing.
	 * @param IDBConnection     $db           Wraps a `reslug` write in one transaction.
	 * @param LoggerInterface   $logger       Receives the duplicate warnings.
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly ObjectSlugHandler $slugHandler,
		private readonly CacheHandler $cacheHandler,
		private readonly IDBConnection $db,
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
	 *     summary: array{scanned: int, assigned: int, changed: int, collisions: int, unchanged: int, duplicates: int, skipped: int}
	 * } What was found and what was (or would be) written.
	 *
	 * @throws InvalidArgumentException When the schema has no `objectSlugField`.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
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
			'unchanged' => 0,
			'duplicates' => count($duplicates),
			'skipped' => 0,
		];

		if ($reslug === true) {
			$plan = $this->planReslug(rows: $rows, register: $register, schema: $schema, summary: $summary, log: ($dryRun === false));
		} else {
			$plan = $this->planFill(rows: $rows, register: $register, schema: $schema, summary: $summary, log: ($dryRun === false));
		}

		if ($dryRun === false && $plan !== []) {
			$summary['skipped'] = $this->write(plan: $plan, register: $register, schema: $schema);
		}

		return ['duplicates' => $duplicates, 'plan' => $plan, 'summary' => $summary];
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
	 * An object whose source is empty keeps its slug, and that slug stays
	 * reserved. The others are decided oldest first; a later object that wants
	 * a slug already given out gets the suffix or no slug (logged).
	 *
	 * @param list<array{id: int, uuid: string, slug: string|null, created: string|null, candidate: string|null}> $rows All live rows.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param array    $summary  Counters, by reference.
	 * @param bool     $log      Log collisions (false on a dry run).
	 *
	 * @return list<array{uuid: string, old: ?string, new: ?string}> Only the rows whose slug changes.
	 */
	private function planReslug(array $rows, Register $register, Schema $schema, array &$summary, bool $log): array {
		$suffixOn = ((($schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true);
		$candidates = [];
		$taken = [];
		foreach ($rows as $index => $row) {
			$candidates[$index] = $row['candidate'];
			if ($candidates[$index] === null && $row['slug'] !== null) {
				$taken[$row['slug']][] = $row['uuid'];
			}
		}

		$plan = [];
		foreach ($rows as $index => $row) {
			$candidate = $candidates[$index];
			if ($candidate === null) {
				$summary['unchanged']++;
				continue;
			}

			$uuid = $row['uuid'];
			$holderOf = static function (string $wanted) use (&$taken, $uuid): ?string {
				foreach ($taken[$wanted] ?? [] as $holder) {
					if ($holder !== $uuid) {
						return $holder;
					}
				}

				return null;
			};

			// Idempotence with the suffix on: an object that already carries a
			// suffixed variant of its taken candidate keeps it, instead of getting
			// a fresh stamp on every run.
			$keepSuffixed = $suffixOn === true
				&& $row['slug'] !== null
				&& $this->slugHandler->isSuffixOf(slug: $row['slug'], candidate: $candidate) === true
				&& $holderOf($candidate) !== null
				&& $holderOf($row['slug']) === null;
			if ($keepSuffixed === true) {
				$slug = $row['slug'];
			} else {
				$slug = $this->slugHandler->decide(
					candidate: $candidate,
					register: $register,
					schema: $schema,
					objectUuid: $uuid,
					holderOf: $holderOf,
					path: 'backfill',
					log: $log
				);
			}

			if ($slug === null) {
				$summary['collisions']++;
			} else {
				$taken[$slug][] = $uuid;
			}

			if ($slug === $row['slug']) {
				$summary['unchanged']++;
				continue;
			}

			$plan[] = ['uuid' => $uuid, 'old' => $row['slug'], 'new' => $slug];
			$summary['changed']++;
		}//end foreach

		return $plan;
	}//end planReslug()

	/**
	 * Write a plan in one transaction and evict the schema's cached objects.
	 *
	 * Each write only applies when the row still holds the slug the plan was
	 * made against; a row a normal save changed meanwhile is skipped and logged.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The planned writes.
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return int The number of planned writes skipped because the row changed.
	 *
	 * @throws Throwable When a write fails (the transaction is rolled back first).
	 */
	private function write(array $plan, Register $register, Schema $schema): int {
		$skipped = 0;
		$this->db->beginTransaction();
		try {
			foreach ($plan as $step) {
				$updated = $this->magicMapper->updateSlugColumn(
					register: $register,
					schema: $schema,
					uuid: $step['uuid'],
					slug: $step['new'],
					expected: $step['old']
				);
				if ($updated === 0) {
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
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->cacheHandler->invalidateForObjectChange(
			object: null,
			operation: 'bulk_update',
			registerId: (int)$register->getId(),
			schemaId: (int)$schema->getId()
		);

		return $skipped;
	}//end write()
}//end class
