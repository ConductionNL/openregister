<?php

/**
 * OpenRegister chat stream platform
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

use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * The platform services of the chat stream API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatStreamPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession  $userSession User session.
	 * @param IDBConnection $db          Database connection.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
