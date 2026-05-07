<?php

/**
 * UserService
 *
 * This service handles all user-related business logic including user data retrieval,
 * updates, and profile management. It centralizes user operations and provides
 * a clean interface for controllers and other services.
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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use RuntimeException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAvatarManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Accounts\IAccountManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

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
 */
class UserService
{

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
        'objectChanges'       => true,
        'assignments'         => true,
        'organisationChanges' => true,
        'systemAnnouncements' => true,
        'emailDigest'         => 'daily',
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
     * UserService constructor
     *
     * @param IUserManager        $userManager         The user manager service
     * @param IUserSession        $userSession         The user session service
     * @param IConfig             $config              The configuration service
     * @param IGroupManager       $groupManager        The group manager service
     * @param IAccountManager     $accountManager      The account manager service
     * @param LoggerInterface     $logger              The logger interface
     * @param OrganisationService $organisationService The organisation service
     * @param IEventDispatcher    $eventDispatcher     The event dispatcher service
     * @param IAvatarManager      $avatarManager       The avatar manager service
     * @param AuditTrailMapper    $auditTrailMapper    The audit trail mapper
     * @param ISecureRandom       $secureRandom        Secure random generator
     * @param IDBConnection       $db                  Database connection for direct queries
     * @param IFactory            $l10nFactory         L10N factory for language detection
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Service requires many Nextcloud dependencies
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
        private readonly IGroupManager $groupManager,
        private readonly IAccountManager $accountManager,
        private readonly LoggerInterface $logger,
        private readonly OrganisationService $organisationService,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IAvatarManager $avatarManager,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ISecureRandom $secureRandom,
        private readonly IDBConnection $db,
        private readonly IFactory $l10nFactory
    ) {
    }//end __construct()

    /**
     * Get current authenticated user
     *
     * @return IUser|null The current user or null if not authenticated
     */
    public function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();
    }//end getCurrentUser()

    /**
     * Build comprehensive user data array
     *
     * @param IUser $user The user object to build data for
     *
     * @return array The comprehensive user data array
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function buildUserDataArray(IUser $user): array
    {
        $userGroups = $this->groupManager->getUserGroups($user);
        $groupNames = array_values(array_map(fn($group) => $group->getGID(), $userGroups));

        $quota = $this->buildQuotaInformation(user: $user);

        [$language, $locale] = $this->getLanguageAndLocale(user: $user);

        $additionalInfo = $this->getAdditionalProfileInfo(user: $user);

        $emailVerified = null;
        if (method_exists($user, 'getEmailVerified') === true) {
            $emailVerified = $user->getEmailVerified();
        }

        $avatarScope = 'contacts';
        if (method_exists($user, 'getAvatarScope') === true) {
            $avatarScope = $user->getAvatarScope();
        }

        $lastLogin = 0;
        if (method_exists($user, 'getLastLogin') === true) {
            $lastLogin = $user->getLastLogin();
        }

        $backend = 'unknown';
        if (method_exists($user, 'getBackendClassName') === true) {
            $backend = $user->getBackendClassName();
        }

        $canChangeDisplayName = false;
        if (method_exists($user, 'canChangeDisplayName') === true) {
            $canChangeDisplayName = $user->canChangeDisplayName();
        }

        $canChangeEmail = false;
        if (method_exists($user, 'canChangeMailAddress') === true) {
            $canChangeEmail = $user->canChangeMailAddress();
        }

        $canChangePassword = false;
        if (method_exists($user, 'canChangePassword') === true) {
            $canChangePassword = $user->canChangePassword();
        }

        $canChangeAvatar = false;
        if (method_exists($user, 'canChangeAvatar') === true) {
            $canChangeAvatar = $user->canChangeAvatar();
        }

        $result = [
            'uid'                 => $user->getUID(),
            'displayName'         => $user->getDisplayName(),
            'email'               => $user->getEMailAddress(),
            'emailVerified'       => $emailVerified,
            'enabled'             => $user->isEnabled(),
            'quota'               => $quota,
            'avatarScope'         => $avatarScope,
            'lastLogin'           => $lastLogin,
            'backend'             => $backend,
            'subadmin'            => [],
            'groups'              => $groupNames,
            'language'            => $language,
            'locale'              => $locale,
            'backendCapabilities' => [
                'displayName' => $canChangeDisplayName,
                'email'       => $canChangeEmail,
                'password'    => $canChangePassword,
                'avatar'      => $canChangeAvatar,
            ],
        ];

        $result = array_merge($result, $additionalInfo);

        $result['firstName']  = $result['firstName'] ?? null;
        $result['lastName']   = $result['lastName'] ?? null;
        $result['middleName'] = $result['middleName'] ?? null;
        // 'functie' is the Dutch term for job title/role - map from 'role' property.
        $result['functie'] = $result['functie'] ?? $additionalInfo['role'] ?? null;

        // Add organization information in the format expected by the frontend.
        // Frontend expects: { active: { uuid, naam, id, slug }, all: [...] }
        // Use cached result if available (avoids ~20-30 redundant queries when called twice in updateMe).
        $organisationStats = $this->cachedOrgStats;
        if ($this->cachedOrgStats === null) {
            try {
                $organisationStats = $this->organisationService->getUserOrganisationStats();
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get user organisation stats: '.$e->getMessage());
                $organisationStats = ['total' => 0, 'active' => null, 'results' => []];
            }

            $this->cachedOrgStats = $organisationStats;
        }

        // Transform organisation data to include 'naam' field (Dutch) alongside 'name'.
        $transformOrg = function (?array $org): ?array {
            if ($org === null) {
                return null;
            }

            // Add 'naam' field that mirrors 'name' for Dutch frontend compatibility.
            $org['naam'] = $org['name'] ?? null;
            return $org;
        };

        // Build the organisations structure expected by the frontend.
        $result['organisations'] = [
            'active'    => $transformOrg($organisationStats['active'] ?? null),
            'all'       => array_map($transformOrg, $organisationStats['results'] ?? []),
            'total'     => $organisationStats['total'] ?? 0,
            'available' => true,
        ];

        return $result;
    }//end buildUserDataArray()

    /**
     * Update user properties based on provided data
     *
     * @param IUser $user The user object to update
     * @param array $data The data array containing updates
     *
     * @return array Result of the update operation including organization changes
     */
    public function updateUserProperties(IUser $user, array $data): array
    {
        $result = [
            'success'              => true,
            'message'              => 'User properties updated successfully',
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
            $this->eventDispatcher->dispatchTyped($event);

            $this->logger->debug(
                    message: '[UserService] UserService: Dispatched UserProfileUpdatedEvent',
                    context: [
                        'file'    => __FILE__,
                        'line'    => __LINE__,
                        'app'     => 'openregister',
                        'userId'  => $user->getUID(),
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
    private function determineChangedFields(array $oldData, array $newData): array
    {
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
            'functie',
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
     */
    public function getCustomNameFields(IUser $user): array
    {
        $userId = $user->getUID();

        $firstName = $this->config->getUserValue($userId, 'core', 'firstName', '');
        if ($firstName === '') {
            $firstName = null;
        }

        $lastName = $this->config->getUserValue($userId, 'core', 'lastName', '');
        if ($lastName === '') {
            $lastName = null;
        }

        $middleName = $this->config->getUserValue($userId, 'core', 'middleName', '');
        if ($middleName === '') {
            $middleName = null;
        }

        return [
            'firstName'  => $firstName,
            'lastName'   => $lastName,
            'middleName' => $middleName,
        ];
    }//end getCustomNameFields()

    /**
     * Set custom name fields for a user
     *
     * @param IUser $user       The user object
     * @param array $nameFields Array containing name field values
     *
     * @return void
     */
    public function setCustomNameFields(IUser $user, array $nameFields): void
    {
        $userId        = $user->getUID();
        $allowedFields = ['firstName', 'lastName', 'middleName'];

        foreach ($allowedFields as $field) {
            if (isset($nameFields[$field]) === true) {
                $value = (string) $nameFields[$field];
                $this->config->setUserValue($userId, 'core', $field, $value);
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
    private function buildQuotaInformation(IUser $user): array
    {
        try {
            $userQuota = 'none';
            if (method_exists($user, 'getQuota') === true) {
                $userQuota = $user->getQuota();
            }

            $usedSpace = 0;

            $userId = $user->getUID();

            try {
                // Default to memory-safe method, override if native method exists.
                $usedSpace = $this->getUsedSpaceMemorySafe(userId: $userId);
                if (method_exists($user, 'getUsedSpace') === true) {
                    $usedSpace = $user->getUsedSpace();
                }
            } catch (\Exception $quotaException) {
                $this->logger->debug(
                    message: '[UserService] User quota calculation failed for user: '.$userId,
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'exception' => $quotaException->getMessage(),
                    ]
                );

                $usedSpace = $this->getUsedSpaceMemorySafe(userId: $userId);
            }

            $quota = [
                'free'     => $userQuota,
                'used'     => $usedSpace,
                'total'    => $userQuota,
                'relative' => 0,
            ];

            if ($userQuota !== 'none' && $userQuota !== 'unlimited' && is_numeric($userQuota) === true) {
                $totalBytes = (int) $userQuota;
                if ($totalBytes > 0) {
                    $quota['relative'] = round(($usedSpace / $totalBytes) * 100, 2);
                }
            }

            return $quota;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[UserService] Failed to build quota information for user: '.$user->getUID(),
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );

            return [
                'free'     => 'none',
                'used'     => 0,
                'total'    => 'none',
                'relative' => 0,
            ];
        }//end try
    }//end buildQuotaInformation()

    /**
     * Get used space in a memory-safe way
     *
     * @param string $userId The user ID
     *
     * @return int The used space in bytes or 0 if cannot be determined safely
     */
    private function getUsedSpaceMemorySafe(string $userId): int
    {
        try {
            $currentMemoryUsage = memory_get_usage(true);

            if ($currentMemoryUsage > 128 * 1024 * 1024) {
                $this->logger->warning(
                    message: '[UserService] Memory usage too high for quota calculation',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'user'         => $userId,
                        'memory_usage' => $currentMemoryUsage,
                    ]
                );
                return 0;
            }

            $query = $this->db->getQueryBuilder();

            $query->select('s.size')
                ->from('storages', 's')
                ->join('s', 'mounts', 'm', $query->expr()->eq('s.id', 'm.storage_id'))
                ->where($query->expr()->eq('m.user_id', $query->createNamedParameter($userId)))
                ->setMaxResults(1);

            $result = $query->execute();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row !== false && isset($row['size']) === true && is_numeric($row['size']) === true) {
                return (int) $row['size'];
            }

            $this->logger->info(
                message: '[UserService] Using fallback quota calculation for user: '.$userId,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return 0;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[UserService] Memory-safe quota calculation failed for user: '.$userId,
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );
            return 0;
        }//end try
    }//end getUsedSpaceMemorySafe()

    /**
     * Get language and locale with proper fallbacks
     *
     * @param IUser $user The user object
     *
     * @return array Array containing language and locale
     */
    private function getLanguageAndLocale(IUser $user): array
    {
        $language = '';
        $locale   = '';

        if (method_exists($user, 'getLanguage') === true) {
            $language = $user->getLanguage();
            if (empty($language) === true) {
                $language = $this->l10nFactory->findLanguage();
            }
        }

        if (method_exists($user, 'getLocale') === true) {
            $locale = $user->getLocale();
            if (empty($locale) === true && empty($language) === false) {
                // Default to language_LANGUAGE format, override for English.
                $locale = $language.'_'.strtoupper($language);
                if ($language === 'en') {
                    $locale = 'en_US';
                }
            }
        }

        return [$language, $locale];
    }//end getLanguageAndLocale()

    /**
     * Get additional profile information from various sources
     *
     * @param IUser $user The user object
     *
     * @return array Additional profile information
     */
    private function getAdditionalProfileInfo(IUser $user): array
    {
        $additionalInfo = [];

        try {
            $additionalInfo = $this->getAccountManagerPropertiesSelectively(user: $user);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[UserService] AccountManager failed for user: '.$user->getUID(),
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );

            $userId = $user->getUID();

            $phone = $this->config->getUserValue($userId, 'settings', 'phone', '');
            if (empty($phone) === false) {
                $additionalInfo['phone'] = $phone;
            }

            $website = $this->config->getUserValue($userId, 'settings', 'website', '');
            if (empty($website) === false) {
                $additionalInfo['website'] = $website;
            }

            $twitter = $this->config->getUserValue($userId, 'settings', 'twitter', '');
            if (empty($twitter) === false) {
                $additionalInfo['twitter'] = $twitter;
            }
        }//end try

        $customNameFields = $this->getCustomNameFields(user: $user);
        $additionalInfo   = array_merge($additionalInfo, $customNameFields);

        $userId           = $user->getUID();
        $organizationUuid = $this->config->getUserValue($userId, 'core', 'organisation', '');
        if (empty($organizationUuid) === false) {
            $additionalInfo['organisation'] = $organizationUuid;
        }

        // Fallback: check for 'functie' in user config if not found via AccountManager's 'role'.
        if (empty($additionalInfo['role']) === true) {
            $functie = $this->config->getUserValue($userId, 'core', 'functie', '');
            if (empty($functie) === false) {
                $additionalInfo['role'] = $functie;
            }
        }

        return $additionalInfo;
    }//end getAdditionalProfileInfo()

    /**
     * Get AccountManager properties selectively to reduce memory usage
     *
     * @param IUser $user The user object
     *
     * @return array Profile information from AccountManager
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function getAccountManagerPropertiesSelectively(IUser $user): array
    {
        $additionalInfo = [];

        $account = $this->accountManager->getAccount($user);

        $neededProperties = [
            IAccountManager::PROPERTY_PHONE        => 'phone',
            IAccountManager::PROPERTY_ADDRESS      => 'address',
            IAccountManager::PROPERTY_WEBSITE      => 'website',
            IAccountManager::PROPERTY_TWITTER      => 'twitter',
            IAccountManager::PROPERTY_FEDIVERSE    => 'fediverse',
            IAccountManager::PROPERTY_ORGANISATION => 'organisation',
            IAccountManager::PROPERTY_ROLE         => 'role',
            IAccountManager::PROPERTY_HEADLINE     => 'headline',
            IAccountManager::PROPERTY_BIOGRAPHY    => 'biography',
        ];

        foreach ($neededProperties as $propertyName => $apiField) {
            try {
                $property = $account->getProperty($propertyName);
                if ($property !== null) {
                    $value = $property->getValue();
                    if (empty($value) === false) {
                        $additionalInfo[$apiField] = $value;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug(
                    message: '[UserService] Failed to load account property: '.$propertyName,
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'user'      => $user->getUID(),
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
    private function updateStandardUserProperties(IUser $user, array $data): void
    {
        if (isset($data['displayName']) === true
            && method_exists($user, 'canChangeDisplayName') === true
            && $user->canChangeDisplayName() === true
        ) {
            $user->setDisplayName($data['displayName']);
        }

        if (isset($data['email']) === true
            && method_exists($user, 'canChangeMailAddress') === true
            && $user->canChangeMailAddress() === true
        ) {
            $user->setEMailAddress($data['email']);
        }

        if (isset($data['password']) === true
            && method_exists($user, 'canChangePassword') === true
            && $user->canChangePassword() === true
        ) {
            $user->setPassword($data['password']);
        }

        if (isset($data['language']) === true && method_exists($user, 'setLanguage') === true) {
            $user->setLanguage($data['language']);
        }

        if (isset($data['locale']) === true && method_exists($user, 'setLocale') === true) {
            $user->setLocale($data['locale']);
        }
    }//end updateStandardUserProperties()

    /**
     * Update profile properties via AccountManager and custom fields
     *
     * @param IUser $user The user object to update
     * @param array $data The data array containing updates
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function updateProfileProperties(IUser $user, array $data): void
    {
        try {
            $account        = $this->accountManager->getAccount($user);
            $accountUpdated = false;

            $standardFields = [
                'phone'        => IAccountManager::PROPERTY_PHONE,
                'address'      => IAccountManager::PROPERTY_ADDRESS,
                'website'      => IAccountManager::PROPERTY_WEBSITE,
                'twitter'      => IAccountManager::PROPERTY_TWITTER,
                'fediverse'    => IAccountManager::PROPERTY_FEDIVERSE,
                'organisation' => IAccountManager::PROPERTY_ORGANISATION,
                'role'         => IAccountManager::PROPERTY_ROLE,
                'functie'      => IAccountManager::PROPERTY_ROLE,
                'headline'     => IAccountManager::PROPERTY_HEADLINE,
                'biography'    => IAccountManager::PROPERTY_BIOGRAPHY,
            ];

            foreach ($standardFields as $apiField => $accountProperty) {
                if (isset($data[$apiField]) === false) {
                    continue;
                }

                $value = (string) $data[$apiField];

                if ($account->getProperty($accountProperty) !== null) {
                    $property = $account->getProperty($accountProperty);
                    if ($property->getValue() !== $value) {
                        $property->setValue($value);
                        $accountUpdated = true;
                    }

                    continue;
                }

                // Property doesn't exist, create it.
                $scope    = $this->getDefaultPropertyScope(propertyName: $accountProperty);
                $verified = IAccountManager::NOT_VERIFIED;

                $account->setProperty(
                    property: $accountProperty,
                    value: $value,
                    scope: $scope,
                    verified: $verified
                );
                $accountUpdated = true;
            }//end foreach

            if ($accountUpdated === true) {
                $this->accountManager->updateAccount($account);
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[UserService] Failed to update AccountManager properties for user: '.$user->getUID(),
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try

        $customFields = ['firstName', 'lastName', 'middleName'];
        $nameFields   = [];

        foreach ($customFields as $field) {
            if (isset($data[$field]) === true) {
                $nameFields[$field] = $data[$field];
            }
        }

        if (empty($nameFields) === false) {
            $this->setCustomNameFields(user: $user, nameFields: $nameFields);
        }

        // Store 'functie' in user config as fallback for the /me endpoint.
        if (isset($data['functie']) === true) {
            $this->config->setUserValue($user->getUID(), 'core', 'functie', (string) $data['functie']);
        }
    }//end updateProfileProperties()

    /**
     * Get default property scope for account properties
     *
     * @param string $propertyName The property name
     *
     * @return string The default scope for the property
     */
    private function getDefaultPropertyScope(string $propertyName): string
    {
        $scopeMap = [
            IAccountManager::PROPERTY_PHONE        => IAccountManager::SCOPE_PRIVATE,
            IAccountManager::PROPERTY_ADDRESS      => IAccountManager::SCOPE_PRIVATE,
            IAccountManager::PROPERTY_WEBSITE      => IAccountManager::SCOPE_PUBLISHED,
            IAccountManager::PROPERTY_TWITTER      => IAccountManager::SCOPE_PUBLISHED,
            IAccountManager::PROPERTY_FEDIVERSE    => IAccountManager::SCOPE_PUBLISHED,
            IAccountManager::PROPERTY_ORGANISATION => IAccountManager::SCOPE_LOCAL,
            IAccountManager::PROPERTY_ROLE         => IAccountManager::SCOPE_LOCAL,
            IAccountManager::PROPERTY_HEADLINE     => IAccountManager::SCOPE_LOCAL,
            IAccountManager::PROPERTY_BIOGRAPHY    => IAccountManager::SCOPE_LOCAL,
        ];

        return $scopeMap[$propertyName] ?? IAccountManager::SCOPE_PRIVATE;
    }//end getDefaultPropertyScope()

    /**
     * Change password for the current user
     *
     * Validates the current password, checks backend capability,
     * and sets the new password.
     *
     * @param IUser  $user            The user changing their password
     * @param string $currentPassword The current password for verification
     * @param string $newPassword     The new password to set
     *
     * @return array Result array with success status
     *
     * @throws InvalidArgumentException If inputs are invalid
     * @throws RuntimeException         If password change fails
     */
    public function changePassword(IUser $user, string $currentPassword, string $newPassword): array
    {
        // Check backend capability.
        if (method_exists($user, 'canChangePassword') === true && $user->canChangePassword() === false) {
            throw new RuntimeException(
                'Password changes are not supported by your authentication backend',
                409
            );
        }

        // Verify current password.
        $verifiedUser = $this->userManager->checkPassword($user->getUID(), $currentPassword);
        if ($verifiedUser === false) {
            throw new RuntimeException('Current password is incorrect', 403);
        }

        // Set new password.
        $result = $user->setPassword($newPassword);
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
    }//end changePassword()

    /**
     * Upload a new avatar for the user
     *
     * Validates file type and size, then sets via IAvatarManager.
     *
     * @param IUser  $user     The user uploading an avatar
     * @param string $data     The raw image data
     * @param string $mimeType The MIME type of the uploaded file
     * @param int    $size     The file size in bytes
     *
     * @return array Result array with success status and avatar URL
     *
     * @throws RuntimeException If upload fails
     */
    public function uploadAvatar(IUser $user, string $data, string $mimeType, int $size): array
    {
        // Check backend capability.
        if (method_exists($user, 'canChangeAvatar') === true && $user->canChangeAvatar() === false) {
            throw new RuntimeException(
                'Avatar changes are not supported by your authentication backend',
                409
            );
        }

        // Validate file type.
        if (in_array($mimeType, self::ALLOWED_AVATAR_TYPES, true) === false) {
            throw new RuntimeException(
                'Unsupported image format. Allowed: JPEG, PNG, GIF, WebP',
                400
            );
        }

        // Validate file size.
        if ($size > self::MAX_AVATAR_SIZE) {
            throw new RuntimeException('Avatar image must be smaller than 5 MB', 400);
        }

        $userId = $user->getUID();
        $avatar = $this->avatarManager->getAvatar($userId);
        $avatar->set($data);

        return [
            'success'   => true,
            'avatarUrl' => '/avatar/'.$userId.'/128',
        ];
    }//end uploadAvatar()

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
     */
    public function deleteAvatar(IUser $user): array
    {
        // Check backend capability.
        if (method_exists($user, 'canChangeAvatar') === true && $user->canChangeAvatar() === false) {
            throw new RuntimeException(
                'Avatar changes are not supported by your authentication backend',
                409
            );
        }

        $avatar = $this->avatarManager->getAvatar($user->getUID());
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
     */
    public function exportPersonalData(IUser $user): array
    {
        $userId = $user->getUID();

        // Check rate limit.
        $lastExport      = $this->config->getUserValue($userId, self::APP_NAME, 'last_export_time', '0');
        $timeSinceExport = time() - (int) $lastExport;

        if ($timeSinceExport < self::EXPORT_RATE_LIMIT) {
            $retryAfter = self::EXPORT_RATE_LIMIT - $timeSinceExport;
            throw new RuntimeException(
                json_encode(
                        [
                            'error'       => 'Data export is limited to once per hour',
                            'retry_after' => $retryAfter,
                        ]
                        ),
                429
            );
        }

        // Record export time.
        $this->config->setUserValue($userId, self::APP_NAME, 'last_export_time', (string) time());

        // Build profile data.
        $profile = $this->buildUserDataArray(user: $user);

        // Get audit trail entries.
        $auditData  = $this->auditTrailMapper->findByActor($userId, 1000, 0);
        $auditTrail = array_map(
            function ($entry) {
                return $entry->jsonSerialize();
            },
            $auditData['results']
        );

        return [
            'exportDate'    => date('c'),
            'profile'       => $profile,
            'organisations' => $profile['organisations'] ?? [],
            'objects'       => [],
            'auditTrail'    => $auditTrail,
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
     */
    public function getNotificationPreferences(IUser $user): array
    {
        $userId = $user->getUID();
        $prefs  = [];

        foreach (self::DEFAULT_NOTIFICATION_PREFS as $key => $defaultValue) {
            $stored = $this->config->getUserValue($userId, self::APP_NAME, 'notification_'.$key, '');

            if ($stored === '') {
                $prefs[$key] = $defaultValue;
                continue;
            }

            // Convert string booleans.
            $prefs[$key] = $stored;
            if ($defaultValue === true || $defaultValue === false) {
                $prefs[$key] = ($stored === 'true' || $stored === '1');
            }
        }

        return $prefs;
    }//end getNotificationPreferences()

    /**
     * Update notification preferences for the current user
     *
     * Validates and stores preference values in IConfig.
     *
     * @param IUser $user  The user to update preferences for
     * @param array $prefs The preference values to update
     *
     * @return array The complete updated preferences
     *
     * @throws InvalidArgumentException If invalid preference values
     */
    public function setNotificationPreferences(IUser $user, array $prefs): array
    {
        $userId = $user->getUID();

        // Validate emailDigest if provided.
        if (isset($prefs['emailDigest']) === true) {
            if (in_array($prefs['emailDigest'], self::VALID_DIGEST_FREQUENCIES, true) === false) {
                throw new InvalidArgumentException(
                    'Invalid emailDigest value. Allowed: none, daily, weekly'
                );
            }
        }

        // Store provided preferences.
        foreach ($prefs as $key => $value) {
            if (array_key_exists($key, self::DEFAULT_NOTIFICATION_PREFS) === false) {
                continue;
            }

            $storeValue = is_bool($value) === true ? ($value === true ? 'true' : 'false') : (string) $value;
            $this->config->setUserValue($userId, self::APP_NAME, 'notification_'.$key, $storeValue);
        }

        // Return complete preferences.
        return $this->getNotificationPreferences(user: $user);
    }//end setNotificationPreferences()

    /**
     * Get activity history for the current user
     *
     * Queries audit trail entries where the user is the actor.
     *
     * @param IUser       $user   The user to get activity for
     * @param int         $limit  Maximum results to return
     * @param int         $offset Results to skip
     * @param string|null $type   Optional action type filter
     * @param string|null $from   Optional start date (Y-m-d)
     * @param string|null $to     Optional end date (Y-m-d)
     *
     * @return array Activity results with total count
     */
    public function getUserActivity(
        IUser $user,
        int $limit=25,
        int $offset=0,
        ?string $type=null,
        ?string $from=null,
        ?string $to=null
    ): array {
        $data = $this->auditTrailMapper->findByActor(
            $user->getUID(),
            $limit,
            $offset,
            $type,
            $from,
            $to
        );

        $results = array_map(
            function ($entry) {
                $serialized = $entry->jsonSerialize();
                return [
                    'id'         => $serialized['id'] ?? null,
                    'type'       => $serialized['action'] ?? null,
                    'objectUuid' => $serialized['objectUuid'] ?? null,
                    'register'   => $serialized['register'] ?? null,
                    'schema'     => $serialized['schema'] ?? null,
                    'timestamp'  => $serialized['created'] ?? null,
                    'summary'    => ($serialized['action'] ?? 'action').' on object',
                ];
            },
            $data['results']
        );

        return [
            'results' => $results,
            'total'   => $data['total'],
        ];
    }//end getUserActivity()

    /**
     * Create a new API token for the user
     *
     * Generates a cryptographically secure token and stores it in IConfig.
     *
     * @param IUser       $user      The user creating a token
     * @param string      $name      The token name
     * @param string|null $expiresIn Optional expiration (e.g., "90d")
     *
     * @return array The created token data (full value shown only once)
     *
     * @throws RuntimeException If maximum tokens reached
     */
    public function createApiToken(IUser $user, string $name, ?string $expiresIn=null): array
    {
        $userId = $user->getUID();
        $tokens = $this->getStoredTokens(userId: $userId);

        if (count($tokens) >= self::MAX_TOKENS) {
            throw new RuntimeException(
                'Maximum number of API tokens ('.self::MAX_TOKENS.') reached. Revoke an existing token first.',
                400
            );
        }

        // Generate a secure token.
        $tokenValue = $this->secureRandom->generate(64);
        $tokenId    = $this->secureRandom->generate(16);

        // Calculate expiration.
        // SECURITY: a non-matching `expiresIn` (e.g. "5x", "abc") used to
        // fall through to `$expires = null` → non-expiring token. That is
        // a perpetual API key minted from malformed input. Reject the
        // request instead so the caller sees the typo.
        $expires = null;
        if ($expiresIn !== null && $expiresIn !== '') {
            $expires = $this->parseExpiration(expiresIn: $expiresIn);
            if ($expires === null) {
                throw new \InvalidArgumentException(
                    'Invalid expiresIn value "'.$expiresIn.'" — expected a number followed by d (days), h (hours), or m (minutes), e.g. "90d".'
                );
            }
        }

        $now       = date('c');
        $tokenData = [
            'id'       => $tokenId,
            'name'     => $name,
            'token'    => hash('sha256', $tokenValue),
            'preview'  => substr($tokenValue, -4),
            'created'  => $now,
            'lastUsed' => null,
            'expires'  => $expires,
        ];

        $tokens[$tokenId] = $tokenData;
        $this->storeTokens(userId: $userId, tokens: $tokens);

        return [
            'id'      => $tokenId,
            'name'    => $name,
            'token'   => $tokenValue,
            'created' => $now,
            'expires' => $expires,
        ];
    }//end createApiToken()

    /**
     * List API tokens for the user (masked values)
     *
     * @param IUser $user The user to list tokens for
     *
     * @return array Array of token objects with masked values
     */
    public function listApiTokens(IUser $user): array
    {
        $tokens = $this->getStoredTokens(userId: $user->getUID());

        return array_values(
                array_map(
            function ($token) {
                return [
                    'id'       => $token['id'],
                    'name'     => $token['name'],
                    'preview'  => '****'.($token['preview'] ?? ''),
                    'created'  => $token['created'],
                    'lastUsed' => $token['lastUsed'] ?? null,
                    'expires'  => $token['expires'] ?? null,
                ];
            },
                $tokens
        )
                );
    }//end listApiTokens()

    /**
     * Revoke an API token by ID
     *
     * @param IUser  $user    The user revoking the token
     * @param string $tokenId The token ID to revoke
     *
     * @return array Result array
     *
     * @throws RuntimeException If token not found
     */
    public function revokeApiToken(IUser $user, string $tokenId): array
    {
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
     * @param IUser  $user   The user requesting deactivation
     * @param string $reason Optional reason for deactivation
     *
     * @return array Result array with status
     *
     * @throws RuntimeException If duplicate request exists
     */
    public function requestDeactivation(IUser $user, string $reason=''): array
    {
        $userId = $user->getUID();

        // Check for existing request.
        $existing = $this->config->getUserValue($userId, self::APP_NAME, 'deactivation_request', '');
        if ($existing !== '') {
            $existingData = json_decode($existing, true);
            throw new RuntimeException(
                json_encode(
                        [
                            'error'       => 'A deactivation request is already pending',
                            'requestedAt' => $existingData['requestedAt'] ?? null,
                        ]
                        ),
                409
            );
        }

        $now         = date('c');
        $requestData = [
            'status'      => 'pending',
            'reason'      => $reason,
            'requestedAt' => $now,
        ];

        $this->config->setUserValue($userId, self::APP_NAME, 'deactivation_request', json_encode($requestData));

        return [
            'success'     => true,
            'message'     => 'Deactivation request submitted',
            'status'      => 'pending',
            'requestedAt' => $now,
        ];
    }//end requestDeactivation()

    /**
     * Get deactivation request status
     *
     * @param IUser $user The user to check status for
     *
     * @return array Status information
     */
    public function getDeactivationStatus(IUser $user): array
    {
        $userId   = $user->getUID();
        $existing = $this->config->getUserValue($userId, self::APP_NAME, 'deactivation_request', '');

        if ($existing === '') {
            return [
                'status'         => 'active',
                'pendingRequest' => null,
            ];
        }

        $data = json_decode($existing, true);
        return [
            'status'         => $data['status'] ?? 'pending',
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
     */
    public function cancelDeactivation(IUser $user): array
    {
        $userId   = $user->getUID();
        $existing = $this->config->getUserValue($userId, self::APP_NAME, 'deactivation_request', '');

        if ($existing === '') {
            throw new RuntimeException('No pending deactivation request', 404);
        }

        $this->config->deleteUserValue($userId, self::APP_NAME, 'deactivation_request');

        return [
            'success' => true,
            'message' => 'Deactivation request cancelled',
            'status'  => 'active',
        ];
    }//end cancelDeactivation()

    /**
     * Get stored API tokens for a user
     *
     * @param string $userId The user ID
     *
     * @return array The stored tokens
     */
    private function getStoredTokens(string $userId): array
    {
        $stored = $this->config->getUserValue($userId, self::APP_NAME, 'api_tokens', '');
        if ($stored === '') {
            return [];
        }

        return json_decode($stored, true) ?? [];
    }//end getStoredTokens()

    /**
     * Store API tokens for a user
     *
     * @param string $userId The user ID
     * @param array  $tokens The tokens array to store
     *
     * @return void
     */
    private function storeTokens(string $userId, array $tokens): void
    {
        $this->config->setUserValue($userId, self::APP_NAME, 'api_tokens', json_encode($tokens));
    }//end storeTokens()

    /**
     * Parse an expiration string into an ISO date
     *
     * @param string $expiresIn Expiration string (e.g., "90d", "24h")
     *
     * @return string|null ISO 8601 date or null
     */
    private function parseExpiration(string $expiresIn): ?string
    {
        $matches = [];
        if (preg_match('/^(\d+)([dhm])$/', $expiresIn, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];
        $unit  = $matches[2];

        $intervalMap = [
            'd' => 'days',
            'h' => 'hours',
            'm' => 'minutes',
        ];

        $interval = $intervalMap[$unit] ?? 'days';
        $date     = new DateTime();
        $date->modify('+'.$value.' '.$interval);

        return $date->format('c');
    }//end parseExpiration()
}//end class
