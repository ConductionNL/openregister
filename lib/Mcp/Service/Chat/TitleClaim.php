<?php

/**
 * A conversation title a user claims for one agent, made unique against the user's other titles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Chat;

/**
 * A title claim: the wanted title and the user-agent pair it must be unique for.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class TitleClaim {
	/**
	 * Hold the claim.
	 *
	 * @param string $baseTitle Wanted title.
	 * @param string $userId    User ID.
	 * @param int    $agentId   Agent ID.
	 */
	public function __construct(
		public readonly string $baseTitle,
		public readonly string $userId,
		public readonly int $agentId,
	) {
	}//end __construct()
}//end class
