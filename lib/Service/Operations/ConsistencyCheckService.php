<?php

/**
 * ConsistencyCheckService: a read that names what is wrong and writes nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * The read-only half of check-then-repair (D-5).
 *
 * Forgejo's doctor separates the check from the fix and the separation is the
 * point: an administrator sees what is wrong before anything changes. That
 * only holds if the check genuinely cannot change anything, and "we were
 * careful" is not a property anything can test. So every probe hands its query
 * back here, and a query that is not a SELECT is refused before it executes.
 * Remove that guard and {@see \Unit\Service\Operations\ConsistencyCheckServiceTest}
 * stops being green.
 *
 * A probe is a name, a sentence and a query returning the rows it objects to.
 * The probes ship as a default list so the service is usable without wiring,
 * and are injectable so a test can hand in one that misbehaves.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
class ConsistencyCheckService {

	/**
	 * How many offending rows one probe reports.
	 *
	 * A register with a hundred thousand orphans is one finding, not a hundred
	 * thousand, and the console has to render it.
	 *
	 * @var integer
	 */
	public const MAX_ROWS_PER_PROBE = 100;

	/**
	 * The probes, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $probes;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection                            $db     The connection every probe reads through.
	 * @param array<string, array<string, mixed>>|null $probes The probes, or null for the shipped set.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		?array $probes = null,
	) {
		$this->probes = ($probes ?? $this->shippedProbes());

	}//end __construct()

	/**
	 * Every probe's findings.
	 *
	 * @return array<string, mixed> The findings, and what was checked.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function check(): array {
		$findings = [];

		foreach ($this->probes as $slug => $probe) {
			$findings[] = $this->run(slug: (string)$slug, probe: $probe);
		}

		return [
			'checked' => count($this->probes),
			'inconsistent' => count(array_filter($findings, static fn (array $f): bool => $f['count'] > 0)),
			'findings' => $findings,
		];

	}//end check()

	/**
	 * One probe's findings.
	 *
	 * @param string $slug The probe slug.
	 *
	 * @return array<string, mixed>|null The finding, or null when no such probe.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function checkOne(string $slug): ?array {
		if (array_key_exists($slug, $this->probes) === false) {
			return null;
		}

		return $this->run(slug: $slug, probe: $this->probes[$slug]);

	}//end checkOne()

	/**
	 * The slugs this instance can check.
	 *
	 * @return array<int, string> The probe slugs.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
	 */
	public function slugs(): array {
		return array_keys($this->probes);

	}//end slugs()

	/**
	 * Run one probe, refusing anything that is not a read.
	 *
	 * @param string               $slug  The probe slug.
	 * @param array<string, mixed> $probe The probe.
	 *
	 * @return array<string, mixed> The finding.
	 *
	 * @throws ConsistencyCheckWouldWriteException When the probe's query would write.
	 */
	private function run(string $slug, array $probe): array {
		$build = $probe['query'];
		$qb = $build($this->db->getQueryBuilder());

		$this->refuseAnythingButARead(slug: $slug, qb: $qb);

		$rows = [];

		try {
			$result = $qb->setMaxResults(self::MAX_ROWS_PER_PROBE)->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (ConsistencyCheckWouldWriteException $refusal) {
			throw $refusal;
		} catch (Throwable $exception) {
			// A probe over a table this instance has not migrated yet is not a
			// finding and not a crash: it is a probe that could not run, and
			// saying so beats reporting zero inconsistencies.
			return [
				'slug' => $slug,
				'title' => $probe['title'],
				'description' => $probe['description'],
				'count' => 0,
				'objects' => [],
				'unavailable' => $exception->getMessage(),
			];
		}

		return [
			'slug' => $slug,
			'title' => $probe['title'],
			'description' => $probe['description'],
			'count' => count($rows),
			'objects' => $rows,
			'unavailable' => null,
		];

	}//end run()

	/**
	 * Refuse a probe whose query is not a SELECT.
	 *
	 * @param string        $slug The probe slug, so the refusal names it.
	 * @param IQueryBuilder $qb   The query the probe built.
	 *
	 * @return void
	 *
	 * @throws ConsistencyCheckWouldWriteException When the query would write.
	 */
	private function refuseAnythingButARead(string $slug, IQueryBuilder $qb): void {
		$sql = ltrim($qb->getSQL());

		if (stripos($sql, 'SELECT') === 0) {
			return;
		}

		throw new ConsistencyCheckWouldWriteException(
			message: 'The consistency probe "'.$slug.'" would write, and the check writes nothing.',
			probe: $slug
		);

	}//end refuseAnythingButARead()

	/**
	 * The probes this instance ships with.
	 *
	 * Each one is an orphan: a row pointing at a record that is gone. They are
	 * the inconsistencies a repair can act on without guessing, which is why
	 * they are the ones the check reports.
	 *
	 * @return array<string, array<string, mixed>> The probes, keyed by slug.
	 */
	private function shippedProbes(): array {
		return [
			'orphan-relations' => [
				'title' => 'Relations pointing at an object that is gone',
				'description' => 'A relation row whose target object no longer exists in the object table.',
				'repair' => 'Delete the relation rows.',
				'table' => 'openregister_object_relations',
				'query' => static function (IQueryBuilder $qb): IQueryBuilder {
					$sub = $qb->getConnection()->getQueryBuilder();
					$sub->select('uuid')->from('openregister_objects');

					return $qb->select('id', 'uuid', 'source_uuid', 'target_uuid')
						->from('openregister_object_relations')
						->where($qb->expr()->isNotNull('target_uuid'))
						->andWhere(
							$qb->expr()->notIn(
								'target_uuid',
								$qb->createFunction($sub->getSQL())
							)
						);
				},
			],
			'orphan-favourites' => [
				'title' => 'Favourites on an object that is gone',
				'description' => 'A favourite row whose object no longer exists in the object table.',
				'repair' => 'Delete the favourite rows.',
				'table' => 'openregister_object_favourites',
				'query' => static function (IQueryBuilder $qb): IQueryBuilder {
					$sub = $qb->getConnection()->getQueryBuilder();
					$sub->select('uuid')->from('openregister_objects');

					return $qb->select('id', 'object_uuid', 'user_id')
						->from('openregister_object_favourites')
						->where($qb->expr()->isNotNull('object_uuid'))
						->andWhere(
							$qb->expr()->notIn(
								'object_uuid',
								$qb->createFunction($sub->getSQL())
							)
						);
				},
			],
			'runs-never-closed' => [
				'title' => 'Job runs that never reported an end',
				'description' => 'A run row still marked running, left behind by a worker that died mid-run.',
				'repair' => 'Mark the runs failed, naming the worker that did not come back.',
				'table' => 'openregister_job_runs',
				'query' => static function (IQueryBuilder $qb): IQueryBuilder {
					return $qb->select('id', 'job_class', 'started')
						->from('openregister_job_runs')
						->where($qb->expr()->eq('outcome', $qb->createNamedParameter('running')))
						->andWhere(
							$qb->expr()->lt(
								'started',
								$qb->createNamedParameter(
									(new \DateTime('-1 day')),
									IQueryBuilder::PARAM_DATETIME_MUTABLE
								)
							)
						);
				},
			],
		];

	}//end shippedProbes()

	/**
	 * What a probe's repair would do, and where.
	 *
	 * @param string $slug The probe slug.
	 *
	 * @return array<string, mixed>|null The repair plan, or null when no such probe.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
	 */
	public function repairPlan(string $slug): ?array {
		if (array_key_exists($slug, $this->probes) === false) {
			return null;
		}

		return [
			'slug' => $slug,
			'table' => $this->probes[$slug]['table'],
			'action' => $this->probes[$slug]['repair'],
		];

	}//end repairPlan()
}//end class
