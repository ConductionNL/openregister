<?php

/**
 * TenancyIdentityTrait: who the caller is and which organisations they act in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Trait
 * @package  OCA\OpenRegister\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Tenancy;

use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Service\TenantLogRedactor;
use OCP\IAppConfig;
use OCP\IUser;

/**
 * The identity half of MultiTenancyTrait: the session user, the active and
 * default organisation, the hierarchy above it, and the resolvers the other
 * tenancy traits share.
 *
 * Composed only by MultiTenancyTrait; a mapper uses that trait, never this one.
 * Deliberately without strict_types, like the trait it was split from, so the
 * moved code coerces exactly as it always did.
 *
 * @package OCA\OpenRegister\Db\Tenancy
 */
trait TenancyIdentityTrait {

	/**
	 * The shared master data resolver, built on first use.
	 *
	 * NOT constructor-injected, and that is a deliberate trade. Eleven mappers
	 * mix the tenancy trait in, each with its own constructor and its own unit
	 * tests building it by hand; threading one more argument through all of them
	 * to reach a class that reads two columns would be a large, purely mechanical
	 * diff. The resolver takes exactly one dependency, `$this->db`, which the
	 * trait already documents as required, and holds no state beyond a
	 * per-request cache.
	 *
	 * @var SharedMasterDataService|null
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
	 */
	private ?SharedMasterDataService $sharedMasterData = null;

	/**
	 * The shared master data resolver for this mapper.
	 *
	 * `$this->db` and `getTableName()` are read WITHOUT a probe, and that is a
	 * decision rather than an oversight: both are required of the host, and
	 * every mapper supplies them by extending QBMapper. A probe was tried first
	 * and removed: phpstan proved it dead in the context of every real mapper.
	 * `TenancyGuardHost`, the stand-in in the trait's own unit test, supplies
	 * both, as a real mapper does.
	 *
	 * @return SharedMasterDataService The resolver.
	 */
	private function sharedMasterData(): SharedMasterDataService {
		if ($this->sharedMasterData === null) {
			$this->sharedMasterData = new SharedMasterDataService(db: $this->db);
		}

		return $this->sharedMasterData;
	}//end sharedMasterData()

	/**
	 * Whether this table can carry a shared master data declaration.
	 *
	 * Only registers and schemas can be declared shared master data.
	 *
	 * @param string $table The table name.
	 *
	 * @return bool
	 */
	private function isSharedMasterDataTable(string $table): bool {
		return $table === SharedMasterDataService::REGISTERS || $table === SharedMasterDataService::SCHEMAS;
	}//end isSharedMasterDataTable()

	/**
	 * Which rows of THIS mapper's table the caller may read through a declared share.
	 *
	 * Only registers and schemas can be declared shared master data, so every
	 * other mapper using the trait resolves an empty list and its query is
	 * untouched, byte for byte.
	 *
	 * @param array<int, string> $activeOrgUuids The caller's organisation and its parents.
	 *
	 * @return array<int, int> Row ids readable through a share.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	private function sharedMasterDataIds(array $activeOrgUuids): array {
		if ($activeOrgUuids === []) {
			return [];
		}

		$table = $this->getTableName();
		if ($this->isSharedMasterDataTable(table: $table) === false) {
			return [];
		}

		return $this->sharedMasterData()->sharedIds(table: $table, consumerOrgUuids: $activeOrgUuids);
	}//end sharedMasterDataIds()

	/**
	 * The log redactor for this mapper.
	 *
	 * @return TenantLogRedactor The redactor, with the app config when there is one.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function tenantLogRedactor(): TenantLogRedactor {
		return new TenantLogRedactor(appConfig: $this->tenancyAppConfig());
	}//end tenantLogRedactor()

	/**
	 * Write one tenancy audit line through the redactor (REQ-SLE-003).
	 *
	 * The context's `actor` holds the raw uid and is replaced, in place, by its
	 * pseudonym; the rest goes through the redactor, and a context the redactor
	 * cannot vouch for drops the line. Callers check for a logger first too,
	 * so that a host without one never builds the context.
	 *
	 * @param string $level   The log level method, `info` or `warning`.
	 * @param string $message The message.
	 * @param array  $context The context, with the raw uid under `actor`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function logRedacted(string $level, string $message, array $context): void {
		if (isset($this->logger) === false) {
			return;
		}

		$redactor = $this->tenantLogRedactor();
		$context['actor'] = $redactor->pseudonym(userId: $context['actor']);
		$line = $redactor->line(context: $context);
		if ($line !== null) {
			$this->logger->{$level}($message, $line);
		}
	}//end logRedacted()

	/**
	 * The host's app config, or null when this host does not carry one.
	 *
	 * `isset()` rather than `??`, deliberately: reading a DECLARED but
	 * uninitialised typed property with `??` raises an Error, and several
	 * mappers declare `$appConfig` without always assigning it. The trait's
	 * header has always called `$appConfig` OPTIONAL, and six of the eleven
	 * mappers declare no such property, so the probe is real. This accessor
	 * exists so the number of psalm-baselined probes does not grow.
	 *
	 * @return IAppConfig|null The app config, or null.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function tenancyAppConfig(): ?IAppConfig {
		if (isset($this->appConfig) === false) {
			return null;
		}

		return $this->appConfig;
	}//end tenancyAppConfig()

	/**
	 * Get the active organisation UUID from the session.
	 *
	 * Falls back to the default organisation from config if no active organisation is set.
	 * Automatically sets the default as active if user has no active organisation.
	 *
	 * @return string|null The active organisation UUID or default organisation UUID, or null if neither set
	 */
	protected function getActiveOrganisationUuid(): ?string {
		$this->debugTenancy(message: '[MultiTenancyTrait] getActiveOrganisationUuid called');

		// Get current user.
		if (isset($this->userSession) === false) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null || isset($this->organisationMapper) === false) {
			// No user, or no OrganisationMapper to ask: the configured default.
			return $this->getDefaultOrganisationUuid();
		}

		return $this->activeOrganisationOf(user: $user);
	}//end getActiveOrganisationUuid()

	/**
	 * The user's active organisation, with OrganisationMapper's automatic fallback to the default.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return string|null The organisation UUID.
	 */
	private function activeOrganisationOf(IUser $user): ?string {
		$organisationMapper = $this->organisationMapper;
		if (isset($this->logger) === true) {
			$this->logger->debug(
				message: '[MultiTenancyTrait] Calling getActiveOrganisationWithFallback for user: ' . $user->getUID(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		// @psalm-suppress UndefinedMethod
		return $organisationMapper->getActiveOrganisationWithFallback($user->getUID());
	}//end activeOrganisationOf()

	/**
	 * Write a debug line when the host has a logger.
	 *
	 * Debug, not info: this runs on the hot tenant-filter path (once per mapper
	 * query), so at info level it emitted ~1,000 lines per object write.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function debugTenancy(string $message): void {
		if (isset($this->logger) === true) {
			$this->logger->debug(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
		}
	}//end debugTenancy()

	/**
	 * Get default organisation UUID from config
	 *
	 * This method provides a fallback for when OrganisationMapper is not available.
	 * Prefer using OrganisationMapper::getDefaultOrganisationFromConfig() when possible.
	 *
	 * @return string|null Default organisation UUID or null if not set
	 */
	protected function getDefaultOrganisationUuid(): ?string {
		// Prefer using OrganisationMapper if available.
		if (isset($this->organisationMapper) === true) {
			$organisationMapper = $this->organisationMapper;
			// @psalm-suppress UndefinedMethod
			return $organisationMapper->getDefaultOrganisationFromConfig();
		}

		// Fallback to direct config access if mapper not available.
		if (isset($this->appConfig) === false) {
			return null;
		}

		return $this->defaultOrganisationFromAppConfig(appConfig: $this->appConfig);
	}//end getDefaultOrganisationUuid()

	/**
	 * The default organisation as the app config stores it, newer key first.
	 *
	 * @param IAppConfig $appConfig The host's app config.
	 *
	 * @return string|null Default organisation UUID or null if not set
	 */
	private function defaultOrganisationFromAppConfig(IAppConfig $appConfig): ?string {
		// Try direct config key (newer format).
		$defaultOrg = $appConfig->getValueString('openregister', 'defaultOrganisation', '');
		if (empty($defaultOrg) === false) {
			return $defaultOrg;
		}

		// Try nested organisation config (legacy format).
		$organisationConfig = $appConfig->getValueString('openregister', 'organisation', '');
		if (empty($organisationConfig) === true) {
			return null;
		}

		$storedData = json_decode($organisationConfig, true);
		return ($storedData['default_organisation'] ?? null);
	}//end defaultOrganisationFromAppConfig()

	/**
	 * Get active organisation UUIDs (active + all parents)
	 *
	 * Returns array of organisation UUIDs that the current user can access.
	 * Includes the active organisation and all parent organisations in the hierarchy.
	 * Falls back to default organisation if no active organisation is set.
	 * Used for filtering queries to allow access to parent resources.
	 *
	 * @return (mixed|null|string)[] Array of organisation UUIDs
	 *
	 * @psalm-return array{0?: mixed|null|string,...}
	 */
	protected function getActiveOrganisationUuids(): array {
		$activeOrgUuid = $this->getActiveOrganisationUuid();
		if ($activeOrgUuid === null) {
			return [];
		}

		// If we have OrganisationMapper, get the full hierarchy (active + parents).
		if (isset($this->organisationMapper) === true) {
			$uuids = $this->organisationHierarchy(activeOrgUuid: $activeOrgUuid);
			if (empty($uuids) === false) {
				return $uuids;
			}
		}

		// Fall back to just the active organisation.
		return [$activeOrgUuid];
	}//end getActiveOrganisationUuids()

	/**
	 * The active organisation and its parents, or an empty list when the lookup fails.
	 *
	 * @param string $activeOrgUuid The active organisation.
	 *
	 * @return array The hierarchy, empty on failure.
	 */
	private function organisationHierarchy(string $activeOrgUuid): array {
		try {
			$organisationMapper = $this->organisationMapper;
			// @psalm-suppress UndefinedMethod
			return $organisationMapper->getOrganisationHierarchy($activeOrgUuid);
		} catch (\Exception $e) {
			// The caller falls back to just the active org.
			if (isset($this->logger) === true) {
				$this->logger->warning(
					message: '[MultiTenancyTrait] Failed to get organisation hierarchy: ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__, 'activeOrgUuid' => $activeOrgUuid]
				);
			}

			return [];
		}
	}//end organisationHierarchy()

	/**
	 * Get the current user ID.
	 *
	 * @return string|null The current user ID or null if no user is logged in
	 */
	protected function getCurrentUserId(): ?string {
		if (isset($this->userSession) === false) {
			return null;
		}

		$user = $this->userSession->getUser();
		if (($user !== null) === false) {
			return null;
		}

		return $user->getUID();
	}//end getCurrentUserId()

	/**
	 * Check if the current user is an admin.
	 *
	 * @return bool True if the current user is an admin, false otherwise
	 */
	protected function isCurrentUserAdmin(): bool {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			return false;
		}

		if (isset($this->groupManager) === false) {
			return false;
		}

		return $this->groupManager->isAdmin($userId);
	}//end isCurrentUserAdmin()
}//end trait
