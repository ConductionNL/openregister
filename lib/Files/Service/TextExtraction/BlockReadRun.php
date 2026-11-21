<?php

/**
 * OpenRegister BlockReadRun.
 *
 * State of one read of block content: relationships, nesting depth and pictures.
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
 * State of one read of block content (table cells, text boxes, wrappers):
 * the relationships, the current nesting depth and the pictures found so far.
 * Never leaves DocumentContentReader.
 *
 * @psalm-import-type DocumentImage from DocumentContentReader
 * @psalm-import-type Relationships from DocumentContentReader
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 */
final class BlockReadRun {
	/**
	 * Pictures found so far, in document order.
	 *
	 * @var list<DocumentImage>
	 */
	public array $images = [];

	/**
	 * Constructor.
	 *
	 * @param Relationships $relationships The document part's relationships.
	 * @param int           $depth         How deeply the content being read is nested.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function __construct(
		public readonly array $relationships,
		public int $depth,
	) {
	}//end __construct()
}//end class
