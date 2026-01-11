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

use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Accounts\IAccountManager;
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
 */
class UserService
{
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
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
        private readonly IGroupManager $groupManager,
        private readonly IAccountManager $accountManager,
        private readonly LoggerInterface $logger,
        private readonly OrganisationService $organisationService
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

        $quota = $this->buildQuotaInformation($user);

        [$language, $locale] = $this->getLanguageAndLocale($user);

        $additionalInfo = $this->getAdditionalProfileInfo($user);

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

        // Add organization information.
        $organisationStats = $this->organisationService->getUserOrganisationStats();
        $organisationStats['available'] = true;
        $result['organisations']        = $organisationStats;

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

        // Handle organization switching if requested.
        if (isset($data['activeOrganisation']) === true && is_string($data['activeOrganisation']) === true) {
            $organisationResult = $this->organisationService->setActiveOrganisation(
                $data['activeOrganisation']
            );
            $result['organisation_updated'] = $organisationResult;
            if ($organisationResult === true) {
                $result['organisation_message'] = 'Active organization updated successfully';
            } else {
                $result['organisation_message'] = 'Failed to update active organization';
            }

            // Remove the organization field from data to prevent it from being processed as a user property.
            unset($data['activeOrganisation']);
        }

        $this->updateStandardUserProperties(user: $user, data: $data);

        $this->updateProfileProperties(user: $user, data: $data);

        return $result;
    }//end updateUserProperties()

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
                $usedSpace = $this->getUsedSpaceMemorySafe($userId);
                if (method_exists($user, 'getUsedSpace') === true) {
                    $usedSpace = $user->getUsedSpace();
                }
            } catch (\Exception $quotaException) {
                $this->logger->debug(
                    'User quota calculation failed for user: '.$userId,
                    [
                        'exception' => $quotaException->getMessage(),
                    ]
                );

                $usedSpace = $this->getUsedSpaceMemorySafe($userId);
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
                'Failed to build quota information for user: '.$user->getUID(),
                [
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
                    'Memory usage too high for quota calculation',
                    [
                        'user'         => $userId,
                        'memory_usage' => $currentMemoryUsage,
                    ]
                );
                return 0;
            }

            $connection = \OC::$server->getDatabaseConnection();
            $query      = $connection->getQueryBuilder();

            $query->select('size')
                ->from('storages')
                ->join('storages', 'mounts', 'm', 'storages.id = m.storage_id')
                ->where($query->expr()->eq('m.user_id', $query->createNamedParameter($userId)))
                ->setMaxResults(1);

            $result = $query->execute();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row !== false && isset($row['size']) === true && is_numeric($row['size']) === true) {
                return (int) $row['size'];
            }

            $this->logger->info('Using fallback quota calculation for user: '.$userId);
            return 0;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Memory-safe quota calculation failed for user: '.$userId,
                [
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
                $language = \OC::$server->getL10NFactory()->findLanguage();
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
            $additionalInfo = $this->getAccountManagerPropertiesSelectively($user);
        } catch (\Exception $e) {
            $this->logger->warning(
                'AccountManager failed for user: '.$user->getUID(),
                [
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

        $customNameFields = $this->getCustomNameFields($user);
        $additionalInfo   = array_merge($additionalInfo, $customNameFields);

        $userId           = $user->getUID();
        $organizationUuid = $this->config->getUserValue($userId, 'core', 'organisation', '');
        if (empty($organizationUuid) === false) {
            $additionalInfo['organisation'] = $organizationUuid;
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
                    'Failed to load account property: '.$propertyName,
                    [
                        'user'      => $user->getUID(),
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }

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
                $scope    = $this->getDefaultPropertyScope($accountProperty);
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
                'Failed to update AccountManager properties for user: '.$user->getUID(),
                [
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
}//end class
