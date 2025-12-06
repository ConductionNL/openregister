<?php
/**
 * OpenRegister Configuration Cache Service
 *
 * This file contains the service for caching configurations in the user session
 * to avoid excessive database queries when checking if entities are managed by configurations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCP\ISession;

/**
 * ConfigurationCacheService caches configurations in user session
 *
 * Service for caching configurations in user session to avoid excessive database
 * queries when checking if entities are managed by configurations. Uses session-based
 * caching per organisation for optimal performance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class ConfigurationCacheService
{

    /**
     * Session key prefix for storing configurations
     *
     * Prefix used for session keys to store cached configurations per organisation.
     *
     * @var string Session key prefix
     */
    private const SESSION_KEY_PREFIX = 'openregister_configurations_';

    /**
     * Session interface for storing cached data
     *
     * Used to store and retrieve cached configurations from user session.
     *
     * @var ISession Session instance
     */
    private readonly ISession $session;

    /**
     * Configuration mapper for database queries
     *
     * Used to fetch configurations from database on cache miss.
     *
     * @var ConfigurationMapper Configuration mapper instance
     */
    private readonly ConfigurationMapper $configurationMapper;

    /**
     * Organisation service for getting active organisation
     *
     * Used to determine which organisation's configurations to cache.
     *
     * @var OrganisationService Organisation service instance
     */
    private readonly OrganisationService $organisationService;


    /**
     * Get configurations for the active organisation
     *
     * Returns configurations from session cache if available, otherwise fetches
     * from database and caches in session. Cache is keyed by organisation UUID
     * to support multi-tenancy.
     *
     * @return Configuration[] Array of configuration entities for active organisation
     */
    public function getConfigurationsForActiveOrganisation(): array
    {
        // Step 1: Get active organisation from organisation service.
        $activeOrg = $this->organisationService->getActiveOrganisation();
        if ($activeOrg === null) {
            // No active organisation - return empty array.
            return [];
        }

        // Step 2: Build session cache key using organisation UUID.
        // This ensures cache is isolated per organisation.
        $orgUuid    = $activeOrg->getUuid();
        $sessionKey = self::SESSION_KEY_PREFIX.$orgUuid;

        // Step 3: Check if configurations are cached in session.
        $cachedData = $this->session->get($sessionKey);
        if ($cachedData !== null) {
            // Configurations are cached - unserialize and return.
            return unserialize($cachedData);
        }

        // Step 4: Not cached - fetch configurations from database.
        $configurations = $this->configurationMapper->findAll();

        // Step 5: Cache configurations in session for future requests.
        $this->session->set($sessionKey, serialize($configurations));

        // Step 6: Return fetched configurations.
        return $configurations;

    }//end getConfigurationsForActiveOrganisation()


}//end class
