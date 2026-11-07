<?php

/**
 * OpenRegister conversation domain
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

use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;

/**
 * The domain services of the conversation API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ConversationDomain {
	/**
	 * Bundle the services.
	 *
	 * @param OrganisationServiceInterface $organisationService Organisation service
	 * @param ChatServiceInterface         $chatService         Chat service
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly OrganisationServiceInterface $organisationService,
		public readonly ChatServiceInterface $chatService,
	) {
	}//end __construct()
}//end class
