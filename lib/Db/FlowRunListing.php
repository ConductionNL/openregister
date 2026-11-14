<?php

/**
 * One page of a run history: the filters and the paging window.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * One page of a run history read: what to list and which window of it.
 *
 * Newest first; the window is a page size and an offset into the filtered set.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
final readonly class FlowRunListing {
	/**
	 * Constructor.
	 *
	 * @param FlowRunFilter $filter What to list.
	 * @param integer       $limit  Page size.
	 * @param integer       $offset Page offset.
	 *
	 * @return void
	 */
	public function __construct(
		public FlowRunFilter $filter = new FlowRunFilter(),
		public int $limit = 50,
		public int $offset = 0,
	) {
	}//end __construct()
}//end class
