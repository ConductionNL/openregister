<?php

/**
 * OpenRegister schema-application migrator
 *
 * Re-points registers and schemas from one owning application id to another
 * when a fleet app's `<id>` changes.
 *
 * WHY THIS EXISTS. ImportHandler resolves an app's register and its schemas by
 * different keys: a register is matched by SLUG alone and then has
 * setApplication() applied, so it follows a rename by itself, while a schema is
 * matched by findByApplicationAndSlug() — the PAIR, on lower(slug). When the
 * app id changes, every schema lookup therefore misses, and the import neither
 * fails nor warns: it takes the "not found, will create new one" branch and
 * builds a second, EMPTY set of schemas under the new id while the originals
 * and their objects stay behind under the old one.
 *
 * This is a service rather than logic inside the command so that the console
 * entry point and an app's own repair step run the SAME code. Two copies of a
 * migration rule drift, and the second one to drift is the one nobody runs
 * interactively.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;

/**
 * Move registers and schemas from one owning application id to another.
 */
class SchemaApplicationMigrator {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(private readonly IDBConnection $db) {

	}//end __construct()


	/**
	 * Which slugs appear under BOTH application ids.
	 *
	 * Pure and static so the refusal rule can be tested without a database —
	 * it is the part that must not be wrong. Matching is case-insensitive
	 * because that is how findByApplicationAndSlug() looks schemas up
	 * (`lower(slug)`); comparing case-sensitively here would report "no
	 * collision" for a pair the importer nevertheless treats as one schema,
	 * skipping the refusal precisely when it was needed.
	 *
	 * @param string[] $fromSlugs Slugs owned by the current application id.
	 * @param string[] $toSlugs   Slugs owned by the new application id.
	 *
	 * @return string[] The colliding slugs, lower-cased and unique.
	 */
	public static function planCollisions(array $fromSlugs, array $toSlugs): array {
		$target = [];
		foreach ($toSlugs as $slug) {
			$target[strtolower((string)$slug)] = true;
		}

		if (empty($target) === true) {
			return [];
		}

		$hits = [];
		foreach ($fromSlugs as $slug) {
			$lower = strtolower((string)$slug);
			if (isset($target[$lower]) === true) {
				$hits[$lower] = true;
			}
		}

		return array_keys($hits);

	}//end planCollisions()


	/**
	 * Count rows owned by an application id.
	 *
	 * @param string $table       The table to count in.
	 * @param string $application The owning application id.
	 *
	 * @return int The row count.
	 */
	public function countFor(string $table, string $application): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($table)
			->where($qb->expr()->eq('application', $qb->createNamedParameter($application)));

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();

		return (int)($row['c'] ?? 0);

	}//end countFor()


	/**
	 * Read every schema slug owned by an application id.
	 *
	 * @param string $application The owning application id.
	 *
	 * @return string[] The slugs.
	 */
	public function slugsFor(string $application): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('slug')
			->from('openregister_schemas')
			->where($qb->expr()->eq('application', $qb->createNamedParameter($application)));

		$result = $qb->executeQuery();
		$slugs  = [];
		while (($row = $result->fetch()) !== false) {
			$slugs[] = (string)$row['slug'];
		}

		$result->closeCursor();

		return $slugs;

	}//end slugsFor()


	/**
	 * Find slugs that exist under BOTH application ids.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @return string[] The colliding slugs.
	 */
	public function collidingSlugs(string $from, string $to): array {
		return self::planCollisions(
			fromSlugs: $this->slugsFor(application: $from),
			toSlugs: $this->slugsFor(application: $to)
		);

	}//end collidingSlugs()


	/**
	 * Re-point every row owned by `from` at `to`.
	 *
	 * @param string $table The table to update.
	 * @param string $from  The current application id.
	 * @param string $to    The new application id.
	 *
	 * @return int The number of rows updated.
	 */
	public function moveApplication(string $table, string $from, string $to): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('application', $qb->createNamedParameter($to))
			->where($qb->expr()->eq('application', $qb->createNamedParameter($from)));

		return (int)$qb->executeStatement();

	}//end moveApplication()


	/**
	 * Perform the migration, refusing when it would create duplicates.
	 *
	 * Idempotent: a second run finds nothing under the old id and reports
	 * `moved` counts of zero rather than failing.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @return array{ok: bool, reason: string, collisions: string[], schemas: int, registers: int}
	 *     The outcome. `ok` false with a non-empty `collisions` means an import
	 *     already forked those schemas and the caller must resolve them first.
	 */
	public function migrate(string $from, string $to): array {
		$result = [
			'ok'         => false,
			'reason'     => '',
			'collisions' => [],
			'schemas'    => 0,
			'registers'  => 0,
		];

		if ($from === '' || $to === '' || $from === $to) {
			$result['reason'] = 'invalid-arguments';
			return $result;
		}

		$collisions = $this->collidingSlugs(from: $from, to: $to);
		if (empty($collisions) === false) {
			$result['reason']     = 'collisions';
			$result['collisions'] = $collisions;
			return $result;
		}

		$result['schemas']   = $this->moveApplication(table: 'openregister_schemas', from: $from, to: $to);
		$result['registers'] = $this->moveApplication(table: 'openregister_registers', from: $from, to: $to);
		$result['ok']        = true;
		$result['reason']    = 'migrated';

		return $result;

	}//end migrate()


}//end class
