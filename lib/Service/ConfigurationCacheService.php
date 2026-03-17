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
 * Service for caching configurations in user session
 *
 * This service provides session-based caching of configurations per organisation
 * to improve performance when checking if entities are managed by configurations.
 *
 * @package OCA\OpenRegister\Service
 */
class ConfigurationCacheService
{

    /**
     * Session key prefix for storing configurations
     *
     * @var string
     */
    private const SESSION_KEY_PREFIX = 'openregister_configurations_';

    /**
     * Session interface for storing cached data
     *
     * @var ISession
     */
    private ISession $session;

    /**
     * Configuration mapper for database queries
     *
     * @var ConfigurationMapper
     */
    private ConfigurationMapper $configurationMapper;

    /**
     * Organisation service for getting active organisation
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;


    /**
     * Constructor
     *
     * @param ISession              $session               Session interface
     * @param ConfigurationMapper   $configurationMapper   Configuration mapper
     * @param OrganisationService   $organisationService   Organisation service
     */
    public function __construct(
        ISession $session,
        ConfigurationMapper $configurationMapper,
        OrganisationService $organisationService
    ) {
        $this->session               = $session;
        $this->configurationMapper   = $configurationMapper;
        $this->organisationService   = $organisationService;

    }//end __construct()


    /**
     * Get configurations for the active organisation
     *
     * Returns configurations from session cache if available,
     * otherwise fetches from database and caches in session.
     *
     * @return Configuration[] Array of configuration entities
     */
    public function getConfigurationsForActiveOrganisation(): array
    {
        $activeOrg = $this->organisationService->getActiveOrganisation();
        if ($activeOrg === null) {
            return [];
        }

        $orgUuid = $activeOrg->getUuid();
        $sessionKey = self::SESSION_KEY_PREFIX.$orgUuid;

        // Check if configurations are cached in session
        $cachedData = $this->session->get($sessionKey);
        if ($cachedData !== null) {
            // Configurations are cached, unserialize and return
            return unserialize($cachedData);
        }

        // Not cached, fetch from database
        $configurations = $this->configurationMapper->findAll();

        // Cache in session
        $this->session->set($sessionKey, serialize($configurations));

        return $configurations;

    }//end getConfigurationsForActiveOrganisation()


    /**
     * Invalidate the configuration cache for the active organisation
     *
     * Call this method when configurations are created, updated, or deleted
     * to ensure the cache is refreshed on the next request.
     *
     * @return void
     */
    public function invalidateCache(): void
    {
        $activeOrg = $this->organisationService->getActiveOrganisation();
        if ($activeOrg === null) {
            return;
        }

        $orgUuid = $activeOrg->getUuid();
        $sessionKey = self::SESSION_KEY_PREFIX.$orgUuid;

        // Remove from session
        $this->session->remove($sessionKey);

    }//end invalidateCache()


    /**
     * Invalidate cache for a specific organisation
     *
     * @param string $organisationUuid Organisation UUID
     *
     * @return void
     */
    public function invalidateCacheForOrganisation(string $organisationUuid): void
    {
        $sessionKey = self::SESSION_KEY_PREFIX.$organisationUuid;
        $this->session->remove($sessionKey);

    }//end invalidateCacheForOrganisation()


    /**
     * Clear all configuration caches
     *
     * Removes all cached configurations from the session.
     * Useful for administrative operations or when switching organisations.
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        // Get all session keys and remove those that match our prefix
        $sessionKeys = $this->session->getKeys();
        foreach ($sessionKeys as $key) {
            if (strpos($key, self::SESSION_KEY_PREFIX) === 0) {
                $this->session->remove($key);
            }
        }

    }//end clearAllCaches()


}//end class

