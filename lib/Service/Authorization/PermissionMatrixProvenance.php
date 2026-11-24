<?php

/**
 * OpenRegister PermissionMatrixProvenance
 *
 * Who generated a permission matrix export, when, where and how it was read.
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

/**
 * The provenance an export carries in its header.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
final class PermissionMatrixProvenance {

	/**
	 * Constructor.
	 *
	 * @param string $generatedAt     When, as an ISO 8601 timestamp.
	 * @param string $generatedBy     The user id of the administrator who asked.
	 * @param string $instanceId      The Nextcloud instance id.
	 * @param string $level           `group` or `user`.
	 * @param string $denyEnforcement The deny enforcement mode the rows were read under.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		public readonly string $generatedAt,
		public readonly string $generatedBy,
		public readonly string $instanceId,
		public readonly string $level,
		public readonly string $denyEnforcement,
	) {
	}//end __construct()
}//end class
