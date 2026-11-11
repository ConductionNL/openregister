<?php

/**
 * OpenRegister file extraction domain
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

use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtractionService;

/**
 * The domain services of the file extraction API.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileExtractionDomain {
	/**
	 * Bundle the services.
	 *
	 * @param TextExtractionService         $textExtractor        Text extraction service
	 * @param VectorizationServiceInterface $vectorizationService Unified vectorization service
	 * @param RiskLevelService              $riskLevelService     Risk level computation service
	 * @param ?FileReadScope                $fileReadScope        The object read rule for an object's file.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly TextExtractionService $textExtractor,
		public readonly VectorizationServiceInterface $vectorizationService,
		public readonly RiskLevelService $riskLevelService,
		public readonly ?FileReadScope $fileReadScope = null,
	) {
	}//end __construct()
}//end class
