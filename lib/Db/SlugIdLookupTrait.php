<?php

/**
 * OpenRegister SlugIdLookupTrait
 *
 * Id and slug lookups for mappers whose table has `id` and `slug` columns.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Id and slug lookups over the using mapper's table.
 *
 * The using class provides `$this->db` and `getTableName()` (as QBMapper does).
 */
trait SlugIdLookupTrait {

	/**
	 * Get all id to slug mappings
	 *
	 * @return array<string,string> Array mapping ids to their slugs
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function getIdToSlugMap(): array {
		$mappings = [];
		foreach ($this->fetchIdSlugRows() as $row) {
			$mappings[$row['id']] = $row['slug'];
		}

		return $mappings;
	}//end getIdToSlugMap()

	/**
	 * Get all slug to id mappings
	 *
	 * @return array<string,string> Array mapping slugs to their ids
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function getSlugToIdMap(): array {
		$mappings = [];
		foreach ($this->fetchIdSlugRows() as $row) {
			$mappings[$row['slug']] = $row['id'];
		}

		return $mappings;
	}//end getSlugToIdMap()

	/**
	 * Resolve a bounded set of slugs to their primary-key ids.
	 *
	 * Deliberately NOT `getSlugToIdMap()`: that materialises every row of the
	 * table to answer a question about a handful of slugs. Filtered event
	 * subscription runs this on the object write path, so it must be bounded by
	 * the caller's declaration rather than by the size of the instance.
	 *
	 * A slug is not unique across apps, so each slug maps to a LIST of ids and
	 * the caller narrows further. Matching is case-insensitive; keys come back
	 * lower-cased.
	 *
	 * @param array<int,string> $slugs Slugs to resolve.
	 *
	 * @return array<string,array<int,string>> Lower-cased slug => matching ids.
	 *                                         Empty when $slugs is empty.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function findIdsBySlugs(array $slugs): array {
		if ($slugs === []) {
			return [];
		}

		$lowered = array_values(array_unique(array_map('strtolower', $slugs)));

		$result = $this->selectIdsBySlug(lowered: $lowered)->executeQuery();
		$map = array_fill_keys($lowered, []);
		while (($row = $result->fetch()) !== false) {
			$map[strtolower((string)$row['slug'])][] = (string)$row['id'];
		}

		$result->closeCursor();

		return $map;
	}//end findIdsBySlugs()

	/**
	 * The ids and slugs of the rows whose slug matches one of some lower-cased slugs, case-insensitively.
	 *
	 * @param array<int, string> $lowered The lower-cased slugs.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function selectIdsBySlug(array $lowered): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
			->from($this->getTableName())
			->where(
				$qb->expr()->in(
					$qb->func()->lower('slug'),
					$qb->createNamedParameter(value: $lowered, type: IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		return $qb;
	}//end selectIdsBySlug()

	/**
	 * Fetch the id and slug of every row in the table.
	 *
	 * @return iterable<array<string,mixed>> The rows.
	 */
	private function fetchIdSlugRows(): iterable {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
			->from($this->getTableName());

		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			yield $row;
		}
	}//end fetchIdSlugRows()
}//end trait
