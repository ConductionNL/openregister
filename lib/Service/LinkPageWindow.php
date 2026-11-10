<?php

/**
 * OpenRegister Link Page Window
 *
 * The offset and size of one page of an object's link rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Where a page of link rows starts and how many rows it holds.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class LinkPageWindow {
	/**
	 * Constructor.
	 *
	 * @param int $offset The row offset the page starts at.
	 * @param int $limit  The page size, already clamped.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly int $offset,
		public readonly int $limit,
	) {
	}//end __construct()

	/**
	 * The cursor of the page after this one, when there is one.
	 *
	 * @param bool $hasMore Whether more rows follow this page.
	 *
	 * @return int|null The next cursor; null on the last page.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function nextCursor(bool $hasMore): ?int {
		if ($hasMore === true) {
			return ($this->offset + $this->limit);
		}

		return null;
	}//end nextCursor()
}//end class
