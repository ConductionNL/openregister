<?php

/**
 * OpenRegister TableContent.
 *
 * One table read from a WordprocessingML body: its rows of cell text and the pictures in its cells.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * One table read from a document body: its rows, each a list of cell texts,
 * and the pictures found in its cells, in document order.
 *
 * @psalm-import-type DocumentImage from DocumentContentReader
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-tables-come-back-as-rows-of-cell-text-req-docx-005
 */
final class TableContent {
	/**
	 * Constructor.
	 *
	 * @param list<list<string>>  $rows   The rows of cell text, as written.
	 * @param list<DocumentImage> $images The pictures found in the cells.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-tables-come-back-as-rows-of-cell-text-req-docx-005
	 */
	public function __construct(
		public readonly array $rows,
		public readonly array $images,
	) {
	}//end __construct()
}//end class
