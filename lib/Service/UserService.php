<?php

/**
 * UserService
 *
 * This service handles all user-related business logic including user data retrieval,
 * updates, and profile management. It centralizes user operations and provides
 * a clean interface for controllers and other services.
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

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail\ActionWindow;
use OCA\OpenRegister\Db\AuditTrail\ActorTrailQuery;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\OpenRegister\Service\User\AccountPropertyChange;
use OCA\OpenRegister\Service\User\ActivityQuery;
use OCA\OpenRegister\Service\User\ApiTokenRequest;
use OCA\OpenRegister\Service\User\AvatarUpload;
use OCA\OpenRegister\Service\User\PasswordChange;
use OCA\OpenRegister\Service\User\UserDirectory;
use OCA\OpenRegister\Service\User\UserPlatform;
use OCA\OpenRegister\Service\User\UserStorage;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IConfig;
use OCP\IUser;
use RuntimeException;

/**
 * Service class for handling user-related operations
 *
 * This service provides methods for retrieving and updating user information,
 * including standard NextCloud user properties and custom profile fields.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
 */
class UserService implements UserServiceInterface {

	/**
	 * Request-scoped cache for organisation stats to avoid duplicate lookups
	 *
	 * Populated on first call to buildUserDataArray() and reused on subsequent calls
	 * within the same request. Reset when organisation is switched.
	 *
	 * @var array|null Cached organisation stats or null if not yet fetched
	 */
	private ?array $cachedOrgStats = null;

	/**
	 * App name constant for config storage
	 */
	private const APP_NAME = 'openregister';

	/**
	 * Maximum number of API tokens per user
	 */
	private const MAX_TOKENS = 10;

	/**
	 * Export rate limit in seconds (1 hour)
	 */
	private const EXPORT_RATE_LIMIT = 3600;

	/**
	 * Default notification preferences
	 */
	private const DEFAULT_NOTIFICATION_PREFS = [
		'objectChanges' => true,
		'assignments' => true,
		'organisationChanges' => true,
		'systemAnnouncements' => true,
		'emailDigest' => 'daily',
	];

	/**
	 * Valid email digest frequencies
	 */
	private const VALID_DIGEST_FREQUENCIES = ['none', 'daily', 'weekly'];

	/**
	 * Allowed avatar MIME types
	 */
	private const ALLOWED_AVATAR_TYPES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	];

	/**
	 * Maximum avatar file size in bytes (5 MB)
	 */
	private const MAX_AVATAR_SIZE = 5242880;

	/**
	 * Methods absent from OCP\IUser that some implementations carry, with the value used when absent.
	 *
	 * @var array<string, mixed>
	 */
	private const OPTIONAL_USER_METHODS = [
		'getEmailVerified' => null,
		'getAvatarScope' => 'contacts',
		'canChangeMailAddress' => false,
	];

	/**
	 * UserService constructor
	 *
	 * @param UserDirectory                $directory           The user, group, account and avatar stores.
	 * @param UserPlatform                 $platform            The session, event dispatcher, language factory and logger.
	 * @param UserStorage                  $storage             The config, database, audit trail and secure random.
	 * @param OrganisationServiceInterface $organisationService The organisation service.
	 */
	public function __construct(
		private readonly UserDirectory $directory,
		private readonly UserPlatform $platform,
		private readonly UserStorage $storage,
		private readonly OrganisationServiceInterface $organisationService,
	) {
	}//end __construct()

	/**
	 * Get current authenticated user
	 *
	 * @return IUser|null The current user or null if not authenticated
	 */
	public function getCurrentUser(): ?IUser {
		return $this->platform->userSession->getUser();
	}//end getCurrentUser()

	/**
	 * Build comprehensive user data array
	 *
	 * @param IUser $user The user object to build data for
	 *
	 * @return array The comprehensive user data array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function buildUserDataArray(IUser $user): array {
		$userGroups = $this->directory->groupManager->getUserGroups($user);
		$groupNames = array_values(array_map(fn ($group) => $group->getGID(), $userGroups));

		$quota = $this->buildQuotaInformation(user: $user);

		[$language, $locale] = $this->getLanguageAndLocale(user: $user);

		$additionalInfo = $this->getAdditionalProfileInfo(user: $user);

		$optional = $this->optionalUserValues(user: $user);

		$emailVerified = $optional['getEmailVerified'];

		$avatarScope = $optional['getAvatarScope'];

		// OCP\IUser declares getLastLogin(), getBackendClassName(),
		// canChangeDisplayName(), canChangePassword() and canChangeAvatar(), so
		// the method_exists() guards that used to wrap these could never be
		// false and their defaults were unreachable. The two guards that remain
		// (getAvatarScope above, canChangeMailAddress below) are NOT decoration:
		// those methods are absent from the interface and only present on some
		// implementations.
		$lastLogin = $user->getLastLogin();
		$backend = $user->getBackendClassName();
		$canChangeDisplayName = $user->canChangeDisplayName();

		$canChangeEmail = $optional['canChangeMailAddress'];

		$canChangePassword = $user->canChangePassword();
		$canChangeAvatar = $user->canChangeAvatar();

		$result = [
			'uid' => $user->getUID(),
			'displayName' => $user->getDisplayName(),
			'email' => $user->getEMailAddress(),
			'emailVerified' => $emailVerified,
			'enabled' => $user->isEnabled(),
			'quota' => $quota,
			'avatarScope' => $avatarScope,
			'lastLogin' => $lastLogin,
			'backend' => $backend,
			'subadmin' => [],
			'groups' => $groupNames,
			'language' => $language,
			'locale' => $locale,
			'backendCapabilities' => [
				'displayName' => $canChangeDisplayName,
				'email' => $canChangeEmail,
				'password' => $canChangePassword,
				'avatar' => $canChangeAvatar,
			],
		];

		$result = array_merge($result, $additionalInfo);

		$result['firstName'] = $result['firstName'] ?? null;
		$result['lastName'] = $result['lastName'] ?? null;
		$result['middleName'] = $result['middleName'] ?? null;
		// The Dutch alias `functie` used to be exposed alongside Nextcloud's own
		// `role`; both are `role` now, so the alias assignment is redundant.
		$result['role'] = $result['role'] ?? $additionalInfo['role'] ?? null;

		// Add organization information in the format expected by the frontend.
		// Frontend expects: { active: { uuid, naam, id, slug }, all: [...] }
		$result['organisations'] = $this->buildOrganisationsBlock(
			organisationStats: $this->getCachedOrganisationStats()
		);

		return $result;
	}//end buildUserDataArray()

	/**
	 * The results of the methods only some IUser implementations carry.
	 *
	 * Each method absent from the implementation yields its default from
	 * OPTIONAL_USER_METHODS.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array<string, mixed> The result (or default) per method name
	 */
	private function optionalUserValues(IUser $user): array {
		$values = self::OPTIONAL_USER_METHODS;
		foreach (array_keys($values) as $method) {
			if (method_exists($user, $method) === true) {
				$values[$method] = $user->$method();
			}
		}

		return $values;
	}//end optionalUserValues()

	/**
	 * The organisation stats of the current user, cached for the request.
	 *
	 * Use cached result if available (avoids ~20-30 redundant queries when called twice in updateMe).
	 *
	 * @return array The organisation stats (total, active, results)
	 */
	private function getCachedOrganisationStats(): array {
		if ($this->cachedOrgStats !== null) {
			return $this->cachedOrgStats;
		}

		try {
			$organisationStats = $this->organisationService->getUserOrganisationStats();
		} catch (\Exception $e) {
			$this->platform->logger->warning('Failed to get user organisation stats: ' . $e->getMessage());
			$organisationStats = ['total' => 0, 'active' => null, 'results' => []];
		}

		$this->cachedOrgStats = $organisationStats;
		return $organisationStats;
	}//end getCachedOrganisationStats()

	/**
	 * Build the organisations structure expected by the frontend.
	 *
	 * @param array $organisationStats The organisation stats (total, active, results)
	 *
	 * @return array The active, all, total and available entries
	 */
	private function buildOrganisationsBlock(array $organisationStats): array {
		return [
			'active' => $this->withDutchOrganisationName(org: $organisationStats['active'] ?? null),
			'all' => array_map($this->withDutchOrganisationName(...), $organisationStats['results'] ?? []),
			'total' => $organisationStats['total'] ?? 0,
			'available' => true,
		];
	}//end buildOrganisationsBlock()

	/**
	 * Transform organisation data to include 'naam' field (Dutch) alongside 'name'.
	 *
	 * @param array|null $org The organisation, or null
	 *
	 * @return array|null The organisation with 'naam', or null
	 */
	private function withDutchOrganisationName(?array $org): ?array {
		if ($org === null) {
			return null;
		}

		// Add 'naam' field that mirrors 'name' for Dutch frontend compatibility.
		$org['naam'] = $org['name'] ?? null;
		return $org;
	}//end withDutchOrganisationName()

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
	public function updateUserProperties(IUser $user, array $data): array {
		$result = [
			'success' => true,
			'message' => 'User properties updated successfully',
			'organisation_updated' => false,
		];

		// Collect old user data before updates for event dispatching.
		$oldData = $this->buildUserDataArray(user: $user);

		// Handle organization switching if requested.
		if (isset($data['activeOrganisation']) === true && is_string($data['activeOrganisation']) === true) {
			// Invalidate cached org stats since the active organisation is changing.
			$this->cachedOrgStats = null;

			$organisationResult = $this->organisationService->setActiveOrganisation(
				$data['activeOrganisation']
			);
			$result['organisation_updated'] = $organisationResult;
			$result['organisation_message'] = 'Failed to update active organization';
			if ($organisationResult === true) {
				$result['organisation_message'] = 'Active organization updated successfully';
			}

			// Remove the organization field from data to prevent it from being processed as a user property.
			unset($data['activeOrganisation']);
		}

		$this->updateStandardUserProperties(user: $user, data: $data);

		$this->updateProfileProperties(user: $user, data: $data);

		// Collect new user data after updates.
		// Organisation stats are cached from the first buildUserDataArray() call,
		// saving ~20-30 queries on this second call.
		$newData = $this->buildUserDataArray(user: $user);

		// Determine which fields changed.
		$changes = $this->determineChangedFields(oldData: $oldData, newData: $newData);

		// Dispatch event if there are changes.
		if (empty($changes) === false) {
			$event = new UserProfileUpdatedEvent(
				user: $user,
				oldData: $oldData,
				newData: $newData,
				changes: $changes
			);
			$this->platform->eventDispatcher->dispatchTyped($event);

			$this->platform->logger->debug(
				message: '[UserService] UserService: Dispatched UserProfileUpdatedEvent',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'userId' => $user->getUID(),
					'changes' => $changes,
				]
			);
		}

		return $result;
	}//end updateUserProperties()

	/**
	 * Determine which fields have changed between old and new user data.
	 *
	 * @param array $oldData The old user data before updates.
	 * @param array $newData The new user data after updates.
	 *
	 * @return array Array of field names that have changed.
	 */
	private function determineChangedFields(array $oldData, array $newData): array {
		$changes = [];

		// Fields to check for changes.
		$fieldsToCheck = [
			'displayName',
			'email',
			'firstName',
			'lastName',
			'middleName',
			'phone',
			'address',
			'website',
			'twitter',
			'fediverse',
			'organisation',
			'role',
			'headline',
			'biography',
			'language',
			'locale',
			'role',
		];

		foreach ($fieldsToCheck as $field) {
			$oldValue = $oldData[$field] ?? null;
			$newValue = $newData[$field] ?? null;

			if ($oldValue !== $newValue) {
				$changes[] = $field;
			}
		}

		return $changes;
	}//end determineChangedFields()

	/**
	 * Get custom name fields for a user
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Array containing name fields
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function getCustomNameFields(IUser $user): array {
		$userId = $user->getUID();

		$firstName = $this->storage->config->getUserValue($userId, 'core', 'firstName', '');
		if ($firstName === '') {
			$firstName = null;
		}

		$lastName = $this->storage->config->getUserValue($userId, 'core', 'lastName', '');
		if ($lastName === '') {
			$lastName = null;
		}

		$middleName = $this->storage->config->getUserValue($userId, 'core', 'middleName', '');
		if ($middleName === '') {
			$middleName = null;
		}

		return [
			'firstName' => $firstName,
			'lastName' => $lastName,
			'middleName' => $middleName,
		];
	}//end getCustomNameFields()

	/**
	 * Set custom name fields for a user
	 *
	 * @param IUser $user The user object
	 * @param array $nameFields Array containing name field values
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function setCustomNameFields(IUser $user, array $nameFields): void {
		$userId = $user->getUID();
		$allowedFields = ['firstName', 'lastName', 'middleName'];

		foreach ($allowedFields as $field) {
			if (isset($nameFields[$field]) === true) {
				$value = (string)$nameFields[$field];
				$this->storage->config->setUserValue($userId, 'core', $field, $value);
			}
		}
	}//end setCustomNameFields()

	/**
	 * Build quota information for a user
	 *
	 * @param IUser $user The user object
	 *
	 * @return array The quota information array
	 */
	private function buildQuotaInformation(IUser $user): array {
		try {
			// OCP\IUser declares getQuota(), so the 'none' default was dead.
			$userQuota = $user->getQuota();

			$userId = $user->getUID();

			$usedSpace = $this->resolveUsedSpace(user: $user, userId: $userId);

			return [
				'free' => $userQuota,
				'used' => $usedSpace,
				'total' => $userQuota,
				'relative' => $this->relativeQuotaUsage(userQuota: $userQuota, usedSpace: $usedSpace),
			];
		} catch (\Exception $e) {
			$this->platform->logger->warning(
				message: '[UserService] Failed to build quota information for user: ' . $user->getUID(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
				]
			);

			return [
				'free' => 'none',
				'used' => 0,
				'total' => 'none',
				'relative' => 0,
			];
		}//end try
	}//end buildQuotaInformation()

	/**
	 * The used space of a user: the native value when the backend has one.
	 *
	 * @param IUser  $user   The user object
	 * @param string $userId The user ID
	 *
	 * @return mixed The used space in bytes
	 */
	private function resolveUsedSpace(IUser $user, string $userId): mixed {
		try {
			// Default to memory-safe method, override if native method exists.
			$usedSpace = $this->getUsedSpaceMemorySafe(userId: $userId);
			if (method_exists($user, 'getUsedSpace') === true) {
				$usedSpace = $user->getUsedSpace();
			}

			return $usedSpace;
		} catch (\Exception $quotaException) {
			$this->platform->logger->debug(
				message: '[UserService] User quota calculation failed for user: ' . $userId,
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $quotaException->getMessage(),
				]
			);

			return $this->getUsedSpaceMemorySafe(userId: $userId);
		}//end try
	}//end resolveUsedSpace()

	/**
	 * The used space as a percentage of a numeric, positive quota; 0 otherwise.
	 *
	 * @param mixed $userQuota The quota ('none', 'unlimited' or a byte count)
	 * @param mixed $usedSpace The used space in bytes
	 *
	 * @return int|float The percentage, rounded to two decimals, or 0
	 */
	private function relativeQuotaUsage(mixed $userQuota, mixed $usedSpace): int|float {
		if ($userQuota === 'none' || $userQuota === 'unlimited' || is_numeric($userQuota) === false) {
			return 0;
		}

		$totalBytes = (int)$userQuota;
		if ($totalBytes <= 0) {
			return 0;
		}

		return round(($usedSpace / $totalBytes) * 100, 2);
	}//end relativeQuotaUsage()

	/**
	 * Get used space in a memory-safe way
	 *
	 * @param string $userId The user ID
	 *
	 * @return int The used space in bytes or 0 if cannot be determined safely
	 */
	private function getUsedSpaceMemorySafe(string $userId): int {
		try {
			$currentMemoryUsage = memory_get_usage(true);

			if ($currentMemoryUsage > 128 * 1024 * 1024) {
				$this->platform->logger->warning(
					message: '[UserService] Memory usage too high for quota calculation',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'user' => $userId,
						'memory_usage' => $currentMemoryUsage,
					]
				);
				return 0;
			}

			$size = $this->fetchHomeStorageRootSize(userId: $userId);
			if ($size !== null) {
				return $size;
			}

			$this->platform->logger->info(
				message: '[UserService] Using fallback quota calculation for user: ' . $userId,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return 0;
		} catch (\Throwable $e) {
			// This is a best-effort, memory-safe helper: any failure (a DB error, a
			// driver-level Error, or a misconfigured query builder) must degrade to 0
			// rather than propagate — the contract is "the used space, or 0 if it cannot
			// be determined safely". Catching \Throwable (not just \Exception) keeps a
			// stray \Error from escaping into the caller and turning a quota lookup into a 500.
			$this->platform->logger->warning(
				message: '[UserService] Memory-safe quota calculation failed for user: ' . $userId,
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
				]
			);
			return 0;
		}//end try
	}//end getUsedSpaceMemorySafe()

	/**
	 * The size of the user's home storage root, from the file cache.
	 *
	 * @param string $userId The user ID
	 *
	 * @return int|null The size in bytes, or null when no numeric size is stored
	 */
	private function fetchHomeStorageRootSize(string $userId): ?int {
		$query = $this->storage->db->getQueryBuilder();

		// Used space lives in oc_filecache.size for the storage root (path = ''),
		// NOT in oc_storages (which has no size column). Join the file cache to the
		// user's home mount on the numeric storage id: oc_filecache.storage and
		// oc_mounts.storage_id are both BIGINT, so this avoids the
		// "operator does not exist: character varying = bigint" error that a
		// storages.id (varchar) = mounts.storage_id (bigint) join throws on PostgreSQL.
		$query->select('f.size')
			->from('filecache', 'f')
			->innerJoin('f', 'mounts', 'm', $query->expr()->eq('f.storage', 'm.storage_id'))
			->where($query->expr()->eq('m.user_id', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('f.path', $query->createNamedParameter('')))
			->andWhere($query->expr()->eq('m.mount_point', $query->createNamedParameter('/' . $userId . '/')))
			->setMaxResults(1);

		// `execute()` was removed from QueryBuilder in Nextcloud 34. It was
		// the read/write-agnostic entry point; a SELECT now goes through
		// executeQuery(). Calling the old name threw "Call to undefined
		// method", which the caller's catch swallowed into a warning and a 0 —
		// so used space silently read as empty for every user instead of
		// failing loudly.
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row !== false && isset($row['size']) === true && is_numeric($row['size']) === true) {
			return (int)$row['size'];
		}

		return null;
	}//end fetchHomeStorageRootSize()

	/**
	 * Get language and locale with proper fallbacks
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Array containing language and locale
	 */
	private function getLanguageAndLocale(IUser $user): array {
		$language = $this->resolveLanguage(user: $user);
		$locale = $this->resolveLocale(user: $user, language: $language);

		return [$language, $locale];
	}//end getLanguageAndLocale()

	/**
	 * The user's language, or the detected one when the user has none set.
	 *
	 * @param IUser $user The user object
	 *
	 * @return mixed The language, or '' when the implementation has no getLanguage()
	 */
	private function resolveLanguage(IUser $user): mixed {
		if (method_exists($user, 'getLanguage') === false) {
			return '';
		}

		$language = $user->getLanguage();
		if (empty($language) === true) {
			$language = $this->platform->l10nFactory->findLanguage();
		}

		return $language;
	}//end resolveLanguage()

	/**
	 * The user's locale, defaulted from the language when the user has none set.
	 *
	 * @param IUser $user     The user object
	 * @param mixed $language The resolved language
	 *
	 * @return mixed The locale, or '' when the implementation has no getLocale()
	 */
	private function resolveLocale(IUser $user, mixed $language): mixed {
		if (method_exists($user, 'getLocale') === false) {
			return '';
		}

		$locale = $user->getLocale();
		if (empty($locale) === false || empty($language) === true) {
			return $locale;
		}

		// Default to language_LANGUAGE format, override for English.
		if ($language === 'en') {
			return 'en_US';
		}

		return $language . '_' . strtoupper($language);
	}//end resolveLocale()

	/**
	 * Get additional profile information from various sources
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Additional profile information
	 */
	private function getAdditionalProfileInfo(IUser $user): array {
		try {
			$additionalInfo = $this->getAccountManagerPropertiesSelectively(user: $user);
		} catch (\Exception $e) {
			$additionalInfo = $this->getSettingsProfileInfo(user: $user, exception: $e);
		}

		$customNameFields = $this->getCustomNameFields(user: $user);
		$additionalInfo = array_merge($additionalInfo, $customNameFields);

		$userId = $user->getUID();
		$organizationUuid = $this->storage->config->getUserValue($userId, 'core', 'organisation', '');
		if (empty($organizationUuid) === false) {
			$additionalInfo['organisation'] = $organizationUuid;
		}

		// Fallback: check user config if AccountManager's 'role' is empty.
		if (empty($additionalInfo['role']) === true) {
			$role = $this->getStoredRole(userId: $userId);
			if (empty($role) === false) {
				$additionalInfo['role'] = $role;
			}
		}

		return $additionalInfo;
	}//end getAdditionalProfileInfo()

	/**
	 * Profile information from the legacy settings keys, used when AccountManager fails.
	 *
	 * @param IUser      $user      The user object
	 * @param \Exception $exception Why AccountManager failed
	 *
	 * @return array The non-empty phone, website and twitter values
	 */
	private function getSettingsProfileInfo(IUser $user, \Exception $exception): array {
		$this->platform->logger->warning(
			message: '[UserService] AccountManager failed for user: ' . $user->getUID(),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'exception' => $exception->getMessage(),
			]
		);

		$userId = $user->getUID();

		$additionalInfo = [];
		foreach (['phone', 'website', 'twitter'] as $field) {
			$value = $this->storage->config->getUserValue($userId, 'settings', $field, '');
			if (empty($value) === false) {
				$additionalInfo[$field] = $value;
			}
		}

		return $additionalInfo;
	}//end getSettingsProfileInfo()

	/**
	 * The role stored in user config: the new key first, then the old one.
	 *
	 * `functie` here is not a variable name, it is a STORED KEY — the preference
	 * row oc_preferences(appid='core', configkey='functie'). Renaming the read
	 * does not rename what is already in the table, so reading only the new key
	 * makes every job title saved before this release disappear, with no error.
	 * Read the new key first, then fall back to the old one.
	 *
	 * @param string $userId The user ID
	 *
	 * @return mixed The stored role, or ''
	 */
	private function getStoredRole(string $userId): mixed {
		$role = $this->storage->config->getUserValue($userId, 'core', 'role', '');
		if (empty($role) === true) {
			$role = $this->storage->config->getUserValue($userId, 'core', 'functie', '');
		}

		return $role;
	}//end getStoredRole()

	/**
	 * Get AccountManager properties selectively to reduce memory usage
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Profile information from AccountManager
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function getAccountManagerPropertiesSelectively(IUser $user): array {
		$additionalInfo = [];

		$account = $this->directory->accountManager->getAccount($user);

		$neededProperties = [
			IAccountManager::PROPERTY_PHONE => 'phone',
			IAccountManager::PROPERTY_ADDRESS => 'address',
			IAccountManager::PROPERTY_WEBSITE => 'website',
			IAccountManager::PROPERTY_TWITTER => 'twitter',
			IAccountManager::PROPERTY_FEDIVERSE => 'fediverse',
			IAccountManager::PROPERTY_ORGANISATION => 'organisation',
			IAccountManager::PROPERTY_ROLE => 'role',
			IAccountManager::PROPERTY_HEADLINE => 'headline',
			IAccountManager::PROPERTY_BIOGRAPHY => 'biography',
		];

		foreach ($neededProperties as $propertyName => $apiField) {
			try {
				// No `!== null` check: getProperty() throws rather than returning
				// null, and the surrounding catch already handles that.
				$value = $account->getProperty($propertyName)->getValue();
				if (empty($value) === false) {
					$additionalInfo[$apiField] = $value;
				}
			} catch (\Exception $e) {
				$this->platform->logger->debug(
					message: '[UserService] Failed to load account property: ' . $propertyName,
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'user' => $user->getUID(),
						'exception' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		return $additionalInfo;
	}//end getAccountManagerPropertiesSelectively()

	/**
	 * Update standard user properties
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updateStandardUserProperties(IUser $user, array $data): void {
		$this->updateDisplayName(user: $user, data: $data);
		$this->updateEmailAddress(user: $user, data: $data);
		$this->updatePassword(user: $user, data: $data);
		$this->updateLanguageAndLocale(user: $user, data: $data);
	}//end updateStandardUserProperties()

	/**
	 * Set the display name when given and the backend allows it.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updateDisplayName(IUser $user, array $data): void {
		if (isset($data['displayName']) === true
			&& $user->canChangeDisplayName() === true
		) {
			$user->setDisplayName($data['displayName']);
		}
	}//end updateDisplayName()

	/**
	 * Set the e-mail address when given and the backend allows it.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updateEmailAddress(IUser $user, array $data): void {
		if (isset($data['email']) === true
			&& method_exists($user, 'canChangeMailAddress') === true
			&& $user->canChangeMailAddress() === true
		) {
			$user->setEMailAddress($data['email']);
		}
	}//end updateEmailAddress()

	/**
	 * Set the password when given and the backend allows it.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updatePassword(IUser $user, array $data): void {
		if (isset($data['password']) === true
			&& $user->canChangePassword() === true
		) {
			$user->setPassword($data['password']);
		}
	}//end updatePassword()

	/**
	 * Set the language and the locale when given and the implementation supports it.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updateLanguageAndLocale(IUser $user, array $data): void {
		if (isset($data['language']) === true && method_exists($user, 'setLanguage') === true) {
			$user->setLanguage($data['language']);
		}

		if (isset($data['locale']) === true && method_exists($user, 'setLocale') === true) {
			$user->setLocale($data['locale']);
		}
	}//end updateLanguageAndLocale()

	/**
	 * Update profile properties via AccountManager and custom fields
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updateProfileProperties(IUser $user, array $data): void {
		try {
			$this->updateAccountProperties(user: $user, data: $data);
		} catch (\Exception $e) {
			$this->platform->logger->warning(
				message: '[UserService] Failed to update AccountManager properties for user: ' . $user->getUID(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		$nameFields = $this->extractNameFields(data: $data);
		if (empty($nameFields) === false) {
			$this->setCustomNameFields(user: $user, nameFields: $nameFields);
		}

		// Store 'role' in user config as fallback for the /me endpoint.
		if (isset($data['role']) === true) {
			$this->storage->config->setUserValue($user->getUID(), 'core', 'role', (string)$data['role']);
		}
	}//end updateProfileProperties()

	/**
	 * Write the given profile fields to the user's AccountManager account.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 */
	private function updateAccountProperties(IUser $user, array $data): void {
		$account = $this->directory->accountManager->getAccount($user);
		$accountUpdated = false;

		$standardFields = [
			'phone' => IAccountManager::PROPERTY_PHONE,
			'address' => IAccountManager::PROPERTY_ADDRESS,
			'website' => IAccountManager::PROPERTY_WEBSITE,
			'twitter' => IAccountManager::PROPERTY_TWITTER,
			'fediverse' => IAccountManager::PROPERTY_FEDIVERSE,
			'organisation' => IAccountManager::PROPERTY_ORGANISATION,
			'role' => IAccountManager::PROPERTY_ROLE,
			'headline' => IAccountManager::PROPERTY_HEADLINE,
			'biography' => IAccountManager::PROPERTY_BIOGRAPHY,
		];

		foreach ($standardFields as $apiField => $accountProperty) {
			if (isset($data[$apiField]) === false) {
				continue;
			}

			$value = (string)$data[$apiField];
			$change = new AccountPropertyChange(property: $accountProperty, value: $value);
			if ($this->writeAccountProperty(account: $account, change: $change) === true) {
				$accountUpdated = true;
			}
		}

		if ($accountUpdated === true) {
			$this->directory->accountManager->updateAccount($account);
		}
	}//end updateAccountProperties()

	/**
	 * Set one account property, creating it when the account lacks it.
	 *
	 * @param IAccount              $account The account to update
	 * @param AccountPropertyChange $change  The property and its new value
	 *
	 * @return bool Whether the account changed
	 */
	private function writeAccountProperty(IAccount $account, AccountPropertyChange $change): bool {
		$accountProperty = $change->property;
		$value = $change->value;

		// IAccount::getProperty() THROWS PropertyDoesNotExistException when
		// the property is absent — it never returns null. The `!== null`
		// test that used to guard this block was therefore always true,
		// which made the create-it fallback below UNREACHABLE: an unknown
		// property escaped to the outer catch and abandoned the whole
		// account update with a warning instead of being created. Catching
		// the exception here is what actually reaches that fallback.
		try {
			$property = $account->getProperty($accountProperty);
			if ($property->getValue() !== $value) {
				$property->setValue($value);
				return true;
			}

			return false;
		} catch (PropertyDoesNotExistException $e) {
			// Fall through to the create path below.
		}

		// Property doesn't exist, create it.
		$scope = $this->getDefaultPropertyScope(propertyName: $accountProperty);
		$verified = IAccountManager::NOT_VERIFIED;

		$account->setProperty(
			property: $accountProperty,
			value: $value,
			scope: $scope,
			verified: $verified
		);
		return true;
	}//end writeAccountProperty()

	/**
	 * The custom name fields present in the update data.
	 *
	 * @param array $data The data array containing updates
	 *
	 * @return array The firstName, lastName and middleName values that are set
	 */
	private function extractNameFields(array $data): array {
		$nameFields = [];
		foreach (['firstName', 'lastName', 'middleName'] as $field) {
			if (isset($data[$field]) === true) {
				$nameFields[$field] = $data[$field];
			}
		}

		return $nameFields;
	}//end extractNameFields()

	/**
	 * Get default property scope for account properties
	 *
	 * @param string $propertyName The property name
	 *
	 * @return string The default scope for the property
	 */
	private function getDefaultPropertyScope(string $propertyName): string {
		$scopeMap = [
			IAccountManager::PROPERTY_PHONE => IAccountManager::SCOPE_PRIVATE,
			IAccountManager::PROPERTY_ADDRESS => IAccountManager::SCOPE_PRIVATE,
			IAccountManager::PROPERTY_WEBSITE => IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::PROPERTY_TWITTER => IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::PROPERTY_FEDIVERSE => IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::PROPERTY_ORGANISATION => IAccountManager::SCOPE_LOCAL,
			IAccountManager::PROPERTY_ROLE => IAccountManager::SCOPE_LOCAL,
			IAccountManager::PROPERTY_HEADLINE => IAccountManager::SCOPE_LOCAL,
			IAccountManager::PROPERTY_BIOGRAPHY => IAccountManager::SCOPE_LOCAL,
		];

		return $scopeMap[$propertyName] ?? IAccountManager::SCOPE_PRIVATE;
	}//end getDefaultPropertyScope()

	/**
	 * Change the user's password after verifying the current one.
	 *
	 * @param IUser          $user   The user changing their password.
	 * @param PasswordChange $change The current password and the new one.
	 *
	 * @return array Result array with success status
	 *
	 * @throws RuntimeException If password change fails
	 *
	 * @spec openspec/specs/profile-actions/spec.md
	 */
	public function applyPasswordChange(IUser $user, PasswordChange $change): array {
		// Check backend capability.
		if ($user->canChangePassword() === false) {
			throw new RuntimeException(
				'Password changes are not supported by your authentication backend',
				409
			);
		}

		// Verify current password.
		$verifiedUser = $this->directory->userManager->checkPassword($user->getUID(), $change->currentPassword);
		if ($verifiedUser === false) {
			throw new RuntimeException('Current password is incorrect', 403);
		}

		// Set new password.
		$result = $user->setPassword($change->newPassword);
		if ($result === false) {
			throw new RuntimeException(
				'New password does not meet the password policy requirements',
				400
			);
		}

		return [
			'success' => true,
			'message' => 'Password updated successfully',
		];
	}//end applyPasswordChange()

	/**
	 * Store an uploaded image as the user's avatar.
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
	public function setAvatar(IUser $user, AvatarUpload $upload): array {
		// Check backend capability.
		if ($user->canChangeAvatar() === false) {
			throw new RuntimeException(
				'Avatar changes are not supported by your authentication backend',
				409
			);
		}

		// Validate file type.
		if (in_array($upload->mimeType, self::ALLOWED_AVATAR_TYPES, true) === false) {
			throw new RuntimeException(
				'Unsupported image format. Allowed: JPEG, PNG, GIF, WebP',
				400
			);
		}

		// Validate file size.
		if ($upload->size > self::MAX_AVATAR_SIZE) {
			throw new RuntimeException('Avatar image must be smaller than 5 MB', 400);
		}

		$userId = $user->getUID();
		$avatar = $this->directory->avatarManager->getAvatar($userId);
		$avatar->set($upload->data);

		return [
			'success' => true,
			'avatarUrl' => '/avatar/' . $userId . '/128',
		];
	}//end setAvatar()

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
	public function deleteAvatar(IUser $user): array {
		// Check backend capability.
		if ($user->canChangeAvatar() === false) {
			throw new RuntimeException(
				'Avatar changes are not supported by your authentication backend',
				409
			);
		}

		$avatar = $this->directory->avatarManager->getAvatar($user->getUID());
		$avatar->remove();

		return [
			'success' => true,
			'message' => 'Avatar removed',
		];
	}//end deleteAvatar()

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
	public function exportPersonalData(IUser $user): array {
		$userId = $user->getUID();

		// Check rate limit.
		$lastExport = $this->storage->config->getUserValue($userId, self::APP_NAME, 'last_export_time', '0');
		$timeSinceExport = time() - (int)$lastExport;

		if ($timeSinceExport < self::EXPORT_RATE_LIMIT) {
			$retryAfter = self::EXPORT_RATE_LIMIT - $timeSinceExport;
			throw new RuntimeException(
				json_encode(
					[
						'error' => 'Data export is limited to once per hour',
						'retry_after' => $retryAfter,
					]
				),
				429
			);
		}

		// Record export time.
		$this->storage->config->setUserValue($userId, self::APP_NAME, 'last_export_time', (string)time());

		// Build profile data.
		$profile = $this->buildUserDataArray(user: $user);

		// Get audit trail entries.
		$auditData = $this->storage->auditTrailMapper->findActorTrail(query: new ActorTrailQuery(userId: $userId, limit: 1000));
		$auditTrail = array_map(
			function ($entry) {
				return $entry->jsonSerialize();
			},
			$auditData['results']
		);

		return [
			'exportDate' => date('c'),
			'profile' => $profile,
			'organisations' => $profile['organisations'] ?? [],
			'objects' => [],
			'auditTrail' => $auditTrail,
		];
	}//end exportPersonalData()

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
	public function getNotificationPreferences(IUser $user): array {
		$userId = $user->getUID();
		$prefs = [];

		foreach (self::DEFAULT_NOTIFICATION_PREFS as $key => $defaultValue) {
			$stored = $this->storage->config->getUserValue($userId, self::APP_NAME, 'notification_' . $key, '');
			$prefs[$key] = $this->preferenceFromStored(stored: $stored, defaultValue: $defaultValue);
		}

		return $prefs;
	}//end getNotificationPreferences()

	/**
	 * One preference from its stored string: the default when unset, a boolean for a boolean default.
	 *
	 * @param string $stored       The stored value, '' when unset.
	 * @param mixed  $defaultValue The preference's default.
	 *
	 * @return mixed The preference value.
	 */
	private function preferenceFromStored(string $stored, mixed $defaultValue): mixed {
		if ($stored === '') {
			return $defaultValue;
		}

		// Convert string booleans.
		if ($defaultValue === true || $defaultValue === false) {
			return ($stored === 'true' || $stored === '1');
		}

		return $stored;
	}//end preferenceFromStored()

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
	public function setNotificationPreferences(IUser $user, array $prefs): array {
		$userId = $user->getUID();
		$this->assertValidEmailDigest(prefs: $prefs);

		// Store provided preferences.
		foreach ($prefs as $key => $value) {
			if (array_key_exists($key, self::DEFAULT_NOTIFICATION_PREFS) === true) {
				$this->storage->config->setUserValue($userId, self::APP_NAME, 'notification_' . $key, $this->preferenceToStore(value: $value));
			}
		}

		// Return complete preferences.
		return $this->getNotificationPreferences(user: $user);
	}//end setNotificationPreferences()

	/**
	 * Refuse an emailDigest that is not one of the valid frequencies.
	 *
	 * @param array $prefs The preference values to update.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the digest frequency is invalid.
	 */
	private function assertValidEmailDigest(array $prefs): void {
		if (isset($prefs['emailDigest']) === false) {
			return;
		}

		if (in_array($prefs['emailDigest'], self::VALID_DIGEST_FREQUENCIES, true) === false) {
			throw new InvalidArgumentException(
				'Invalid emailDigest value. Allowed: none, daily, weekly'
			);
		}
	}//end assertValidEmailDigest()

	/**
	 * A preference value as it is stored: booleans as 'true'/'false', anything else as a string.
	 *
	 * @param mixed $value The preference value.
	 *
	 * @return string The stored form.
	 */
	private function preferenceToStore(mixed $value): string {
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		return (string)$value;
	}//end preferenceToStore()

	/**
	 * Get one page of a user's activity.
	 *
	 * @param IUser         $user  The user to get activity for.
	 * @param ActivityQuery $query The page, the type filter and the period.
	 *
	 * @return array Activity results with total count
	 *
	 * @spec openspec/specs/profile-actions/spec.md
	 */
	public function activity(IUser $user, ActivityQuery $query): array {
		$window = new ActionWindow(action: $query->type, from: $query->period->from, to: $query->period->to);
		$data = $this->storage->auditTrailMapper->findActorTrail(
			query: new ActorTrailQuery(userId: $user->getUID(), limit: $query->limit, offset: $query->offset, window: $window)
		);

		return [
			'results' => array_map(fn ($entry): array => $this->activityRow(serialized: $entry->jsonSerialize()), $data['results']),
			'total' => $data['total'],
		];
	}//end activity()

	/**
	 * One audit-trail entry as the activity listing shows it.
	 *
	 * @param array $serialized The serialized audit-trail entry.
	 *
	 * @return array The activity row.
	 */
	private function activityRow(array $serialized): array {
		return [
			'id' => $serialized['id'] ?? null,
			'type' => $serialized['action'] ?? null,
			'objectUuid' => $serialized['objectUuid'] ?? null,
			'register' => $serialized['register'] ?? null,
			'schema' => $serialized['schema'] ?? null,
			'timestamp' => $serialized['created'] ?? null,
			'summary' => ($serialized['action'] ?? 'action') . ' on object',
		];
	}//end activityRow()

	/**
	 * Mint a personal API token.
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
	public function issueApiToken(IUser $user, ApiTokenRequest $request): array {
		$userId = $user->getUID();
		$tokens = $this->tokensWithRoom(userId: $userId);

		// Generate a secure token.
		$tokenValue = $this->storage->secureRandom->generate(64);
		$tokenId = $this->storage->secureRandom->generate(16);
		$expires = $this->requestedExpiry(expiresIn: $request->expiresIn);
		$now = date('c');

		$tokens[$tokenId] = [
			'id' => $tokenId,
			'name' => $request->name,
			'token' => hash('sha256', $tokenValue),
			'preview' => substr($tokenValue, -4),
			'created' => $now,
			'lastUsed' => null,
			'expires' => $expires,
		];
		$this->storeTokens(userId: $userId, tokens: $tokens);

		return ['id' => $tokenId, 'name' => $request->name, 'token' => $tokenValue, 'created' => $now, 'expires' => $expires];
	}//end issueApiToken()

	/**
	 * The user's stored tokens, refusing when the maximum is reached.
	 *
	 * @param string $userId The user id.
	 *
	 * @return array The stored tokens.
	 *
	 * @throws RuntimeException If maximum tokens reached
	 */
	private function tokensWithRoom(string $userId): array {
		$tokens = $this->getStoredTokens(userId: $userId);

		if (count($tokens) >= self::MAX_TOKENS) {
			throw new RuntimeException(
				'Maximum number of API tokens (' . self::MAX_TOKENS . ') reached. Revoke an existing token first.',
				400
			);
		}

		return $tokens;
	}//end tokensWithRoom()

	/**
	 * The expiry a requested lifetime gives, null for a token without one.
	 *
	 * SECURITY: a non-matching `expiresIn` (e.g. "5x", "abc") used to
	 * fall through to `$expires = null` → non-expiring token. That is
	 * a perpetual API key minted from malformed input. Reject the
	 * request instead so the caller sees the typo.
	 *
	 * @param string|null $expiresIn The requested lifetime (e.g. "90d"), or null/empty for none.
	 *
	 * @return string|null The ISO 8601 expiry, or null.
	 *
	 * @throws InvalidArgumentException If the lifetime is malformed
	 */
	private function requestedExpiry(?string $expiresIn): ?string {
		if ($expiresIn === null || $expiresIn === '') {
			return null;
		}

		return $this->validExpiry(expiresIn: $expiresIn);
	}//end requestedExpiry()

	/**
	 * The ISO 8601 expiry of a lifetime, refusing a malformed one.
	 *
	 * @param string $expiresIn The requested lifetime (e.g. "90d").
	 *
	 * @return string The ISO 8601 expiry.
	 *
	 * @throws InvalidArgumentException If the lifetime is malformed
	 */
	private function validExpiry(string $expiresIn): string {
		$expires = $this->parseExpiration(expiresIn: $expiresIn);
		if ($expires === null) {
			throw new InvalidArgumentException(
				'Invalid expiresIn value "' . $expiresIn . '" — expected a number followed by d (days), h (hours), or m (minutes), e.g. "90d".'
			);
		}

		return $expires;
	}//end validExpiry()

	/**
	 * List API tokens for the user (masked values)
	 *
	 * @param IUser $user The user to list tokens for
	 *
	 * @return array Array of token objects with masked values
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function listApiTokens(IUser $user): array {
		$tokens = $this->getStoredTokens(userId: $user->getUID());

		return array_values(
			array_map(
				function ($token) {
					return [
						'id' => $token['id'],
						'name' => $token['name'],
						'preview' => '****' . ($token['preview'] ?? ''),
						'created' => $token['created'],
						'lastUsed' => $token['lastUsed'] ?? null,
						'expires' => $token['expires'] ?? null,
					];
				},
				$tokens
			)
		);
	}//end listApiTokens()

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
	public function revokeApiToken(IUser $user, string $tokenId): array {
		$userId = $user->getUID();
		$tokens = $this->getStoredTokens(userId: $userId);

		if (isset($tokens[$tokenId]) === false) {
			throw new RuntimeException('Token not found', 404);
		}

		unset($tokens[$tokenId]);
		$this->storeTokens(userId: $userId, tokens: $tokens);

		return [
			'success' => true,
			'message' => 'Token revoked',
		];
	}//end revokeApiToken()

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
	public function requestDeactivation(IUser $user, string $reason = ''): array {
		$userId = $user->getUID();

		// Check for existing request.
		$existing = $this->storage->config->getUserValue($userId, self::APP_NAME, 'deactivation_request', '');
		if ($existing !== '') {
			$existingData = json_decode($existing, true);
			throw new RuntimeException(
				json_encode(
					[
						'error' => 'A deactivation request is already pending',
						'requestedAt' => $existingData['requestedAt'] ?? null,
					]
				),
				409
			);
		}

		$now = date('c');
		$requestData = [
			'status' => 'pending',
			'reason' => $reason,
			'requestedAt' => $now,
		];

		$this->storage->config->setUserValue($userId, self::APP_NAME, 'deactivation_request', json_encode($requestData));

		return [
			'success' => true,
			'message' => 'Deactivation request submitted',
			'status' => 'pending',
			'requestedAt' => $now,
		];
	}//end requestDeactivation()

	/**
	 * Get deactivation request status
	 *
	 * @param IUser $user The user to check status for
	 *
	 * @return array Status information
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3
	 */
	public function getDeactivationStatus(IUser $user): array {
		$userId = $user->getUID();
		$existing = $this->storage->config->getUserValue($userId, self::APP_NAME, 'deactivation_request', '');

		if ($existing === '') {
			return [
				'status' => 'active',
				'pendingRequest' => null,
			];
		}

		$data = json_decode($existing, true);
		return [
			'status' => $data['status'] ?? 'pending',
			'pendingRequest' => $data,
		];
	}//end getDeactivationStatus()

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
	public function cancelDeactivation(IUser $user): array {
		$userId = $user->getUID();
		$existing = $this->storage->config->getUserValue($userId, self::APP_NAME, 'deactivation_request', '');

		if ($existing === '') {
			throw new RuntimeException('No pending deactivation request', 404);
		}

		$this->storage->config->deleteUserValue($userId, self::APP_NAME, 'deactivation_request');

		return [
			'success' => true,
			'message' => 'Deactivation request cancelled',
			'status' => 'active',
		];
	}//end cancelDeactivation()

	/**
	 * Get stored API tokens for a user
	 *
	 * @param string $userId The user ID
	 *
	 * @return array The stored tokens
	 */
	private function getStoredTokens(string $userId): array {
		$stored = $this->storage->config->getUserValue($userId, self::APP_NAME, 'api_tokens', '');
		if ($stored === '') {
			return [];
		}

		return json_decode($stored, true) ?? [];
	}//end getStoredTokens()

	/**
	 * Store API tokens for a user
	 *
	 * @param string $userId The user ID
	 * @param array $tokens The tokens array to store
	 *
	 * @return void
	 */
	private function storeTokens(string $userId, array $tokens): void {
		$this->storage->config->setUserValue($userId, self::APP_NAME, 'api_tokens', json_encode($tokens));
	}//end storeTokens()

	/**
	 * Parse an expiration string into an ISO date
	 *
	 * @param string $expiresIn Expiration string (e.g., "90d", "24h")
	 *
	 * @return string|null ISO 8601 date or null
	 */
	private function parseExpiration(string $expiresIn): ?string {
		$matches = [];
		if (preg_match('/^(\d+)([dhm])$/', $expiresIn, $matches) !== 1) {
			return null;
		}

		$value = (int)$matches[1];
		$unit = $matches[2];

		$intervalMap = [
			'd' => 'days',
			'h' => 'hours',
			'm' => 'minutes',
		];

		$interval = $intervalMap[$unit] ?? 'days';
		$date = new DateTime();
		$date->modify('+' . $value . ' ' . $interval);

		return $date->format('c');
	}//end parseExpiration()
}//end class
