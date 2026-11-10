<?php

/**
 * OpenRegister WordReplacementRequest.
 *
 * One request to replace words in a document: the source node, the replacements and the output settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\Node;

/**
 * One request to replace words in a document.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class WordReplacementRequest {
	/**
	 * Constructor.
	 *
	 * @param Node                  $node              The file node to process.
	 * @param array<string, string> $replacements      Map: search text => replacement.
	 * @param string|null           $outputName        Name of the output file; null adds a suffix to the source name.
	 * @param bool                  $strict            PDF only: when true (entity anonymisation), an output that
	 *                                                 cannot be re-read for the residual check is refused instead
	 *                                                 of written unchecked.
	 * @param bool|null             $preserveStructure PDF only (REQ-ORTPR-004): null = auto (preserve iff the input
	 *                                                 is a tagged PDF), true = attempt, false = skip but still
	 *                                                 measure. Ignored for DOCX/ODT/text.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict selects strict vs lenient validation per the entity-anonymisation contract.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly Node $node,
		public readonly array $replacements,
		public readonly ?string $outputName = null,
		public readonly bool $strict = false,
		public readonly ?bool $preserveStructure = null,
	) {
	}//end __construct()
}//end class
