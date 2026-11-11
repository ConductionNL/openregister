<?php

/**
 * SlugBackfillCursor
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Where one page of a slug backfill starts and how many rows it reads.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SlugBackfillCursor {

	/**
	 * Constructor.
	 *
	 * @param int $afterId  Last `_id` of the previous page (0 for the first).
	 * @param int $pageSize Rows per page.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $afterId,
		public readonly int $pageSize,
	) {
	}//end __construct()
}//end class
