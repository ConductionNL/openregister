<?php

/**
 * TenantKeyRotationInterface: what the occ key-rotation command asks of TenantKeyService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use RuntimeException;

/**
 * Checking for and rotating a tenant's audit-trail HMAC key, as the occ
 * rotation command uses it. Only the methods it calls; key material itself is
 * not part of this interface.
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md#tenantkeyservice-must-be-di-registered-as-a-server-side-internal-api
 */
interface TenantKeyRotationInterface {

	/**
	 * Rotate the active HMAC key for a tenant; returns metadata only, never the key.
	 *
	 * @param string $tenantId Tenant identifier
	 *
	 * @return array{
	 *     tenant_id: string,
	 *     rotated_at: string,
	 *     retired_key_id: int|null
	 * } Rotation metadata: the tenant, the ISO-8601 rotation timestamp, and
	 *   the primary-key id of the row that was retired (or null when there
	 *   was no prior active key for this tenant).
	 *
	 * @throws RuntimeException When key encryption fails
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md#new-active-keys-must-be-stored-encrypted-with-status-active
	 */
	public function rotateTenantKey(string $tenantId): array;

	/**
	 * Whether a tenant has an active key, without creating one.
	 *
	 * @param string $tenantId Tenant identifier
	 *
	 * @return bool True when an active key row exists.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function hasActiveKey(string $tenantId): bool;
}//end interface
