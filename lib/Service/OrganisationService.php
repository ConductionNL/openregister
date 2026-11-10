<?php

/**
 * OpenRegister Organisation Service
 *
 * This file contains the service class for managing organisations and multi-tenancy.
 * Handles user-organisation relationships, session management, and organisational context.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * OrganisationService
 *
 * Manages multi-tenancy through organisations, handling user-organisation relationships,
 * session management for active organisation, and ensuring proper organisational context.
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Organisation management requires comprehensive multi-tenancy methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex multi-tenancy and permission logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires multiple Nextcloud services for user and group management
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */
class OrganisationService implements OrganisationServiceInterface {
	/**
	 * App name for user configuration storage
	 */
	private const APP_NAME = 'openregister';

	/**
	 * Configuration key for active organisation UUID
	 */
	private const CONFIG_ACTIVE_ORGANISATION = 'active_organisation';

	/**
	 * Session key for storing user's organisations array (cache only)
	 */
	private const SESSION_USER_ORGANISATIONS = 'openregister_user_organisations';

	/**
	 * Session key for storing active organisation (cache only)
	 */
	private const SESSION_ACTIVE_ORGANISATION = 'openregister_active_organisation';

	/**
	 * Session key for storing active organisation cache timestamp
	 */
	private const SESSION_ACTIVE_ORGANISATION_TIMESTAMP = 'openregister_active_organisation_timestamp';

	/**
	 * Cache timeout for organisations in seconds (15 minutes)
	 */
	private const CACHE_TIMEOUT = 900;

	/**
	 * The organisation setter for each plain cached field, in the order they are restored.
	 *
	 * @var array<string, string>
	 */
	private const CACHED_ORGANISATION_SETTERS = [
		'id'          => 'setId',
		'uuid'        => 'setUuid',
		'name'        => 'setName',
		'description' => 'setDescription',
		'owner'       => 'setOwner',
		'users'       => 'setUsers',
	];

	/**
	 * Configuration key for the system identifier used as `_owner` when a write
	 * happens with no active user session (cron jobs, background workers, etc.).
	 *
	 * @see self::getSystemUserId()
	 */
	public const CONFIG_SYSTEM_USER_ID = 'systemUserId';

	/**
	 * Configuration key for the comma-separated list of Nextcloud groups that
	 * are allowed to read system-owned rows in addition to the `admin` group.
	 *
	 * @see self::getSystemReaderGroups()
	 */
	public const CONFIG_SYSTEM_READER_GROUPS = 'systemReaderGroups';

	/**
	 * Default system identifier persisted as `_owner` when no user session is
	 * active. The double-underscore prefix is rejected by Nextcloud's user-ID
	 * validator, guaranteeing this identifier cannot collide with a real user.
	 */
	public const SYSTEM_USER_ID_DEFAULT = '__system__';

	/**
	 * Static cache for default organisation (shared across all instances)
	 *
	 * @var Organisation|null
	 */
	private static ?Organisation $defaultOrgCache = null;

	/**
	 * Timestamp when default organisation was cached
	 *
	 * @var integer|null
	 */
	private static ?int $defaultOrgCacheTs = null;

	/**
	 * Request-level in-memory cache for user organisations (avoids repeated DB queries within same request)
	 *
	 * @var array<string, Organisation[]>
	 */
	private static array $userOrgsCache = [];

	/**
	 * Organisation mapper for database operations
	 *
	 * @var OrganisationMapperInterface
	 */
	private OrganisationMapperInterface $organisationMapper;

	/**
	 * App config for storing user preferences
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * User session for getting current user
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Session interface for storing organisation data
	 *
	 * @var ISession
	 */
	private ISession $session;

	/**
	 * Configuration interface for persistent user settings
	 *
	 * @var IConfig
	 */
	private IConfig $config;

	/**
	 * Group manager for accessing Nextcloud groups
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * User manager for accessing Nextcloud users
	 *
	 * @var IUserManager
	 */
	private IUserManager $userManager;

	/**
	 * Logger for debugging and error tracking
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Settings service for application configuration
	 *
	 * @var SettingsServiceInterface|null
	 */
	private ?SettingsServiceInterface $settingsService = null;

	/**
	 * Organisations named by an organisation context, by uuid (null = not found).
	 *
	 * @var array<string, Organisation|null>
	 */
	private array $contextOrganisations = [];

	/**
	 * OrganisationService constructor
	 *
	 * @param OrganisationMapperInterface $organisationMapper Organisation database mapper
	 * @param IUserSession $userSession User session service
	 * @param ISession $session Session storage service for caching
	 * @param IConfig $config Configuration service for persistent storage
	 * @param IAppConfig $appConfig App configuration service
	 * @param IGroupManager $groupManager Group manager service
	 * @param IUserManager $userManager User manager service
	 * @param LoggerInterface $logger Logger service
	 * @param SettingsServiceInterface|null $settingsService Settings service (optional)
	 */
	public function __construct(
		OrganisationMapperInterface $organisationMapper,
		IUserSession $userSession,
		ISession $session,
		IConfig $config,
		IAppConfig $appConfig,
		IGroupManager $groupManager,
		IUserManager $userManager,
		LoggerInterface $logger,
		?SettingsServiceInterface $settingsService = null,
	) {
		$this->organisationMapper = $organisationMapper;
		$this->userSession = $userSession;
		$this->session = $session;
		$this->config = $config;
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->settingsService = $settingsService;
	}//end __construct()

	/**
	 * Ensure default organisation exists, create if needed
	 * Uses static application-level caching for performance optimization
	 *
	 * @return Organisation The default organisation
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function ensureDefaultOrganisation(): Organisation {
		// Check static cache first (shared across all instances).
		if (self::$defaultOrgCache !== null && self::$defaultOrgCacheTs !== null) {
			$age = time() - self::$defaultOrgCacheTs;
			if ($age < self::CACHE_TIMEOUT) {
				$this->logger->debug(
					message: '[OrganisationService] Retrieved default organisation from static cache',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'cacheAge' => $age,
					]
				);
				return self::$defaultOrgCache;
			}
		}

		// Cache miss or expired - fetch from database.
		$defaultOrg = $this->fetchDefaultOrganisationFromDatabase();

		// Cache the result.
		$this->cacheDefaultOrganisation(organisation: $defaultOrg);

		return $defaultOrg;
	}//end ensureDefaultOrganisation()

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
	public function getOrganisationSettingsOnly(): array {
		try {
			$organisationConfig = $this->appConfig->getValueString('openregister', 'organisation', '');

			$organisationData = [];
			$organisationData = [
				'default_organisation' => null,
				'auto_create_default_organisation' => true,
			];
			if (empty($organisationConfig) === false) {
				$storedData = json_decode($organisationConfig, true);
				$organisationData = [
					'default_organisation' => $storedData['default_organisation'] ?? null,
					'auto_create_default_organisation' => $storedData['auto_create_default_organisation'] ?? true,
				];
			}

			return [
				'organisation' => $organisationData,
			];
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve Organisation settings: ' . $e->getMessage());
		}//end try
	}//end getOrganisationSettingsOnly()

	/**
	 * Get default organisation UUID from settings
	 *
	 * @return string|null Default organisation UUID or null if not set
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getDefaultOrganisationUuid(): ?string {
		try {
			// First try the direct config key (newer format).
			$defaultOrg = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
			if (empty($defaultOrg) === false) {
				return $defaultOrg;
			}

			// Fall back to nested organisation config (legacy format).
			$settings = $this->getOrganisationSettingsOnly();
			return $settings['organisation']['default_organisation'] ?? null;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[OrganisationService] Failed to get default organisation UUID: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end getDefaultOrganisationUuid()

	/**
	 * Fetch default organisation from database (cache miss fallback)
	 *
	 * @return Organisation The default organisation
	 */
	private function fetchDefaultOrganisationFromDatabase(): Organisation {
		$defaultOrgUuid = $this->configuredDefaultOrganisationUuid();

		try {
			// No UUID in settings: create a new default organisation.
			if ($defaultOrgUuid === null) {
				return $this->provisionDefaultOrganisation();
			}

			return $this->findOrReplaceDefaultOrganisation(uuid: $defaultOrgUuid);
		} catch (Exception $e) {
			$this->logDefaultOrganisationFailure(exception: $e);
			throw $e;
		}
	}//end fetchDefaultOrganisationFromDatabase()

	/**
	 * The default organisation UUID, from the settings service when there is one.
	 *
	 * @return string|null The configured UUID, or null when none is configured.
	 */
	private function configuredDefaultOrganisationUuid(): ?string {
		// Try to get default organisation UUID from settings.
		$defaultOrgUuid = $this->getDefaultOrganisationUuid();
		if ($this->settingsService !== null) {
			$defaultOrgUuid = $this->settingsService->getDefaultOrganisationUuid();
		}

		return $defaultOrgUuid;
	}//end configuredDefaultOrganisationUuid()

	/**
	 * The configured default organisation, or a new one when its UUID no longer exists.
	 *
	 * An existing organisation is returned as it is: its admin setup was done at creation time.
	 *
	 * @param string $uuid The configured default organisation UUID.
	 *
	 * @return Organisation The default organisation.
	 */
	private function findOrReplaceDefaultOrganisation(string $uuid): Organisation {
		try {
			return $this->organisationMapper->findByUuid($uuid);
		} catch (DoesNotExistException $e) {
			// UUID in settings doesn't exist, create new default.
			return $this->provisionDefaultOrganisation();
		}
	}//end findOrReplaceDefaultOrganisation()

	/**
	 * Create a new default organisation, record it as the default and give the admins their rights.
	 *
	 * @return Organisation The new, saved default organisation.
	 */
	private function provisionDefaultOrganisation(): Organisation {
		$defaultOrg = $this->createDefaultOrganisation();

		// Store in settings.
		if ($this->settingsService !== null) {
			$this->settingsService->setDefaultOrganisationUuid($defaultOrg->getUuid());
		}

		$this->setDefaultOrganisationId(uuid: $defaultOrg->getUuid());

		return $this->grantAdminsOnNewDefaultOrganisation(defaultOrg: $defaultOrg);
	}//end provisionDefaultOrganisation()

	/**
	 * Add every admin user and the admin group's RBAC rights to a just-created default organisation.
	 *
	 * @param Organisation $defaultOrg The just-created default organisation.
	 *
	 * @return Organisation The updated organisation.
	 */
	private function grantAdminsOnNewDefaultOrganisation(Organisation $defaultOrg): Organisation {
		foreach ($this->getAdminGroupUsers() as $adminUserId) {
			if ($defaultOrg->hasUser($adminUserId) === false) {
				$defaultOrg->addUser($adminUserId);
			}
		}

		$authorization = $defaultOrg->getAuthorization();
		if ($this->hasAdminGroupInAuthorization(authorization: $authorization) === false) {
			$defaultOrg = $this->addAdminGroupToAuthorization(organisation: $defaultOrg);
		}

		return $this->organisationMapper->update($defaultOrg);
	}//end grantAdminsOnNewDefaultOrganisation()

	/**
	 * Log a failure to fetch or create the default organisation.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return void
	 */
	private function logDefaultOrganisationFailure(Exception $exception): void {
		$this->logger->error(
			message: '[OrganisationService] Failed to fetch or create default organisation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);
	}//end logDefaultOrganisationFailure()

	/**
	 * Create the default organisation, or use the one already holding its slug
	 *
	 * The organisation holding the slug is returned as it is; it is not rewritten.
	 *
	 * @return Organisation The default organisation
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisationservice-must-resolve-per-user-organisation-membership-and-active-context
	 */
	private function createDefaultOrganisation(): Organisation {
		try {
			return $this->createOrganisation(
				name: 'Default Organisation',
				description: 'Auto-generated default organisation',
				addCurrentUser: false
			);
		} catch (OrganisationSlugConflictException $e) {
			return $this->organisationMapper->findBySlug($e->getSlug());
		}
	}//end createDefaultOrganisation()

	/**
	 * Cache default organisation in static memory for performance
	 *
	 * @param Organisation $organisation The default organisation to cache
	 *
	 * @return void
	 */
	private function cacheDefaultOrganisation(Organisation $organisation): void {
		self::$defaultOrgCache = $organisation;
		self::$defaultOrgCacheTs = time();

		$this->logger->debug(
			message: '[OrganisationService] Cached default organisation in static memory',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'organisationUuid' => $organisation->getUuid(),
				'organisationName' => $organisation->getName(),
			]
		);
	}//end cacheDefaultOrganisation()

	/**
	 * Get the current user
	 *
	 * @return IUser|null The current user or null if not logged in
	 */
	private function getCurrentUser(): ?IUser {
		return $this->userSession->getUser();
	}//end getCurrentUser()

	/**
	 * Get organisations for the current user
	 *
	 * @param bool $_useCache Whether to use session cache (temporarily disabled)
	 *
	 * @return Organisation[]
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Cache parameter reserved for future implementation
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flag controls caching behavior
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getUserOrganisations(bool $_useCache = true): array {
		$user = $this->getCurrentUser();
		if ($user === null) {
			return [];
		}

		$userId = $user->getUID();

		// Check request-level in-memory cache (no serialization issues).
		if ($_useCache === true && isset(self::$userOrgsCache[$userId]) === true) {
			return self::$userOrgsCache[$userId];
		}

		$organisations = $this->organisationMapper->findByUserId($userId);

		// If user has no organisations, add them to default.
		if ($organisations === []) {
			$defaultOrg = $this->ensureDefaultOrganisation();
			$defaultOrg->addUser($userId);
			$this->organisationMapper->update($defaultOrg);
			$organisations = [$defaultOrg];
		}

		self::$userOrgsCache[$userId] = $organisations;

		return $organisations;
	}//end getUserOrganisations()

	/**
	 * Get the active organisation for the current user
	 * Uses session caching to avoid repeated database calls for RBAC performance
	 *
	 * @param array|null $preloadedOrgs Pre-loaded organisations to avoid extra queries.
	 *
	 * @return Organisation|null The active organisation or null.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	public function getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation {
		// Code acting for an organisation answers with that organisation,
		// whoever holds the session.
		$contextUuid = OrganisationContext::current();
		if ($contextUuid !== null) {
			return $this->findContextOrganisation(uuid: $contextUuid);
		}

		$user = $this->getCurrentUser();
		if ($user === null) {
			return null;
		}

		return $this->activeOrganisationOfUser(userId: $user->getUID(), preloadedOrgs: $preloadedOrgs);
	}//end getActiveOrganisation()

	/**
	 * A user's active organisation: from the session cache while it is fresh, else from the database.
	 *
	 * @param string     $userId        The user.
	 * @param array|null $preloadedOrgs Pre-loaded organisations to avoid extra queries.
	 *
	 * @return Organisation|null The active organisation or null.
	 */
	private function activeOrganisationOfUser(string $userId, ?array $preloadedOrgs): ?Organisation {
		// Check session cache first for performance.
		$cached = $this->freshCachedActiveOrganisation(userId: $userId);
		if ($cached !== null) {
			return $cached;
		}

		// Cache miss or expired - fetch from database.
		$organisation = $this->fetchActiveOrganisationFromDatabase(userId: $userId, preloadedOrgs: $preloadedOrgs);

		// Cache the result if we have an organisation.
		if ($organisation !== null) {
			$this->cacheActiveOrganisation(organisation: $organisation, userId: $userId);
		}

		return $organisation;
	}//end activeOrganisationOfUser()

	/**
	 * The user's active organisation from the session cache, when it is there and not expired.
	 *
	 * @param string $userId The user.
	 *
	 * @return Organisation|null The reconstructed organisation, or null on a miss or an expired entry.
	 */
	private function freshCachedActiveOrganisation(string $userId): ?Organisation {
		$cachedOrganisation = $this->session->get(self::SESSION_ACTIVE_ORGANISATION . '_' . $userId);
		$cacheTimestamp = $this->session->get(self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP . '_' . $userId);
		if ($cachedOrganisation === null || $cacheTimestamp === null) {
			return null;
		}

		$age = time() - $cacheTimestamp;
		if ($age >= self::CACHE_TIMEOUT) {
			return null;
		}

		// Reconstruct organisation from cached data.
		return $this->reconstructOrganisationFromCache(cachedData: $cachedOrganisation);
	}//end freshCachedActiveOrganisation()

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
	public function findContextOrganisation(string $uuid): ?Organisation {
		if (array_key_exists($uuid, $this->contextOrganisations) === true) {
			return $this->contextOrganisations[$uuid];
		}

		try {
			$organisation = $this->organisationMapper->findByUuid($uuid);
		} catch (DoesNotExistException $e) {
			$organisation = null;
		}

		$this->contextOrganisations[$uuid] = $organisation;

		return $organisation;
	}//end findContextOrganisation()

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
	public function setActiveOrganisation(string $organisationUuid): bool {
		$user = $this->getCurrentUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$userId = $user->getUID();

		// Verify user belongs to this organisation.
		try {
			$organisation = $this->organisationMapper->findByUuid($organisationUuid);
		} catch (DoesNotExistException $e) {
			throw new Exception('Organisation not found');
		}

		if ($organisation->hasUser($userId) === false) {
			throw new Exception('User does not belong to this organisation');
		}

		// Set in user configuration (persistent across sessions).
		$this->config->setUserValue(
			userId: $userId,
			appName: self::APP_NAME,
			key: self::CONFIG_ACTIVE_ORGANISATION,
			value: $organisationUuid
		);

		// Clear cached organisations and active organisation to force refresh.
		$orgCacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
		$this->session->remove($orgCacheKey);
		$this->clearActiveOrganisationCache(userId: $userId);

		// Cache the new active organisation immediately.
		$this->cacheActiveOrganisation(organisation: $organisation, userId: $userId);

		$this->logger->debug(
			message: '[OrganisationService] Set active organisation in user config',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'userId' => $userId,
				'organisationUuid' => $organisationUuid,
				'organisationName' => $organisation->getName(),
			]
		);

		return true;
	}//end setActiveOrganisation()

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
	public function joinOrganisation(string $organisationUuid, ?string $targetUserId = null): bool {
		// Get current user (for authentication).
		$currentUser = $this->getCurrentUser();
		if ($currentUser === null) {
			throw new Exception('No user logged in');
		}

		// Determine which user to add.
		// If targetUserId is provided, use it; otherwise use current user.
		$userId = $targetUserId ?? $currentUser->getUID();

		// Validate that target user exists if different from current user.
		if ($targetUserId !== null && $targetUserId !== $currentUser->getUID()) {
			$this->assertUserExists(userId: $targetUserId);
		}

		return $this->addMembership(organisationUuid: $organisationUuid, userId: $userId);
	}//end joinOrganisation()

	/**
	 * Refuse a user id that names no Nextcloud user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return void
	 *
	 * @throws Exception When the user does not exist.
	 */
	private function assertUserExists(string $userId): void {
		if ($this->userManager->get($userId) === null) {
			throw new Exception('Target user not found');
		}
	}//end assertUserExists()

	/**
	 * Add a user to an organisation and drop their cached organisation list.
	 *
	 * @param string $organisationUuid The organisation.
	 * @param string $userId           The user to add.
	 *
	 * @return bool Always true; a failure throws.
	 *
	 * @throws Exception When the organisation does not exist.
	 */
	private function addMembership(string $organisationUuid, string $userId): bool {
		try {
			$this->organisationMapper->addUserToOrganisation(organisationUuid: $organisationUuid, userId: $userId);

			// Clear cached organisations to force refresh for the affected user.
			$this->forgetCachedUserOrganisations(userId: $userId);

			return true;
		} catch (DoesNotExistException $e) {
			throw new Exception('Organisation not found');
		}
	}//end addMembership()

	/**
	 * Drop a user's cached organisation list from the session.
	 *
	 * @param string $userId The user.
	 *
	 * @return void
	 */
	private function forgetCachedUserOrganisations(string $userId): void {
		$cacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
		$this->session->remove($cacheKey);
	}//end forgetCachedUserOrganisations()

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
	public function leaveOrganisation(string $organisationUuid, ?string $targetUserId = null): bool {
		$currentUser = $this->getCurrentUser();
		if ($currentUser === null) {
			throw new Exception('No user logged in');
		}

		// Determine which user to remove.
		// If targetUserId is provided, use it; otherwise use current user.
		$userId = $targetUserId ?? $currentUser->getUID();

		// If removing current user, check if it's their last organisation.
		if ($userId === $currentUser->getUID()) {
			$this->assertNotLastOrganisation();
		}

		return $this->removeMembership(organisationUuid: $organisationUuid, userId: $userId);
	}//end leaveOrganisation()

	/**
	 * Prevent the current user from leaving all organisations.
	 *
	 * @return void
	 *
	 * @throws Exception When the user belongs to one organisation or none.
	 */
	private function assertNotLastOrganisation(): void {
		// Don't use cache.
		$userOrgs = $this->getUserOrganisations(_useCache: false);
		if (count($userOrgs) <= 1) {
			throw new Exception('Cannot leave last organisation');
		}
	}//end assertNotLastOrganisation()

	/**
	 * Remove a user from an organisation, move them off it when it was active, and drop their cached list.
	 *
	 * @param string $organisationUuid The organisation.
	 * @param string $userId           The user to remove.
	 *
	 * @return bool Always true; a failure throws.
	 *
	 * @throws Exception When the organisation does not exist.
	 */
	private function removeMembership(string $organisationUuid, string $userId): bool {
		try {
			$this->organisationMapper->removeUserFromOrganisation(organisationUuid: $organisationUuid, userId: $userId);
			$this->resetActiveOrganisationIfLeft(organisationUuid: $organisationUuid, userId: $userId);

			// Clear cached organisations to force refresh.
			$this->forgetCachedUserOrganisations(userId: $userId);

			return true;
		} catch (DoesNotExistException $e) {
			throw new Exception('Organisation not found');
		}
	}//end removeMembership()

	/**
	 * When the organisation left was the active one, clear it and let the oldest remaining one take over.
	 *
	 * @param string $organisationUuid The organisation left.
	 * @param string $userId           The user who left.
	 *
	 * @return void
	 */
	private function resetActiveOrganisationIfLeft(string $organisationUuid, string $userId): void {
		$activeOrg = $this->getActiveOrganisation();
		if ($activeOrg === null || $activeOrg->getUuid() !== $organisationUuid) {
			return;
		}

		// Clear active organisation cache and config.
		$this->clearActiveOrganisationCache(userId: $userId);
		$this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);

		// Set another organisation as active (this will auto-set the oldest remaining org).
		$this->getActiveOrganisation();
	}//end resetActiveOrganisationIfLeft()

	/**
	 * Generate a URL-friendly slug from a name
	 *
	 * @param string $name The name to slugify
	 *
	 * @return string The generated slug
	 */
	private function generateSlug(string $name): string {
		// Convert to lowercase.
		$slug = strtolower($name);

		// Replace spaces and special characters with hyphens.
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

		// Remove leading/trailing hyphens.
		$slug = trim($slug, '-');

		// Limit length to 100 characters.
		$slug = substr($slug, 0, 100);

		return $slug;
	}//end generateSlug()

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
	 * @SuppressWarnings(PHPMD.StaticAccess)         Uuid::isValid is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag controls whether to add current user to organisation
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Organisation creation requires multiple validation steps
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function createOrganisation(
		string $name,
		string $description = '',
		bool $addCurrentUser = true,
		string $uuid = '',
	): Organisation {
		$user = $this->getCurrentUser();
		$userId = null;

		// Validate UUID if provided.
		if ($uuid !== '' && Uuid::isValid($uuid) === false) {
			throw new Exception('Invalid UUID format. UUID must be a 32-character hexadecimal string.');
		}

		$organisation = new Organisation();
		$organisation->setName($name);
		$organisation->setDescription($description);

		// Auto-generate slug from name if not provided.
		$organisation->setSlug($this->generateSlug(name: $name));

		// Set UUID if provided.
		if ($uuid !== '') {
			$organisation->setUuid($uuid);
		}

		if ($user !== null) {
			$userId = $user->getUID();
			if ($addCurrentUser === true) {
				$organisation->setOwner($userId);
				$organisation->setUsers([$userId]);
			}
		}

		// Add all admin group users to the organisation.
		$organisation = $this->addAdminUsersToOrganisation(organisation: $organisation);

		// Add admin group to RBAC authorization with full permissions.
		$organisation = $this->addAdminGroupToAuthorization(organisation: $organisation);

		$saved = $this->organisationMapper->save($organisation);

		// If there's no default organisation set, make this one the default.
		$defaultOrgId = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
		if ($defaultOrgId === '') {
			$this->appConfig->setValueString('openregister', 'defaultOrganisation', $saved->getUuid());
		}

		// Clear cached organisations and active organisation cache to force refresh.
		if ($addCurrentUser === true && $userId !== null) {
			$cacheKey = self::SESSION_USER_ORGANISATIONS . '_' . $userId;
			$this->session->remove($cacheKey);
			$this->clearActiveOrganisationCache(userId: $userId);
		}

		$this->logger->info(
			message: '[OrganisationService] Created new organisation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'organisationUuid' => $saved->getUuid(),
				'name' => $name,
				'owner' => $userId,
				'adminUsersAdded' => $this->getAdminGroupUsers(),
				'uuidProvided' => $uuid !== '',
			]
		);

		return $saved;
	}//end createOrganisation()

	/**
	 * Check if current user has access to an organisation
	 *
	 * @param string $organisationUuid The organisation UUID to check
	 *
	 * @return bool True if user has access
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function hasAccessToOrganisation(string $organisationUuid): bool {
		try {
			$organisation = $this->organisationMapper->findByUuid($organisationUuid);
			$user = $this->getCurrentUser();

			if ($user === null) {
				return false;
			}

			// Admin users have access to all organisations.
			if ($this->groupManager->isAdmin($user->getUID()) === true) {
				return true;
			}

			return $organisation->hasUser($user->getUID());
		} catch (DoesNotExistException $e) {
			return false;
		}
	}//end hasAccessToOrganisation()

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
	public function isOrganisationAdmin(string $organisationUuid, ?string $userId = null): bool {
		$userId = $this->givenOrCurrentUserId(userId: $userId);
		if ($userId === null || $userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->ownsOrganisation(organisationUuid: $organisationUuid, userId: $userId);
	}//end isOrganisationAdmin()

	/**
	 * The given user id, or the signed-in user's id when none is given.
	 *
	 * @param string|null $userId The given user id.
	 *
	 * @return string|null The uid, or null when none is given and nobody is signed in.
	 */
	private function givenOrCurrentUserId(?string $userId): ?string {
		if ($userId !== null) {
			return $userId;
		}

		$user = $this->getCurrentUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end givenOrCurrentUserId()

	/**
	 * Whether a user is the owner of an organisation; an organisation that does not exist has none.
	 *
	 * @param string $organisationUuid The organisation.
	 * @param string $userId           The user.
	 *
	 * @return bool True when the user owns it.
	 */
	private function ownsOrganisation(string $organisationUuid, string $userId): bool {
		try {
			$organisation = $this->organisationMapper->findByUuid($organisationUuid);
		} catch (DoesNotExistException $e) {
			return false;
		}

		return $organisation->getOwner() === $userId;
	}//end ownsOrganisation()

	/**
	 * Get user organisation statistics
	 *
	 * @return array Statistics with total count, active organisation, and results list.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getUserOrganisationStats(): array {
		$user = $this->getCurrentUser();
		if ($user === null) {
			return ['total' => 0, 'active' => null, 'results' => []];
		}

		$organisations = $this->getUserOrganisations();
		$activeOrg = $this->getActiveOrganisation(preloadedOrgs: $organisations);

		return [
			'total' => count($organisations),
			'active' => $activeOrg?->jsonSerialize(),
			'results' => array_map(
				function ($org) {
					return $org->jsonSerialize();
				},
				$organisations
			),
		];
	}//end getUserOrganisationStats()

	/**
	 * Clear default organisation cache (public method for external use)
	 *
	 * @return void
	 *
	 * @spec exclude Trivial static default-org cache reset; no business logic.
	 */
	public function clearDefaultOrganisationCache(): void {
		self::$defaultOrgCache = null;
		self::$defaultOrgCacheTs = null;

		$this->logger->debug(
			message: '[OrganisationService] Cleared default organisation static cache',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end clearDefaultOrganisationCache()

	/**
	 * Clear all organisation cache for current user
	 *
	 * @param bool $clearPersistent Whether to also clear persistent active organisation setting
	 *
	 * @return bool True if cache cleared
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag controls whether to clear persistent settings
	 *
	 * @spec exclude Cache-invalidation helper (request/session/static caches + optional persistent setting); no business logic.
	 */
	public function clearCache(bool $clearPersistent = false): bool {
		$user = $this->getCurrentUser();
		if ($user === null) {
			return false;
		}

		$userId = $user->getUID();

		// Clear request-level in-memory cache.
		unset(self::$userOrgsCache[$userId]);

		// Clear session-based cache for organisations and active organisation.
		$this->session->remove(self::SESSION_USER_ORGANISATIONS . '_' . $userId);
		$this->clearActiveOrganisationCache(userId: $userId);

		// Clear static default organisation cache as well.
		$this->clearDefaultOrganisationCache();

		// Clear persistent configuration if requested.
		if ($clearPersistent === true) {
			$this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);
		}

		return true;
	}//end clearCache()

	/**
	 * Get all users in the admin group
	 *
	 * @return string[] Array of user IDs in the admin group
	 *
	 * @psalm-return array<string>
	 */
	private function getAdminGroupUsers(): array {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			$this->logger->warning(
				message: '[OrganisationService] Admin group not found',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		$adminUsers = $adminGroup->getUsers();
		return array_map(
			function ($user) {
				return $user->getUID();
			},
			$adminUsers
		);
	}//end getAdminGroupUsers()

	/**
	 * Add all admin group users to an organisation
	 *
	 * @param Organisation $organisation The organisation to add admin users to
	 *
	 * @return Organisation The updated organisation
	 */
	private function addAdminUsersToOrganisation(Organisation $organisation): Organisation {
		$adminUsers = $this->getAdminGroupUsers();

		// Check if this is the default organisation.
		$defaultOrgId = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
		$isDefaultOrg = ($organisation->getUuid() === $defaultOrgId);

		foreach ($adminUsers as $adminUserId) {
			$organisation->addUser($adminUserId);
		}

		// Clear default organisation cache if we modified the default organisation.
		if ($isDefaultOrg === true) {
			$this->clearDefaultOrganisationCache();
		}

		$this->logger->info(
			message: '[OrganisationService] Added admin users to organisation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'organisationUuid' => $organisation->getUuid(),
				'organisationName' => $organisation->getName(),
				'adminUsersAdded' => $adminUsers,
				'isDefault' => $isDefaultOrg,
				'clearedCache' => $isDefaultOrg,
			]
		);

		return $organisation;
	}//end addAdminUsersToOrganisation()

	/**
	 * Add admin group to organisation authorization with full permissions
	 *
	 * @param Organisation $organisation The organisation to add admin group permissions to
	 *
	 * @return Organisation The updated organisation
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC permission setup requires branches for each entity type and action
	 */
	private function addAdminGroupToAuthorization(Organisation $organisation): Organisation {
		$authorization = $organisation->getAuthorization();
		$adminGroupId = 'admin';

		// Add admin group to all CRUD permissions for all entity types.
		$entityTypes = ['register', 'schema', 'object', 'view', 'agent', 'configuration', 'application'];
		foreach ($entityTypes as $entityType) {
			if (($authorization[$entityType] ?? null) !== null && is_array($authorization[$entityType]) === true) {
				foreach (['create', 'read', 'update', 'delete'] as $action) {
					$actionAuth = $authorization[$entityType][$action] ?? null;
					if ($actionAuth !== null && is_array($actionAuth) === true) {
						if (in_array($adminGroupId, $actionAuth, true) === false) {
							$authorization[$entityType][$action][] = $adminGroupId;
						}
					}
				}
			}
		}

		// Add admin group to special permissions.
		$specialPermissions = ['object_publish', 'agent_use', 'dashboard_view', 'llm_use'];
		foreach ($specialPermissions as $permission) {
			if (($authorization[$permission] ?? null) !== null && is_array($authorization[$permission]) === true) {
				if (in_array($adminGroupId, $authorization[$permission], true) === false) {
					$authorization[$permission][] = $adminGroupId;
				}
			}
		}

		$organisation->setAuthorization($authorization);

		$this->logger->info(
			message: '[OrganisationService] Added admin group to organisation RBAC authorization',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'organisationUuid' => $organisation->getUuid(),
				'organisationName' => $organisation->getName(),
				'adminGroupId' => $adminGroupId,
			]
		);

		return $organisation;
	}//end addAdminGroupToAuthorization()

	/**
	 * Check if admin group is already in authorization configuration
	 *
	 * @param array $authorization The authorization configuration to check
	 *
	 * @return bool True if admin group is found in any permission
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC check requires branches for each entity type and action
	 */
	private function hasAdminGroupInAuthorization(array $authorization): bool {
		$adminGroupId = 'admin';

		// Check all entity types.
		$entityTypes = ['register', 'schema', 'object', 'view', 'agent', 'configuration', 'application'];
		foreach ($entityTypes as $entityType) {
			if (($authorization[$entityType] ?? null) !== null && is_array($authorization[$entityType]) === true) {
				foreach (['create', 'read', 'update', 'delete'] as $action) {
					$actionAuth = $authorization[$entityType][$action] ?? null;
					if ($actionAuth !== null && is_array($actionAuth) === true) {
						if (in_array($adminGroupId, $actionAuth, true) === true) {
							return true;
						}
					}
				}
			}
		}

		// Check special permissions.
		$specialPermissions = ['object_publish', 'agent_use', 'dashboard_view', 'llm_use'];
		foreach ($specialPermissions as $permission) {
			if (($authorization[$permission] ?? null) !== null && is_array($authorization[$permission]) === true) {
				if (in_array($adminGroupId, $authorization[$permission], true) === true) {
					return true;
				}
			}
		}

		return false;
	}//end hasAdminGroupInAuthorization()

	/**
	 * Resolve an organisation through its merge chain
	 *
	 * A merge is recorded on the row that was merged AWAY, so a UUID held
	 * anywhere outside the mapper — a user's stored active organisation, a
	 * membership list, a federation peer's reference — can name an organisation
	 * that no longer owns anything. The resolver is bounded and cycle-guarded
	 * and never fails open; if the survivor cannot be loaded this returns the
	 * organisation it was handed, which is a real row.
	 *
	 * @param Organisation $organisation The (possibly merged-away) organisation.
	 *
	 * @return Organisation The surviving organisation, or the input if unresolvable.
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/tasks.md#3-merge-resolution
	 */
	private function followMerge(Organisation $organisation): Organisation {
		// The overwhelming majority of rows were never merged, and those need no
		// walk at all: the flag lives on the row that was merged AWAY.
		if ($organisation->isMerged() === false) {
			return $organisation;
		}

		$uuid = (string) $organisation->getUuid();
		if ($uuid === '') {
			return $organisation;
		}

		try {
			return $this->organisationMapper->findByUuidFollowingMerge(uuid: $uuid);
		} catch (Exception $e) {
			// An unresolvable survivor is a data defect, not a reason to hand
			// the caller nothing: the row we already have is real.
			$this->logger->warning(
				message: '[OrganisationService] Could not resolve merge target; keeping the organisation as stored',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'organisationUuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);
			return $organisation;
		}
	}//end followMerge()

	/**
	 * Write a followed merge back into the user's stored active organisation
	 *
	 * Following the merge on every read is correct but leaves the dead UUID in
	 * config forever, so every later read pays the walk. Persisting the survivor
	 * once makes the walk a one-off per user per merge.
	 *
	 * @param string       $userId       The user whose setting is being corrected.
	 * @param string       $storedUuid   The UUID currently in user config.
	 * @param Organisation $organisation The organisation the merge resolved to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-organisation-on-or/tasks.md#3-merge-resolution
	 */
	private function persistFollowedMerge(string $userId, string $storedUuid, Organisation $organisation): void {
		$survivorUuid = (string) $organisation->getUuid();
		if ($survivorUuid === '' || $survivorUuid === $storedUuid) {
			return;
		}

		$this->config->setUserValue(
			$userId,
			self::APP_NAME,
			self::CONFIG_ACTIVE_ORGANISATION,
			$survivorUuid
		);

		$this->logger->info(
			message: '[OrganisationService] Active organisation followed a merge',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'userId' => $userId,
				'from' => $storedUuid,
				'to' => $survivorUuid,
			]
		);
	}//end persistFollowedMerge()

	/**
	 * Fetch active organisation from database (cache miss fallback)
	 *
	 * The stored active organisation wins while the user is still a member; otherwise the
	 * oldest of the user's organisations becomes active, and a user without organisations
	 * joins the default organisation.
	 *
	 * @param string $userId The user ID to fetch active organisation for.
	 * @param array|null $preloadedOrgs Pre-loaded organisations to avoid extra queries.
	 *
	 * @return Organisation|null The active organisation or null
	 */
	private function fetchActiveOrganisationFromDatabase(string $userId, ?array $preloadedOrgs = null): ?Organisation {
		$stored = $this->findStoredActiveOrganisation(userId: $userId);
		if ($stored !== null) {
			return $stored;
		}

		$organisations = $preloadedOrgs ?? $this->getUserOrganisations();
		if (empty($organisations) === false) {
			return $this->activateOldestOrganisation(userId: $userId, organisations: $organisations);
		}

		return $this->activateDefaultOrganisation(userId: $userId);
	}//end fetchActiveOrganisationFromDatabase()

	/**
	 * The active organisation stored in user config, when it exists and the user is a member.
	 *
	 * A stored organisation that is gone, or that the user is no longer a member of, is
	 * cleared from config and cache.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return Organisation|null The stored active organisation, or null.
	 */
	private function findStoredActiveOrganisation(string $userId): ?Organisation {
		$activeUuid = $this->config->getUserValue(
			$userId,
			self::APP_NAME,
			self::CONFIG_ACTIVE_ORGANISATION,
			''
		);
		if ($activeUuid === '') {
			return null;
		}

		try {
			// Follow the merge chain BEFORE the UUID is used as a scope. A
			// merged-away organisation no longer owns its data, so using the
			// stored UUID literally would run every scoped query for this
			// user under a tenant boundary that no longer applies.
			$organisation = $this->followMerge(organisation: $this->organisationMapper->findByUuid($activeUuid));

			// Membership of the SURVIVOR: a user the merge did not carry over
			// falls through to their own organisation list, which fails closed.
			if ($organisation->hasUser($userId) === true) {
				$this->persistFollowedMerge(userId: $userId, storedUuid: $activeUuid, organisation: $organisation);
				return $organisation;
			}

			$reason = 'invalid';
		} catch (DoesNotExistException $e) {
			$reason = 'non-existent';
		}

		$this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION);
		$this->clearActiveOrganisationCache(userId: $userId);
		$this->logger->debug(
			message: "[OrganisationService] Cleared {$reason} active organisation",
			context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId, 'organisationUuid' => $activeUuid]
		);

		return null;
	}//end findStoredActiveOrganisation()

	/**
	 * Make the oldest of the user's organisations active and store it in user config.
	 *
	 * @param string         $userId        The user ID.
	 * @param Organisation[] $organisations The user's organisations, not empty.
	 *
	 * @return Organisation The organisation made active.
	 */
	private function activateOldestOrganisation(string $userId, array $organisations): Organisation {
		usort(
			$organisations,
			function ($a, $b) {
				return $a->getCreated() <=> $b->getCreated();
			}
		);

		// The user's membership list can still name a merged-away
		// organisation, so the auto-pick resolves too — otherwise the very
		// first login after a merge writes the dead UUID back into config.
		$oldestOrg = $this->followMerge(organisation: $organisations[0]);
		$this->config->setUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION, $oldestOrg->getUuid());

		$this->logger->info(
			message: '[OrganisationService] Auto-set active organisation to oldest',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'userId' => $userId,
				'organisationUuid' => $oldestOrg->getUuid(),
				'organisationName' => $oldestOrg->getName(),
			]
		);

		return $oldestOrg;
	}//end activateOldestOrganisation()

	/**
	 * Make the default organisation active for a user without organisations, adding the user to it.
	 *
	 * This ensures all users always have at least one organisation.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return Organisation|null The default organisation, or null when it could not be set.
	 */
	private function activateDefaultOrganisation(string $userId): ?Organisation {
		try {
			$defaultOrg = $this->ensureDefaultOrganisation();
			if ($defaultOrg->hasUser($userId) === false) {
				$defaultOrg->setUsers(array_merge($defaultOrg->getUsers() ?? [], [$userId]));
				$this->organisationMapper->update($defaultOrg);
				$this->logger->info(
					message: '[OrganisationService] Added user to default organisation',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'userId' => $userId,
						'organisationUuid' => $defaultOrg->getUuid(),
						'organisationName' => $defaultOrg->getName(),
					]
				);
			}

			$this->config->setUserValue($userId, self::APP_NAME, self::CONFIG_ACTIVE_ORGANISATION, $defaultOrg->getUuid());
			$this->logger->info(
				message: '[OrganisationService] Auto-set active organisation to default',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'userId' => $userId,
					'organisationUuid' => $defaultOrg->getUuid(),
					'organisationName' => $defaultOrg->getName(),
				]
			);

			return $defaultOrg;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[OrganisationService] Failed to set default organisation for user',
				context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId, 'error' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end activateDefaultOrganisation()

	/**
	 * Cache active organisation in session for performance
	 *
	 * @param Organisation $organisation The organisation to cache
	 * @param string $userId The user ID to cache for
	 *
	 * @return void
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function cacheActiveOrganisation(Organisation $organisation, string $userId): void {
		$cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
		$timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP . '_' . $userId;

		// Store organisation data as array to avoid serialization issues.
		// Convert DateTime objects to ISO strings for proper caching.
		$orgData = [
			'id' => $organisation->getId(),
			'uuid' => $organisation->getUuid(),
			'name' => $organisation->getName(),
			'description' => $organisation->getDescription(),
			'owner' => $organisation->getOwner(),
			'users' => $organisation->getUsers(),
			'created' => $this->formatCreatedDate(organisation: $organisation),
			'updated' => $this->formatUpdatedDate(organisation: $organisation),
		];

		$this->session->set($cacheKey, $orgData);
		$this->session->set($timestampKey, time());

		$this->logger->debug(
			message: '[OrganisationService] Cached active organisation in session',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'userId' => $userId,
				'organisationUuid' => $organisation->getUuid(),
				'organisationName' => $organisation->getName(),
			]
		);
	}//end cacheActiveOrganisation()

	/**
	 * Reconstruct Organisation object from cached data
	 *
	 * @param array $cachedData The cached organisation data
	 *
	 * @return Organisation The reconstructed organisation object
	 *
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function reconstructOrganisationFromCache(array $cachedData): Organisation {
		$organisation = new Organisation();

		// Set all properties from cached data.
		foreach (self::CACHED_ORGANISATION_SETTERS as $key => $setter) {
			if (($cachedData[$key] ?? null) !== null) {
				$organisation->$setter($cachedData[$key]);
			}
		}

		$this->restoreCachedTimestamps(organisation: $organisation, cachedData: $cachedData);

		return $organisation;
	}//end reconstructOrganisationFromCache()

	/**
	 * Set the created and updated timestamps of a reconstructed organisation.
	 *
	 * @param Organisation $organisation The organisation being reconstructed.
	 * @param array        $cachedData   The cached organisation data.
	 *
	 * @return void
	 */
	private function restoreCachedTimestamps(Organisation $organisation, array $cachedData): void {
		$created = $this->cachedDateTime(value: $cachedData['created'] ?? null);
		if ($created !== null) {
			$organisation->setCreated($created);
		}

		$updated = $this->cachedDateTime(value: $cachedData['updated'] ?? null);
		if ($updated !== null) {
			$organisation->setUpdated($updated);
		}
	}//end restoreCachedTimestamps()

	/**
	 * A cached timestamp as a DateTime: a string is parsed, a DateTime kept, anything else dropped.
	 *
	 * @param mixed $value The cached value.
	 *
	 * @return DateTime|null The timestamp, or null when there is none to set.
	 */
	private function cachedDateTime(mixed $value): ?DateTime {
		// Convert string back to DateTime if needed.
		if (is_string($value) === true) {
			return new DateTime($value);
		}

		if ($value instanceof DateTime) {
			return $value;
		}

		return null;
	}//end cachedDateTime()

	/**
	 * Clear active organisation cache for a specific user
	 *
	 * @param string $userId The user ID to clear cache for
	 *
	 * @return void
	 */
	private function clearActiveOrganisationCache(string $userId): void {
		$cacheKey = self::SESSION_ACTIVE_ORGANISATION . '_' . $userId;
		$timestampKey = self::SESSION_ACTIVE_ORGANISATION_TIMESTAMP . '_' . $userId;

		$this->session->remove($cacheKey);
		$this->session->remove($timestampKey);

		$this->logger->debug(
			message: '[OrganisationService] Cleared active organisation cache',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'userId' => $userId,
			]
		);
	}//end clearActiveOrganisationCache()

	/**
	 * Get the organisation UUID to use for creating new entities
	 * Uses the active organisation or falls back to default
	 *
	 * @return null|string The organisation UUID to use
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getOrganisationForNewEntity(): ?string {
		// One debug line, recording the OUTCOME and which branch produced it.
		// This runs on every object save, and used to emit four info lines to
		// answer a single question — "called", "found", "no active org",
		// "got default". Entry traces like those describe the code rather than
		// the system: they say a function was entered, which the next line
		// already implies, and at info they crowd out the events an operator is
		// actually scanning for.
		$activeOrg = $this->getActiveOrganisation();

		if ($activeOrg !== null) {
			$this->logger->debug(
				message: '[OrganisationService] New entity assigned to active organisation ' . $activeOrg->getUuid(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $activeOrg->getUuid();
		}

		$defaultOrg = $this->ensureDefaultOrganisation();
		$this->logger->debug(
			message: '[OrganisationService] No active organisation; new entity assigned to default '
				. $defaultOrg->getUuid(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $defaultOrg->getUuid();
	}//end getOrganisationForNewEntity()

	/**
	 * Get the default organisation UUID from config
	 *
	 * @return null|string The UUID of the default organisation, or null if not set
	 *
	 * @spec exclude Trivial app-config getter for the defaultOrganisation key; no business logic.
	 */
	public function getDefaultOrganisationId(): ?string {
		$defaultOrgId = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
		if ($defaultOrgId !== '') {
			return $defaultOrgId;
		}

		return null;
	}//end getDefaultOrganisationId()

	/**
	 * Get the system identifier used as `_owner` for writes without an active
	 * user session (cron jobs, background workers, internal service calls).
	 *
	 * Reads `openregister.systemUserId` from app-config; falls back to
	 * {@see self::SYSTEM_USER_ID_DEFAULT} when the key is unset or empty.
	 *
	 * The default identifier (`__system__`) cannot collide with a real user
	 * because Nextcloud's user-ID validator rejects identifiers starting with
	 * a double-underscore.
	 *
	 * @return string The system identifier (never empty).
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	public function getSystemUserId(): string {
		$configured = $this->appConfig->getValueString(
			self::APP_NAME,
			self::CONFIG_SYSTEM_USER_ID,
			''
		);

		if ($configured !== '') {
			return $configured;
		}

		return self::SYSTEM_USER_ID_DEFAULT;
	}//end getSystemUserId()

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
	public function getSystemReaderGroups(): array {
		$configured = $this->appConfig->getValueString(
			self::APP_NAME,
			self::CONFIG_SYSTEM_READER_GROUPS,
			''
		);

		if ($configured === '') {
			return [];
		}

		$parts = explode(',', $configured);
		$groups = [];
		foreach ($parts as $part) {
			$trimmed = trim($part);
			if ($trimmed !== '') {
				$groups[] = $trimmed;
			}
		}

		return $groups;
	}//end getSystemReaderGroups()

	/**
	 * Format created date for JSON serialization
	 *
	 * @param Organisation $organisation Organisation object
	 *
	 * @return string|null Formatted date or null
	 */
	private function formatCreatedDate(Organisation $organisation): ?string {
		$created = $organisation->getCreated();
		if ($created !== null) {
			return $created->format('Y-m-d H:i:s');
		}

		return null;
	}//end formatCreatedDate()

	/**
	 * Format updated date for JSON serialization
	 *
	 * @param Organisation $organisation Organisation object
	 *
	 * @return string|null Formatted date or null
	 */
	private function formatUpdatedDate(Organisation $organisation): ?string {
		$updated = $organisation->getUpdated();
		if ($updated !== null) {
			return $updated->format('Y-m-d H:i:s');
		}

		return null;
	}//end formatUpdatedDate()

	/**
	 * Set the default organisation UUID in config
	 *
	 * @param string $uuid The UUID of the organisation to set as default
	 *
	 * @return void
	 *
	 * @spec exclude Trivial app-config setter + cache bust; no business logic.
	 */
	public function setDefaultOrganisationId(string $uuid): void {
		$this->appConfig->setValueString('openregister', 'defaultOrganisation', $uuid);
		$this->clearDefaultOrganisationCache();
	}//end setDefaultOrganisationId()

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
	public function getUserActiveOrganisations(): array {
		$activeOrg = $this->getActiveOrganisation();

		if ($activeOrg === null) {
			$this->logger->debug(
				message: '[OrganisationService] No active organisation found for user',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		// Start with the active organisation UUID.
		$orgUuids = [$activeOrg->getUuid()];

		// Get all parent organisations recursively.
		$parents = $this->organisationMapper->findParentChain($activeOrg->getUuid());

		// Merge active UUID with parent UUIDs.
		$orgUuids = array_merge($orgUuids, $parents);

		$this->logger->debug(
			message: '[OrganisationService] Retrieved active organisations (including parents)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'activeOrg' => $activeOrg->getUuid(),
				'activeOrgName' => $activeOrg->getName(),
				'parents' => $parents,
				'totalOrganisations' => count($orgUuids),
				'allUuids' => $orgUuids,
			]
		);

		return $orgUuids;
	}//end getUserActiveOrganisations()
}//end class
