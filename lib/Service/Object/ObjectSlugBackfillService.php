<?php

/**
 * OpenRegister ObjectSlugBackfillService
 *
 * Fills object slugs for the existing objects of one register and schema
 * that have none yet (#4037). Behind `occ openregister:backfill-slugs`.
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

/**
 * Plans and applies a slug backfill for one register+schema table.
 *
 * The run only touches objects without a slug, oldest first; a stored slug
 * and every existing duplicate stay as they are (BC2). A set slug never
 * changes, also not after `objectSlugField` was switched: the backfill has no
 * rewrite mode (Robert Zondervan, 2026-10-23). Writes go straight to the slug
 * column: no per-object version bump, audit-trail entry or object event
 * (design D11); instead a run that changes at least one slug writes one
 * audit-trail entry with the old to new slugs. A second run changes nothing.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugBackfillService {

	/**
	 * Rows per page when reading the table.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Writes a plan, evicts the cache and records the audit entry.
	 *
	 * @var ObjectSlugBackfillWriter
	 */
	private ObjectSlugBackfillWriter $writer;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper       $magicMapper  Reads and writes the magic table.
	 * @param ObjectSlugHandler $slugHandler  Candidate, suffix and collision rules.
	 * @param CacheHandler      $cacheHandler Object cache, evicted after writing.
	 * @param IDBConnection     $db           Wraps each chunk of a write in a transaction.
	 * @param AuditTrailMapper  $auditTrail   Records the audit entry of a run that changed slugs.
	 * @param LoggerInterface   $logger       Receives the duplicate warnings.
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly ObjectSlugHandler $slugHandler,
		CacheHandler $cacheHandler,
		IDBConnection $db,
		AuditTrailMapper $auditTrail,
		private readonly LoggerInterface $logger,
	) {
		$this->writer = new ObjectSlugBackfillWriter(
			magicMapper: $magicMapper,
			cacheHandler: $cacheHandler,
			db: $db,
			auditTrail: $auditTrail,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Run the backfill.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema; must set `objectSlugField`.
	 * @param bool     $dryRun   Plan only, write nothing.
	 *
	 * @return array{
	 *     duplicates: array<string, list<string>>,
	 *     plan: list<array{uuid: string, old: ?string, new: ?string}>,
	 *     summary: array{scanned: int, assigned: int, collisions: int, unchanged: int, duplicates: int, skipped: int}
	 * } What was found and what was (or would be) written.
	 *
	 * @throws InvalidArgumentException When the schema has no `objectSlugField`.
	 * @throws \OCA\OpenRegister\Exception\SlugBackfillWriteException When a write chunk fails (earlier chunks stay written).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `dryRun` is the documented command option.
	 */
	public function run(Register $register, Schema $schema, bool $dryRun=false): array {
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

		$rows = $this->loadRows(register: $register, schema: $schema);
		$summary = [
			'scanned' => count($rows),
			'assigned' => 0,
			'collisions' => 0,
			'unchanged' => 0,
			'duplicates' => count($duplicates),
			'skipped' => 0,
		];

		$plan = $this->planFill(rows: $rows, register: $register, schema: $schema, summary: $summary, log: ($dryRun === false));

		if ($dryRun === false) {
			$summary['skipped'] = $this->writer->write(plan: $plan, register: $register, schema: $schema, summary: $summary);
		}

		return ['duplicates' => $duplicates, 'plan' => $plan, 'summary' => $summary];
	}//end run()

	/**
	 * Read every live row without a slug, oldest first (`_created`, then `_id`).
	 *
	 * The candidate is computed while each page is read and only the small
	 * {id, uuid, slug, created, candidate} tuple is kept, so a large table does
	 * not hold every object body in memory.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return list<array{id: int, uuid: string, slug: string|null, created: string|null, candidate: string|null}>
	 */
	private function loadRows(Register $register, Schema $schema): array {
		$rows = [];
		$afterId = 0;
		do {
			$result = $this->magicMapper->findSlugBackfillPage(
				register: $register,
				schema: $schema,
				afterId: $afterId,
				pageSize: self::PAGE_SIZE
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
	 * Give objects without a slug their candidate when it is free.
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
		// Keyed case-folded, as bulk save does: on a case-insensitive collation
		// `Hello-World` and `hello-world` are one slug (ObjectSlugHandler::foldKey()).
		$taken = [];
		foreach ($this->magicMapper->findAllSlugs(register: $register, schema: $schema) as $held => $holders) {
			$key = $this->slugHandler->foldKey(slug: (string)$held);
			$taken[$key] = array_merge($taken[$key] ?? [], $holders);
		}

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
				holderOf: function (string $wanted) use (&$taken, $uuid): ?string {
					foreach ($taken[$this->slugHandler->foldKey(slug: $wanted)] ?? [] as $holder) {
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

			$taken[$this->slugHandler->foldKey(slug: $slug)][] = $uuid;
			$plan[] = ['uuid' => $uuid, 'old' => null, 'new' => $slug];
			$summary['assigned']++;
		}//end foreach

		return $plan;
	}//end planFill()
}//end class
