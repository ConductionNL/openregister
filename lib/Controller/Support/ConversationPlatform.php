<?php

/**
 * OpenRegister conversation platform
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

use Psr\Log\LoggerInterface;

/**
 * The platform services of the conversation API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ConversationPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
