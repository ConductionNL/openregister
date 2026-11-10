<?php

/**
 * ReachRevocationServiceInterface: the part of ReachRevocationService that the principal reach controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Rbac
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The methods of ReachRevocationService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ReachRevocationServiceInterface {

	/**
	 * Revoke everything this principal can reach, in one act.
	 *
	 * @param string $principal The account losing its reach.
	 * @param string $reason    Why, recorded with the act.
	 *
	 * @return array<string, mixed> One record naming every grant removed and every one retained.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function revokeAll(string $principal, string $reason = ''): array;
}//end interface
