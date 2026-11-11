<?php

/**
 * OpenRegister ObjectSourceWindow
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
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The window one object-source read asked for, and the page shape of its answer.
 *
 * Mirrors the `{ results, total, '@self' }` shape the index and database search
 * backends return, so virtual objects render exactly like native ones.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class ObjectSourceWindow {

	/**
	 * The requested limit, 0 for none.
	 *
	 * @var int
	 */
	public readonly int $limit;

	/**
	 * The requested offset.
	 *
	 * @var int
	 */
	public readonly int $offset;

	/**
	 * Read the window of a query in the provider contract shape.
	 *
	 * @param array<string, mixed> $query The query.
	 */
	public function __construct(array $query) {
		$this->limit  = (int) ($query['limit'] ?? 0);
		$this->offset = max(0, (int) ($query['offset'] ?? 0));
	}//end __construct()

	/**
	 * The paginated result for the rows of this window.
	 *
	 * @param array<mixed> $results The rows.
	 * @param int|null     $counted The provider's count, null when it has none.
	 *
	 * @return array{results: array, total: int, '@self': array} The paginated result.
	 */
	public function page(array $results, ?int $counted): array {
		$trueTotal = $this->trustedCount(counted: $counted, rows: count($results));
		$total     = ($trueTotal ?? count($results));
		return [
			'results' => $results,
			'total'   => $total,
			'@self'   => $this->metadata(total: $total, realCount: ($trueTotal !== null)),
		];
	}//end page()

	/**
	 * The count, unless it is inconsistent with the returned rows.
	 *
	 * A count below offset + rows comes from a provider without real count
	 * support; such a provider keeps its single-page behaviour.
	 *
	 * @param int|null $counted The provider's count.
	 * @param int      $rows    The number of rows returned.
	 *
	 * @return int|null The true total, or null for the in-memory fallback.
	 */
	private function trustedCount(?int $counted, int $rows): ?int {
		if ($counted !== null && $counted >= ($this->offset + $rows)) {
			return $counted;
		}

		return null;
	}//end trustedCount()

	/**
	 * The `@self` pagination block.
	 *
	 * @param int  $total     The true or fallback total.
	 * @param bool $realCount Whether the total came from a real provider count.
	 *
	 * @return array<string, int|null> The pagination metadata.
	 */
	private function metadata(int $total, bool $realCount): array {
		[$page, $pages] = $this->position(total: $total, realCount: $realCount);
		return [
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
			'limit' => $this->effectiveLimit(total: $total),
			'next'  => $this->nextPage(page: $page, pages: $pages),
			'prev'  => $this->previousPage(page: $page),
		];
	}//end metadata()

	/**
	 * The current page and the number of pages; one of one without a real count or a limit.
	 *
	 * @param int  $total     The total.
	 * @param bool $realCount Whether the total came from a real provider count.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function position(int $total, bool $realCount): array {
		if ($realCount === false || $this->limit <= 0) {
			return [1, 1];
		}

		return [((int) floor($this->offset / $this->limit) + 1), max(1, (int) ceil($total / $this->limit))];
	}//end position()

	/**
	 * The limit to report: the requested one, or the total when none was requested.
	 *
	 * @param int $total The total.
	 *
	 * @return int
	 */
	private function effectiveLimit(int $total): int {
		if ($this->limit > 0) {
			return $this->limit;
		}

		return $total;
	}//end effectiveLimit()

	/**
	 * The page after this one, when there is one.
	 *
	 * @param int $page  The current page.
	 * @param int $pages The number of pages.
	 *
	 * @return int|null
	 */
	private function nextPage(int $page, int $pages): ?int {
		if ($page < $pages) {
			return ($page + 1);
		}

		return null;
	}//end nextPage()

	/**
	 * The page before this one, when there is one.
	 *
	 * @param int $page The current page.
	 *
	 * @return int|null
	 */
	private function previousPage(int $page): ?int {
		if ($page > 1) {
			return ($page - 1);
		}

		return null;
	}//end previousPage()
}//end class
