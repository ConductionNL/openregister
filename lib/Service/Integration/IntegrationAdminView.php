<?php

/**
 * OpenRegister IntegrationAdminView
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The admin-only part of an integration descriptor, and who gets it.
 *
 * Shared by the integrations capability and the integrations endpoint, which
 * each carried their own copy. Per AD-17 the admin fields are OMITTED (not
 * null-stubbed) for a caller who is not admin, so absence matches
 * non-existence.
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-registered-integrations-must-be-advertised-via-ocs-capabilities
 */
final class IntegrationAdminView {
	/**
	 * Whether the current user is in the admin group.
	 *
	 * @param IUserSession  $userSession  The session of the caller
	 * @param IGroupManager $groupManager The group manager
	 *
	 * @return bool True for a signed-in admin
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-registered-integrations-must-be-advertised-via-ocs-capabilities
	 */
	public static function callerIsAdmin(IUserSession $userSession, IGroupManager $groupManager): bool {
		$user = $userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $groupManager->isAdmin($user->getUID());
	}//end callerIsAdmin()

	/**
	 * Add the admin-only operational fields to a provider's public descriptor.
	 *
	 * A provider whose health check throws reports itself unavailable instead
	 * of failing the whole listing.
	 *
	 * @param array<string, mixed> $row      The public descriptor
	 * @param IntegrationProvider  $provider The provider
	 *
	 * @return array<string, mixed> The descriptor with requiresPermission, openConnectorSource and authStatus
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-registered-integrations-must-be-advertised-via-ocs-capabilities
	 */
	public static function withAdminFields(array $row, IntegrationProvider $provider): array {
		$row['requiresPermission'] = $provider->requiresPermission();
		$row['openConnectorSource'] = $provider->getOpenConnectorSource();

		try {
			$row['authStatus'] = $provider->health();
		} catch (\Throwable $e) {
			$row['authStatus'] = [
				'status' => 'unavailable',
				'authStatus' => 'unknown',
				'message' => 'Provider health check threw',
			];
		}

		return $row;
	}//end withAdminFields()
}//end class
