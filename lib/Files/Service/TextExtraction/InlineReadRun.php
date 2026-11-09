<?php

/**
 * OpenRegister InlineReadRun.
 *
 * State of one read of a paragraph: relationships, content so far and picture.
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
 * State of one read of a paragraph's inline content: the relationships, the
 * content collected so far and the picture being read.
 * Never leaves DocumentContentReader.
 *
 * @psalm-import-type InlineContent from DocumentContentReader
 * @psalm-import-type PictureContext from DocumentContentReader
 * @psalm-import-type Relationships from DocumentContentReader
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 */
final class InlineReadRun {
	/**
	 * The content so far.
	 *
	 * @var InlineContent
	 */
	public array $inline = ['text' => '', 'images' => [], 'textBoxes' => []];

	/**
	 * The name and alt text of the drawing or shape being read.
	 *
	 * @var PictureContext
	 */
	public array $picture = ['name' => '', 'description' => ''];

	/**
	 * Constructor.
	 *
	 * @param Relationships $relationships The document part's relationships.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function __construct(
		public readonly array $relationships,
	) {
	}//end __construct()
}//end class
