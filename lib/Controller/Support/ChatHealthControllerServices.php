<?php

/**
 * OpenRegister ChatHealthController services
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
use Psr\Log\LoggerInterface;

/**
 * The collaborators of ChatHealthController, wired by DI autowiring.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatHealthControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AppSettingsInterface $settingsService Settings service for LLM config
	 * @param LoggerInterface      $logger          Logger for surfacing config errors
	 */
	public function __construct(
		public readonly AppSettingsInterface $settingsService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
