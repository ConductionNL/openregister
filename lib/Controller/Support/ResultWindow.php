<?php

/**
 * OpenRegister ResultWindow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


/**
 * The total of a listing and the position a caller asked for, as a paging envelope.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ResultWindow {
	/**
	 * Hold the total and the position.
	 *
	 * @param int|null $total  The total number of items, before paging.
	 * @param int|null $limit  The number of items per page.
	 * @param int|null $offset The offset of the first item.
	 * @param int|null $page   The page number.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		private readonly ?int $total = 0,
		private readonly ?int $limit = 20,
		private readonly ?int $offset = 0,
		private readonly ?int $page = 1,
	) {
	}//end __construct()

	/**
	 * The envelope around these results.
	 *
	 * A total below the number of results is raised to it, for a count that
	 * does not know the real total.
	 *
	 * @param array<int, mixed> $results The results of this page.
	 *
	 * @return array<string, mixed> The results with total, page, pages, limit and offset.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function over(array $results): array {
		$limit = max(1, ($this->limit ?? 20));
		$total = max(0, ($this->total ?? 0), count($results));
		$position = $this->position(limit: $limit);

		return [
			'results' => $results,
			'total' => $total,
			'page' => $position['page'],
			'pages' => max(1, ceil($total / $limit)),
			'limit' => $limit,
			'offset' => $position['offset'],
		];
	}//end over()

	/**
	 * The offset and the page, each derived from the other when it was not given.
	 *
	 * @param int $limit The items per page.
	 *
	 * @return array{page: float|int, offset: int} The position.
	 */
	private function position(int $limit): array {
		$offset = max(0, ($this->offset ?? 0));
		$page = max(1, ($this->page ?? 1));
		if ($offset === 0) {
			$offset = (($page - 1) * $limit);
		}

		if ($page === 1 && $offset > 0) {
			$page = (floor($offset / $limit) + 1);
		}

		return ['page' => $page, 'offset' => $offset];
	}//end position()
}//end class
