<?php

/**
 * TextExtractionAnalysis: the entity recognition, settings, risk level and logging of TextExtractionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The entity recognition, settings, risk level and logging of TextExtractionService.
 */
final class TextExtractionAnalysis {
	/**
	 * Bundle the services.
	 *
	 * @param EntityRecognitionHandler $entityHandler    Handler for entity recognition
	 * @param SettingsServiceInterface $settingsService  Settings service
	 * @param RiskLevelService         $riskLevelService Risk level computation service
	 * @param LoggerInterface          $logger           Logger
	 */
	public function __construct(
		public readonly EntityRecognitionHandler $entityHandler,
		public readonly SettingsServiceInterface $settingsService,
		public readonly RiskLevelService $riskLevelService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
