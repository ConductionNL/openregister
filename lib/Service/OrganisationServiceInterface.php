<?php

/**
 * OrganisationServiceInterface: the public surface of OrganisationService, for callers to type against.
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

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;

/**
 * Every public method of OrganisationService. Callers depend on this interface, not on the class.
 */
interface OrganisationServiceInterface {

	/**
	 * Ensure default organisation exists, create if needed
	 * Uses static application-level caching for performance optimization
	 *
	 * @return Organisation The default organisation
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function ensureDefaultOrganisation(): Organisation;

	/**
	 * Get Organisation settings only
	 *
	 * @return (mixed|null|true)[][] Organisation configuration
	 *
	 * @throws \RuntimeException If Organisation settings retrieval fails
	 *
	 * @psalm-return array{organisation: array{default_organisation: mixed|null,
	 *               auto_create_default_organisation: mixed|true}}
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getOrganisationSettingsOnly(): array;

	/**
	 * Get default organisation UUID from settings
	 *
	 * @return string|null Default organisation UUID or null if not set
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getDefaultOrganisationUuid(): ?string;

	/**
	 * Get organisations for the current user
	 *
	 * @param bool $_useCache Whether to use session cache (temporarily disabled)
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getUserOrganisations(bool $_useCache = true): array;

	/**
	 * Get the active organisation for the current user
	 * Uses session caching to avoid repeated database calls for RBAC performance
	 *
	 * @param array|null $preloadedOrgs Pre-loaded organisations to avoid extra queries.
	 *
	 * @return Organisation|null The active organisation or null.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation;

	/**
	 * The organisation an organisation context acts for.
	 *
	 * Memoised per request by uuid. An organisation that does not exist answers
	 * null, so the organisation filter admits nothing.
	 *
	 * @param string $uuid The organisation uuid the context names.
	 *
	 * @return Organisation|null The organisation, or null when it does not exist.
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	public function findContextOrganisation(string $uuid): ?Organisation;

	/**
	 * Set the active organisation for the current user
	 *
	 * @param string $organisationUuid The organisation UUID to set as active
	 *
	 * @return true True if successfully set, false otherwise
	 *
	 * @throws Exception If user doesn't belong to the organisation
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function setActiveOrganisation(string $organisationUuid): bool;

	/**
	 * Add a user to an organisation
	 *
	 * @param string $organisationUuid The organisation UUID
	 * @param string|null $targetUserId Optional user ID to add. If null, current user is added.
	 *
	 * @return true True if successfully added
	 *
	 * @throws Exception If organisation not found, user not logged in, or target user does not exist
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function joinOrganisation(string $organisationUuid, ?string $targetUserId = null): bool;

	/**
	 * Remove current user or specified user from an organisation
	 *
	 * @param string $organisationUuid The organisation UUID
	 * @param string|null $targetUserId Optional user ID to remove. If null, current user is removed.
	 *
	 * @return true True if successfully removed
	 *
	 * @throws Exception If organisation not found, user not logged in, or trying to leave last organisation
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function leaveOrganisation(string $organisationUuid, ?string $targetUserId = null): bool;

	/**
	 * Create a new organisation
	 *
	 * @param string $name Organisation name
	 * @param string $description Organisation description
	 * @param bool $addCurrentUser Whether to add current user as owner and member
	 * @param string $uuid Optional specific UUID to use
	 *
	 * @return Organisation The created organisation
	 *
	 * @throws OrganisationSlugConflictException If another organisation holds the slug the name gives
	 * @throws Exception                         If user not logged in or organisation creation fails
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function createOrganisation(string $name, string $description = '', bool $addCurrentUser = true, string $uuid = ''): Organisation;

	/**
	 * Check if current user has access to an organisation
	 *
	 * @param string $organisationUuid The organisation UUID to check
	 *
	 * @return bool True if user has access
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function hasAccessToOrganisation(string $organisationUuid): bool;

	/**
	 * Whether a user administers an organisation (may manage its shared resources).
	 *
	 * Owner-level authority: a Nextcloud administrator, or the organisation's
	 * `owner`. This is the gate for creating, updating, or deleting an
	 * organisation-scoped credential (credential-broker-organisation-scope D4).
	 *
	 * follow-up: OpenRegister exposes only owner-level organisation authority
	 * today (the `owner` field). When finer organisation roles land (an
	 * administrators list / an authorization "admin" group), widen this check to
	 * honour them — see the change's Follow-ups.
	 *
	 * @param string $organisationUuid The organisation UUID.
	 * @param string|null $userId The user id to test, or null for the current session user.
	 *
	 * @return bool True when the user is an org owner or a Nextcloud admin.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function isOrganisationAdmin(string $organisationUuid, ?string $userId = null): bool;

	/**
	 * Get user organisation statistics
	 *
	 * @return array Statistics with total count, active organisation, and results list.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getUserOrganisationStats(): array;

	/**
	 * Clear default organisation cache (public method for external use)
	 *
	 * @return void
	 *
	 * @spec exclude Trivial static default-org cache reset; no business logic.
	 */
	public function clearDefaultOrganisationCache(): void;

	/**
	 * Clear all organisation cache for current user
	 *
	 * @param bool $clearPersistent Whether to also clear persistent active organisation setting
	 *
	 * @return bool True if cache cleared
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec exclude Cache-invalidation helper (request/session/static caches + optional persistent setting); no business logic.
	 */
	public function clearCache(bool $clearPersistent = false): bool;

	/**
	 * Get the organisation UUID to use for creating new entities
	 * Uses the active organisation or falls back to default
	 *
	 * @return null|string The organisation UUID to use
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getOrganisationForNewEntity(): ?string;

	/**
	 * Get the default organisation UUID from config
	 *
	 * @return null|string The UUID of the default organisation, or null if not set
	 *
	 * @spec exclude Trivial app-config getter for the defaultOrganisation key; no business logic.
	 */
	public function getDefaultOrganisationId(): ?string;

	/**
	 * Get the system identifier used as `_owner` for writes without an active
	 * user session (cron jobs, background workers, internal service calls).
	 *
	 * Reads `openregister.systemUserId` from app-config; falls back to
	 * {@see OrganisationService::SYSTEM_USER_ID_DEFAULT} when the key is unset or empty.
	 *
	 * The default identifier (`__system__`) cannot collide with a real user
	 * because Nextcloud's user-ID validator rejects identifiers starting with
	 * a double-underscore.
	 *
	 * @return string The system identifier (never empty).
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getSystemUserId(): string;

	/**
	 * Get the Nextcloud groups (beyond `admin`) that are allowed to read
	 * system-owned rows.
	 *
	 * Reads `openregister.systemReaderGroups` from app-config as a
	 * comma-separated string, trims each entry, and drops empties. Returns
	 * an empty array when the key is unset.
	 *
	 * @return string[] Normalised list of group IDs (may be empty).
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getSystemReaderGroups(): array;

	/**
	 * Set the default organisation UUID in config
	 *
	 * @param string $uuid The UUID of the organisation to set as default
	 *
	 * @return void
	 *
	 * @spec exclude Trivial app-config setter + cache bust; no business logic.
	 */
	public function setDefaultOrganisationId(string $uuid): void;

	/**
	 * Get UUIDs of active organisation and all its parent organisations
	 *
	 * This method returns an array of organisation UUIDs that the current user
	 * can access based on their active organisation and the parent hierarchy.
	 * Children can view resources from their parents, recursively up the hierarchy.
	 *
	 * Example hierarchy:
	 * - VNG (root)
	 * - Amsterdam (parent: VNG)
	 * - Noord (parent: Amsterdam)
	 *
	 * When Noord is active, returns: [Noord-UUID, Amsterdam-UUID, VNG-UUID]
	 *
	 * This is used by MultiTenancyTrait for filtering queries to include parent resources.
	 *
	 * @return (mixed|null|string)[]
	 *
	 * @psalm-return list{0?: null|string,...}
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getUserActiveOrganisations(): array;
}//end interface
