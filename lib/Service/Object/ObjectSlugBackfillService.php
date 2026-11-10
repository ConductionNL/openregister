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
use OCA\OpenRegister\Db\MagicMapper\MagicSlugStoreInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugDecision;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWant;
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
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugBackfillService implements ObjectSlugBackfillServiceInterface {

	/**
	 * Rows per page when reading the table.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param MagicSlugStoreInterface              $magicMapper Reads the magic table.
	 * @param ObjectSlugHandler        $slugHandler Candidate, suffix and collision rules.
	 * @param ObjectSlugBackfillWriter $writer      Writes a plan, evicts the cache and records the audit entry.
	 * @param LoggerInterface          $logger      Receives the duplicate warnings.
	 */
	public function __construct(
		private readonly MagicSlugStoreInterface $magicMapper,
		private readonly ObjectSlugHandler $slugHandler,
		private readonly ObjectSlugBackfillWriter $writer,
		private readonly LoggerInterface $logger,
	) {
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `dryRun` is the documented command option.
	 */
	public function run(Register $register, Schema $schema, bool $dryRun=false): array {
		return $this->runRequest(request: new ObjectSlugBackfillRequest(register: $register, schema: $schema, dryRun: $dryRun));
	}//end run()

	/**
	 * Check the schema, report the duplicates, plan the fill and write it unless dry.
	 *
	 * @param ObjectSlugBackfillRequest $request The run's register, schema and mode.
	 *
	 * @return array{duplicates: array<string, list<string>>, plan: list<array>, summary: array<string, int>} As run().
	 *
	 * @throws InvalidArgumentException When the schema has no `objectSlugField`.
	 * @throws \OCA\OpenRegister\Exception\SlugBackfillWriteException When a write chunk fails.
	 */
	private function runRequest(ObjectSlugBackfillRequest $request): array {
		$register = $request->register;
		$schema = $request->schema;
		if ($this->slugHandler->isManaged(schema: $schema) === false) {
			throw new InvalidArgumentException(
				sprintf('Schema %s has no objectSlugField; there is nothing to backfill', (string)($schema->getSlug() ?? $schema->getId()))
			);
		}

		$duplicates = $this->magicMapper->findDuplicateSlugs(register: $register, schema: $schema);
		$this->logDuplicates(request: $request, duplicates: $duplicates);

		$rows = $this->loadRows(register: $register, schema: $schema);
		$fill = $this->planFill(rows: $rows, request: $request);
		$plan = $fill['plan'];
		$summary = [
			'scanned' => count($rows),
			'assigned' => $fill['assigned'],
			'collisions' => $fill['collisions'],
			'unchanged' => $fill['unchanged'],
			'duplicates' => count($duplicates),
			'skipped' => 0,
		];

		if ($request->dryRun === false) {
			$planned = new ObjectSlugChangeSet(register: $register, schema: $schema, changes: $plan, summary: $summary);
			$summary['skipped'] = $this->writer->write(planned: $planned);
		}

		return ['duplicates' => $duplicates, 'plan' => $plan, 'summary' => $summary];
	}//end runRequest()

	/**
	 * Warn about every slug more than one object holds already; they stay as they are.
	 *
	 * @param ObjectSlugBackfillRequest   $request    The run's register and schema.
	 * @param array<string, list<string>> $duplicates Slug => holder uuids.
	 *
	 * @return void
	 */
	private function logDuplicates(ObjectSlugBackfillRequest $request, array $duplicates): void {
		$register = $request->register;
		$schema = $request->schema;
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
	}//end logDuplicates()

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
					'candidate' => $this->slugHandler->candidate(objectData: $row['object'], schema: $schema),
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
	 * The slugs the table holds now, with their holders.
	 *
	 * Keyed case-folded, as bulk save does: on a case-insensitive collation
	 * `Hello-World` and `hello-world` are one slug (ObjectSlugHandler::foldKey()).
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, list<string>> Folded slug => holder uuids.
	 */
	private function takenSlugs(Register $register, Schema $schema): array {
		$taken = [];
		foreach ($this->magicMapper->findAllSlugs(register: $register, schema: $schema) as $held => $holders) {
			$key = $this->slugHandler->foldKey(slug: (string)$held);
			$taken[$key] = array_merge($taken[$key] ?? [], $holders);
		}

		return $taken;
	}//end takenSlugs()

	/**
	 * Give objects without a slug their candidate when it is free.
	 *
	 * Collisions are logged unless the run is a dry run.
	 *
	 * @param list<array{id: int, uuid: string, slug: string|null, created: string|null, candidate: string|null}> $rows Rows without a slug.
	 * @param ObjectSlugBackfillRequest $request The run's register, schema and mode.
	 *
	 * @return array{plan: list<array{uuid: string, old: ?string, new: ?string}>, assigned: int, collisions: int, unchanged: int}
	 */
	private function planFill(array $rows, ObjectSlugBackfillRequest $request): array {
		$scope = $request->scope();
		$taken = $this->takenSlugs(register: $request->register, schema: $request->schema);
		$fill = ['plan' => [], 'assigned' => 0, 'collisions' => 0, 'unchanged' => 0];
		foreach ($rows as $row) {
			$candidate = $row['candidate'];
			if ($candidate === null) {
				$fill['unchanged']++;
				continue;
			}

			$uuid = $row['uuid'];
			$slug = $this->slugHandler->decide(
				decision: new ObjectSlugDecision(
					want: new ObjectSlugWant(slug: $candidate, scope: $scope, objectUuid: $uuid, path: 'backfill'),
					holderOf: $this->holderOf(taken: $taken, uuid: $uuid),
					log: ($request->dryRun === false)
				)
			);
			if ($slug === null) {
				$fill['collisions']++;
				continue;
			}

			$taken[$this->slugHandler->foldKey(slug: $slug)][] = $uuid;
			$fill['plan'][] = ['uuid' => $uuid, 'old' => null, 'new' => $slug];
			$fill['assigned']++;
		}//end foreach

		return $fill;
	}//end planFill()

	/**
	 * The holder lookup for one object: the first other object holding a folded slug.
	 *
	 * Reads the run's taken map by reference, so slugs planned earlier in the run count.
	 *
	 * @param array  $taken Holder uuids per folded slug (by reference).
	 * @param mixed  $uuid  The object the slug is decided for.
	 *
	 * @return \Closure(string): ?string The lookup
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	private function holderOf(array &$taken, mixed $uuid): \Closure {
		return function (string $wanted) use (&$taken, $uuid): ?string {
			foreach ($taken[$this->slugHandler->foldKey(slug: $wanted)] ?? [] as $holder) {
				if ($holder !== $uuid) {
					return $holder;
				}
			}

			return null;
		};
	}//end holderOf()
}//end class
