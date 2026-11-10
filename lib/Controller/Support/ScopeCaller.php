<?php

/**
 * OpenRegister scope caller
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

/**
 * The caller a scope discovery probes: who they are, and whether they hold
 * every action by the admin bypass.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ScopeCaller {
	/**
	 * Name the caller probed.
	 *
	 * @param string|null $userId  Active user (null = unauthenticated probe).
	 * @param bool        $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly bool $isAdmin,
	) {
	}//end __construct()
}//end class
