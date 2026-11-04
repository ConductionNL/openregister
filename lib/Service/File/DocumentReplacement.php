<?php

/**
 * OpenRegister DocumentReplacement.
 *
 * Immutable description of one word-replacement run over a document:
 * the source file, the replacement map, the output name and the
 * options that select how the replacement is validated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\Files\File;

/**
 * One replacement run handled by `DocumentProcessingHandler`.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentReplacement {
	/**
	 * Constructor.
	 *
	 * @param File $node The source file.
	 * @param array<string, string> $replacements Map: original text => replacement.
	 * @param string $outputName Name of the output file next to the source.
	 * @param bool $strict Strict (entity anonymisation) validation of PDF output.
	 * @param bool|null $preserveStructure Tri-state PDF structure-preservation option.
	 * @param string|null $sanitizedSourcePath Sanitised temp copy to read instead of the source.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly File $node,
		public readonly array $replacements,
		public readonly string $outputName,
		public readonly bool $strict,
		public readonly ?bool $preserveStructure,
		public readonly ?string $sanitizedSourcePath = null,
	) {

	}//end __construct()

	/**
	 * The same run, reading from a sanitised temp copy.
	 *
	 * @param string $path The sanitised copy.
	 *
	 * @return self The new run description.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function withSanitizedSource(string $path): self {
		return new self(
			node: $this->node,
			replacements: $this->replacements,
			outputName: $this->outputName,
			strict: $this->strict,
			preserveStructure: $this->preserveStructure,
			sanitizedSourcePath: $path
		);

	}//end withSanitizedSource()
}//end class
