<?php

/**
 * OpenRegister PermissionMatrixRequest
 *
 * What one permission matrix export covers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The registers, schemas, subjects and object grants one export lists.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
final class PermissionMatrixRequest {

	/**
	 * Constructor.
	 *
	 * @param array<int, Register>                $registers     The registers.
	 * @param array<int, Schema>                  $schemas       The schemas; each is listed under the registers that hold it.
	 * @param array<int, PermissionMatrixSubject>    $subjects      The groups, or the users, to list.
	 * @param bool                                   $denyEnforcing Whether a deny bites now; below `enforcing` it is reported as staged.
	 * @param array<int, array<string, mixed>>|null  $objectGrants  Per-object grant rows (register, schema, object, principal,
	 *                                                              action, rule), or null when the caller does not list them.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		public readonly array $registers,
		public readonly array $schemas,
		public readonly array $subjects,
		public readonly bool $denyEnforcing = true,
		public readonly ?array $objectGrants = null,
	) {
	}//end __construct()
}//end class
