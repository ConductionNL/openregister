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

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\File;

/**
 * One replacement run handled by `DocumentProcessingHandler`.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentReplacement {
	/**
	 * Strict (entity anonymisation) validation of PDF output.
	 *
	 * @var boolean
	 */
	private bool $strict = false;

	/**
	 * Tri-state PDF structure-preservation option.
	 *
	 * @var boolean|null
	 */
	private ?bool $preserveStructure = null;

	/**
	 * Sanitised temp copy to read instead of the source.
	 *
	 * @var string|null
	 */
	private ?string $sanitizedSourcePath = null;

	/**
	 * Constructor.
	 *
	 * @param File $node The source file.
	 * @param array<string, string> $replacements Map: original text => replacement.
	 * @param string $outputName Name of the output file next to the source.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly File $node,
		public readonly array $replacements,
		public readonly string $outputName,
	) {

	}//end __construct()

	/**
	 * The same run with the PDF validation options.
	 *
	 * @param bool $strict Strict (entity anonymisation) validation of PDF output.
	 * @param bool|null $preserveStructure Tri-state structure-preservation option.
	 *
	 * @return self The new run description.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function withPdfOptions(bool $strict, ?bool $preserveStructure): self {
		$copy = clone $this;
		$copy->strict = $strict;
		$copy->preserveStructure = $preserveStructure;
		return $copy;

	}//end withPdfOptions()

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
		$copy = clone $this;
		$copy->sanitizedSourcePath = $path;
		return $copy;

	}//end withSanitizedSource()

	/**
	 * Whether residual entity text in PDF output is validated strictly.
	 *
	 * @return bool True for strict validation.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function isStrict(): bool {
		return $this->strict;

	}//end isStrict()

	/**
	 * The tri-state PDF structure-preservation option.
	 *
	 * @return bool|null Null for auto.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function preserveStructure(): ?bool {
		return $this->preserveStructure;

	}//end preserveStructure()

	/**
	 * The sanitised temp copy to read, if any.
	 *
	 * @return string|null The path, or null to read the source.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function sanitizedSourcePath(): ?string {
		return $this->sanitizedSourcePath;

	}//end sanitizedSourcePath()
}//end class
