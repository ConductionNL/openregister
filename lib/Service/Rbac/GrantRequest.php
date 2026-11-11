<?php

/**
 * One per-object grant asked for: whom, as which principal type, on which terms.
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
 * A grant request: the principal type, the principal, and the terms it is made on.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class GrantRequest {
	/**
	 * Hold the request.
	 *
	 * @param string     $type      One of ObjectSharingService's grantable types (user, group, remote, remote_group).
	 * @param string     $shareWith The principal to grant, as sent.
	 * @param GrantTerms $terms     The permission mask, the verbs, the end and the share time.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $shareWith,
		public readonly GrantTerms $terms,
	) {
	}//end __construct()
}//end class
