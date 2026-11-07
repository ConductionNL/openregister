<?php

/**
 * OpenRegister file text domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\ManualEntityService;
use OCA\OpenRegister\Files\Service\TextExtractionService;

/**
 * The domain services of the file text API.
 *
 * @spec openspec/specs/api-test-coverage/spec.md
 */
final class FileTextDomain {
	/**
	 * Bundle the services.
	 *
	 * @param TextExtractionService $textExtractor       Text extraction service
	 * @param FileService           $fileService         File service for file operations
	 * @param ManualEntityService   $manualEntityService Orchestrator for the manual-entity write path
	 * @param ?FileReadScope        $fileReadScope       The object read rule for an object's file.
	 *
	 * @spec openspec/specs/api-test-coverage/spec.md
	 */
	public function __construct(
		public readonly TextExtractionService $textExtractor,
		public readonly FileService $fileService,
		public readonly ManualEntityService $manualEntityService,
		public readonly ?FileReadScope $fileReadScope = null,
	) {
	}//end __construct()
}//end class
