<?php

/**
 * OpenRegister ExportPage
 *
 * One page of a bounded export: which page, and how many rows a page holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * A page of an export that is read in bounded pieces (design D-4).
 *
 * An export without a page reads every row, as a signed-in export always did.
 * An export with one reads at most `size` rows, starting after the pages
 * before it, so a harvester walks a large set page by page.
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */
final class ExportPage {

	/**
	 * The most rows an anonymous export returns per response.
	 *
	 * @var int
	 */
	public const PUBLIC_SIZE = 10000;

	/**
	 * Describe one page.
	 *
	 * @param int $number The 1-based page number.
	 * @param int $size   The rows per page.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly int $number = 1,
		public readonly int $size = self::PUBLIC_SIZE,
	) {
	}//end __construct()

	/**
	 * The page a request's `_page` asks for, at the public page size.
	 *
	 * Anything that is not a positive whole number reads as page 1, so a
	 * malformed parameter narrows to the first page rather than failing.
	 *
	 * @param mixed $requested The `_page` parameter.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public static function publicFrom(mixed $requested): self {
		$number = 1;
		if ((is_int($requested) === true || (is_string($requested) === true && ctype_digit($requested) === true))
			&& (int)$requested > 0
		) {
			$number = (int)$requested;
		}

		return new self(number: $number);
	}//end publicFrom()

	/**
	 * How many pages a set of this many rows takes; an empty set is one page.
	 *
	 * @param int $total The number of rows matching the export.
	 *
	 * @return int The page count, at least 1.
	 *
	 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
	 */
	public function pagesFor(int $total): int {
		return max(1, (int)ceil($total / max(1, $this->size)));
	}//end pagesFor()
}//end class
