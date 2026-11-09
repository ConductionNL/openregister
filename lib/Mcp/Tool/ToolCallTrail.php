<?php

/**
 * The caller and the log of a tool function call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Mcp\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Tool;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The session a tool function call runs under and the log that records it.
 *
 * Shared by every agent tool: the session names the calling user, the logger
 * records each call and its failure.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ToolCallTrail {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession    $userSession The session that names the calling user.
	 * @param LoggerInterface $logger      Records each tool function call.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
