<?php

/**
 * AgentAudience
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Mcp\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\OpenRegister\Mcp\Db;

/**
 * Who an agent listing is for: one organisation, narrowed to the agents one user may access when a user is named.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class AgentAudience {
	/**
	 * Describe the audience.
	 *
	 * @param string      $organisationUuid The organisation whose agents are listed.
	 * @param string|null $userId           The user whose access filters the list, or null for no filtering.
	 */
	public function __construct(
		public readonly string $organisationUuid,
		public readonly ?string $userId = null,
	) {
	}//end __construct()
}//end class
