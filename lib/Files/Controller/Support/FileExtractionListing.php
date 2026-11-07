<?php

/**
 * OpenRegister file extraction listing
 *
 * The list parameters of the file extraction overview, and the paging and
 * sorting that follow from them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Support;

use OCP\IRequest;

/**
 * The list parameters of one file extraction overview request.
 *
 * Sorting on riskLevel or entityCount happens in PHP, on all rows. A restricted
 * (non-admin) caller sees only the files they can open, so their page is cut in
 * PHP too. Either way the database query then returns every summary.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class FileExtractionListing {

	private const RISK_ORDER = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'very_high' => 4];

	/**
	 * Page size.
	 *
	 * @var int
	 */
	public readonly int $limit;

	/**
	 * Page offset.
	 *
	 * @var int
	 */
	public readonly int $offset;

	/**
	 * Search term, null when absent or empty.
	 *
	 * @var string|null
	 */
	public readonly ?string $searchTerm;

	/**
	 * Requested extraction status.
	 *
	 * @var mixed
	 */
	public readonly mixed $status;

	/**
	 * Requested risk level.
	 *
	 * @var mixed
	 */
	public readonly mixed $riskLevel;

	/**
	 * Requested sort field.
	 *
	 * @var mixed
	 */
	public readonly mixed $sort;

	/**
	 * Requested sort order (ASC or DESC).
	 *
	 * @var mixed
	 */
	public readonly mixed $order;

	/**
	 * Whether the caller sees only the files they can open.
	 *
	 * @var bool
	 */
	private bool $restricted = false;

	/**
	 * Read the list parameters from the request.
	 *
	 * @param IRequest $request The request.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function __construct(IRequest $request) {
		$this->limit = (int)($request->getParam('limit', 50));
		$this->offset = (int)($request->getParam('offset', 0));
		$search = $request->getParam('search');
		$this->status = $request->getParam('status');
		$this->riskLevel = $request->getParam('riskLevel');
		$this->sort = $request->getParam('sort', 'extractedAt');
		$this->order = $request->getParam('order', 'DESC');
		$searchTerm = null;
		if ($search !== null && $search !== '') {
			$searchTerm = $search;
		}

		$this->searchTerm = $searchTerm;
	}//end __construct()

	/**
	 * Mark the caller as restricted to the files they can open.
	 *
	 * @param bool $restricted Whether the caller is restricted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function restrict(bool $restricted): void
	{
		$this->restricted = $restricted;
	}//end restrict()

	/**
	 * Whether the caller is restricted to the files they can open.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function isRestricted(): bool
	{
		return $this->restricted;
	}//end isRestricted()

	/**
	 * Whether the rows are sorted in PHP (riskLevel, entityCount).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function sortsInPhp(): bool
	{
		return in_array($this->sort, ['riskLevel', 'entityCount'], true);
	}//end sortsInPhp()

	/**
	 * Whether the page is cut in PHP rather than by the database query.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function cutsInPhp(): bool
	{
		return $this->sortsInPhp() === true || $this->restricted === true;
	}//end cutsInPhp()

	/**
	 * The sort field for the database query.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function databaseSort(): mixed
	{
		if ($this->sortsInPhp() === true) {
			return 'extractedAt';
		}

		return $this->sort;
	}//end databaseSort()

	/**
	 * Sort rows on a field the database query cannot sort on.
	 *
	 * @param array $rows The rows.
	 *
	 * @return array The sorted rows.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function sortRows(array $rows): array
	{
		$sort  = $this->sort;
		$order = $this->order;
		usort(
			$rows,
			function ($a, $b) use ($sort, $order) {
				$cmp = ($a[$sort] ?? 0) <=> ($b[$sort] ?? 0);
				if ($sort === 'riskLevel') {
					$cmp = (self::RISK_ORDER[$a['riskLevel']] ?? 0) <=> (self::RISK_ORDER[$b['riskLevel']] ?? 0);
				}

				if ($order === 'ASC') {
					return $cmp;
				}

				return -$cmp;
			}
		);
		return $rows;
	}//end sortRows()
}//end class
