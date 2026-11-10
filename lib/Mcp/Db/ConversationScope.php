<?php

/**
 * ConversationScope
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
 * Which conversations a lookup reads: the owning user's, inside one organisation when one is named,
 * and live only unless the archived (soft-deleted) ones are asked for too.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ConversationScope {
	/**
	 * Describe the scope.
	 *
	 * @param string      $userId         The owning user.
	 * @param string|null $organisation   The organisation UUID, or null for every organisation.
	 * @param bool        $includeDeleted Whether soft-deleted conversations count too.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Include deleted toggle is intentional
	 */
	public function __construct(
		public readonly string $userId,
		public readonly ?string $organisation = null,
		public readonly bool $includeDeleted = false,
	) {
	}//end __construct()
}//end class
