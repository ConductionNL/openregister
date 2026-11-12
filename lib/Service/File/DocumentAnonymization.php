<?php

/**
 * What to replace in a document and how to number the placeholders.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

/**
 * The detected entities to replace in one document, with the placeholder scope and the PDF structure option.
 *
 * Read by {@see \OCA\OpenRegister\Service\FileService::anonymizeDocument()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentAnonymization {
	/**
	 * Constructor.
	 *
	 * @param array       $entities          Detected entities with 'text' and 'key' fields.
	 * @param string      $scope             Placeholder-numbering scope: 'document' or 'dossier'.
	 * @param string|null $fileKey           Stable folder id of the dossier (per-dossier scope); null falls
	 *                                       back to the file's parent folder.
	 * @param bool|null   $preserveStructure PDF only: null = auto (preserve iff the input is a tagged PDF),
	 *                                       true = attempt, false = skip but still measure.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly array $entities,
		public readonly string $scope='document',
		public readonly ?string $fileKey=null,
		public readonly ?bool $preserveStructure=null,
	) {
	}//end __construct()
}//end class
