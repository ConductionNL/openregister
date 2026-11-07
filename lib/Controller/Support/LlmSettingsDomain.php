<?php

/**
 * OpenRegister llm settings domain
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;

/**
 * Services of the llm settings API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class LlmSettingsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param AppSettingsInterface          $settingsService      Settings service.
	 * @param VectorizationServiceInterface $vectorizationService Vectorization service.
	 * @param ConnectionReporter            $connectionReporter   Reports the saved provider to integriq's connection registry.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly AppSettingsInterface $settingsService,
		public readonly VectorizationServiceInterface $vectorizationService,
		public readonly ConnectionReporter $connectionReporter,
	) {
	}//end __construct()
}//end class
