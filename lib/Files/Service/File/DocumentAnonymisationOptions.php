<?php

/**
 * OpenRegister DocumentAnonymisationOptions.
 *
 * The optional settings of one document anonymisation: placeholder scope, dossier key and PDF structure preservation.
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

/**
 * Optional settings of one document anonymisation.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentAnonymisationOptions {
	/**
	 * Constructor.
	 *
	 * @param string      $scope             Placeholder-numbering scope: 'document' or 'dossier'.
	 * @param string|null $fileKey           Stable folder id of the dossier when the scope is 'dossier'.
	 * @param bool|null   $preserveStructure PDF only: null = auto, true = attempt, false = skip but measure.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $scope = 'document',
		public readonly ?string $fileKey = null,
		public readonly ?bool $preserveStructure = null,
	) {
	}//end __construct()
}//end class
