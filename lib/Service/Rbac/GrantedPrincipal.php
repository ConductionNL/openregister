<?php

/**
 * The principal a grant or an owner-group change would let in: a user, a group, or a remote user or group.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Whom a request lets in, as GrantManageGuard::letsItselfIn() compares it with the caller.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class GrantedPrincipal {

	/**
	 * Constructor.
	 *
	 * @param string|null $user   The user being let in, or null.
	 * @param string|null $group  The group being let in, or null.
	 * @param string|null $remote The remote user or remote group being let in, or null.
	 */
	public function __construct(
		public readonly ?string $user = null,
		public readonly ?string $group = null,
		public readonly ?string $remote = null,
	) {
	}//end __construct()
}//end class
