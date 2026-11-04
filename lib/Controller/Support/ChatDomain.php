<?php

/**
 * OpenRegister chat domain
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

use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;

/**
 * The domain services of the chat API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatDomain {
	/**
	 * Bundle the services.
	 *
	 * @param ChatService         $chatService         Chat service for AI interactions
	 * @param OrganisationService $organisationService Organisation service for permissions
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ChatService $chatService,
		public readonly OrganisationService $organisationService,
	) {
	}//end __construct()
}//end class
