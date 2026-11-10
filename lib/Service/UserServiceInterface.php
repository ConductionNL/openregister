<?php

/**
 * UserServiceInterface: the part of UserService that the user controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCP\IUser;
use RuntimeException;

/**
 * The methods of UserService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface UserServiceInterface {

	/**
	 * Get current authenticated user
	 *
	 * @return IUser|null The current user or null if not authenticated
	 */
	public function getCurrentUser(): ?IUser;

	/**
	 * Build comprehensive user data array
	 *
	 * @param IUser $user The user object to build data for
	 *
	 * @return array The comprehensive user data array
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function buildUserDataArray(IUser $user): array;

	/**
	 * Update user properties based on provided data
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return array Result of the update operation including organization changes
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function updateUserProperties(IUser $user, array $data): array;

	/**
	 * Change password for the current user
	 *
	 * Validates the current password, checks backend capability,
	 * and sets the new password.
	 *
	 * @param IUser $user The user changing their password
	 * @param string $currentPassword The current password for verification
	 * @param string $newPassword The new password to set
	 *
	 * @return array Result array with success status
	 *
	 * @throws InvalidArgumentException If inputs are invalid
	 * @throws RuntimeException If password change fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function changePassword(IUser $user, string $currentPassword, string $newPassword): array;

	/**
	 * Upload a new avatar for the user
	 *
	 * Validates file type and size, then sets via IAvatarManager.
	 *
	 * @param IUser $user The user uploading an avatar
	 * @param string $data The raw image data
	 * @param string $mimeType The MIME type of the uploaded file
	 * @param int $size The file size in bytes
	 *
	 * @return array Result array with success status and avatar URL
	 *
	 * @throws RuntimeException If upload fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function uploadAvatar(IUser $user, string $data, string $mimeType, int $size): array;

	/**
	 * Delete the user's avatar
	 *
	 * Removes the custom avatar and resets to the default.
	 *
	 * @param IUser $user The user deleting their avatar
	 *
	 * @return array Result array with success status
	 *
	 * @throws RuntimeException If deletion fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function deleteAvatar(IUser $user): array;

	/**
	 * Export personal data for the current user (GDPR Article 20)
	 *
	 * Assembles profile data, organisation memberships, and audit trail entries
	 * into a downloadable JSON structure. Rate limited to once per hour.
	 *
	 * @param IUser $user The user requesting data export
	 *
	 * @return array The export data structure
	 *
	 * @throws RuntimeException If rate limited
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function exportPersonalData(IUser $user): array;

	/**
	 * Get notification preferences for the current user
	 *
	 * Returns stored preferences with defaults for unset values.
	 *
	 * @param IUser $user The user to get preferences for
	 *
	 * @return array The notification preferences
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function getNotificationPreferences(IUser $user): array;

	/**
	 * Update notification preferences for the current user
	 *
	 * Validates and stores preference values in IConfig.
	 *
	 * @param IUser $user The user to update preferences for
	 * @param array $prefs The preference values to update
	 *
	 * @return array The complete updated preferences
	 *
	 * @throws InvalidArgumentException If invalid preference values
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function setNotificationPreferences(IUser $user, array $prefs): array;

	/**
	 * Get activity history for the current user
	 *
	 * Queries audit trail entries where the user is the actor.
	 *
	 * @param IUser $user The user to get activity for
	 * @param int $limit Maximum results to return
	 * @param int $offset Results to skip
	 * @param string|null $type Optional action type filter
	 * @param string|null $from Optional start date (Y-m-d)
	 * @param string|null $to Optional end date (Y-m-d)
	 *
	 * @return array Activity results with total count
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function getUserActivity(
		IUser $user,
		int $limit = 25,
		int $offset = 0,
		?string $type = null,
		?string $from = null,
		?string $to = null,
	): array;

	/**
	 * Create a new API token for the user
	 *
	 * Generates a cryptographically secure token and stores it in IConfig.
	 *
	 * @param IUser $user The user creating a token
	 * @param string $name The token name
	 * @param string|null $expiresIn Optional expiration (e.g., "90d")
	 *
	 * @return array The created token data (full value shown only once)
	 *
	 * @throws RuntimeException If maximum tokens reached
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function createApiToken(IUser $user, string $name, ?string $expiresIn = null): array;

	/**
	 * List API tokens for the user (masked values)
	 *
	 * @param IUser $user The user to list tokens for
	 *
	 * @return array Array of token objects with masked values
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function listApiTokens(IUser $user): array;

	/**
	 * Revoke an API token by ID
	 *
	 * @param IUser $user The user revoking the token
	 * @param string $tokenId The token ID to revoke
	 *
	 * @return array Result array
	 *
	 * @throws RuntimeException If token not found
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function revokeApiToken(IUser $user, string $tokenId): array;

	/**
	 * Request account deactivation
	 *
	 * Creates a pending deactivation request for admin approval.
	 *
	 * @param IUser $user The user requesting deactivation
	 * @param string $reason Optional reason for deactivation
	 *
	 * @return array Result array with status
	 *
	 * @throws RuntimeException If duplicate request exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function requestDeactivation(IUser $user, string $reason = ''): array;

	/**
	 * Get deactivation request status
	 *
	 * @param IUser $user The user to check status for
	 *
	 * @return array Status information
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function getDeactivationStatus(IUser $user): array;

	/**
	 * Cancel a pending deactivation request
	 *
	 * @param IUser $user The user cancelling their request
	 *
	 * @return array Result array
	 *
	 * @throws RuntimeException If no pending request
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function cancelDeactivation(IUser $user): array;
}//end interface
