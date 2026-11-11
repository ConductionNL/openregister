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
use OCA\OpenRegister\Service\User\ActivityQuery;
use OCA\OpenRegister\Service\User\ApiTokenRequest;
use OCA\OpenRegister\Service\User\AvatarUpload;
use OCA\OpenRegister\Service\User\PasswordChange;
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
	 * Change the user's password after verifying the current one.
	 *
	 * Validates the current password, checks backend capability,
	 * and sets the new password.
	 *
	 * @param IUser          $user   The user changing their password.
	 * @param PasswordChange $change The current password and the new one.
	 *
	 * @return array Result array with success status
	 *
	 * @throws InvalidArgumentException If inputs are invalid
	 * @throws RuntimeException If password change fails
	 *
	 * @spec openspec/specs/profile-actions/spec.md
	 */
	public function applyPasswordChange(IUser $user, PasswordChange $change): array;

	/**
	 * Store an uploaded image as the user's avatar.
	 *
	 * Validates file type and size, then sets via IAvatarManager.
	 *
	 * @param IUser        $user   The user uploading an avatar.
	 * @param AvatarUpload $upload The image, its MIME type and its size.
	 *
	 * @return array Result array with success status and avatar URL
	 *
	 * @throws RuntimeException If upload fails
	 *
	 * @spec openspec/specs/profile-actions/spec.md
	 */
	public function setAvatar(IUser $user, AvatarUpload $upload): array;

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
	 * Get one page of a user's activity.
	 *
	 * Queries audit trail entries where the user is the actor.
	 *
	 * @param IUser         $user  The user to get activity for.
	 * @param ActivityQuery $query The page, the type filter and the period.
	 *
	 * @return array Activity results with total count
	 *
	 * @spec openspec/specs/profile-actions/spec.md
	 */
	public function activity(IUser $user, ActivityQuery $query): array;

	/**
	 * Mint a personal API token.
	 *
	 * Generates a cryptographically secure token and stores it in IConfig.
	 *
	 * @param IUser           $user    The user creating a token.
	 * @param ApiTokenRequest $request The token name and its optional lifetime.
	 *
	 * @return array The created token data (full value shown only once)
	 *
	 * @throws RuntimeException If maximum tokens reached
	 * @throws InvalidArgumentException If the lifetime is malformed
	 *
	 * @spec openspec/specs/profile-actions/spec.md
	 */
	public function issueApiToken(IUser $user, ApiTokenRequest $request): array;

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
