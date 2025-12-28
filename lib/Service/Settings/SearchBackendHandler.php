<?php

/**
 * OpenRegister Search Backend Settings Handler
 *
 * This file contains the handler class for managing search backend configuration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use RuntimeException;
use InvalidArgumentException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Handler for search backend settings operations.
 *
 * This handler is responsible for managing which search backend is active
 * (Solr, Elasticsearch, etc.) and providing configuration for the active backend.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class SearchBackendHandler
{
    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Application name
     *
     * @var string
     */
    private string $appName;

    /**
     * Constructor for SearchBackendHandler
     *
     * @param IConfig         $config  Configuration service.
     * @param LoggerInterface $logger  Logger.
     * @param string          $appName Application name.
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        LoggerInterface $logger,
        string $appName = 'openregister'
    ) {
        $this->config  = $config;
        $this->logger  = $logger;
        $this->appName = $appName;
    }//end __construct()

    /**
     * Get search backend configuration.
     *
     * Returns which search backend is currently active (solr, elasticsearch, etc).
     *
     * @return array Backend configuration with 'active' key.
     *
     * @throws \RuntimeException If backend configuration retrieval fails.
     */
    public function getSearchBackendConfig(): array
    {
        try {
            $backendConfig = $this->config->getAppValue($this->appName, 'search_backend', '');

            if (empty($backendConfig) === true) {
                return [
                    'active'    => 'solr',
                // Default to Solr for backward compatibility.
                    'available' => ['solr', 'elasticsearch'],
                ];
            }

            return json_decode($backendConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve search backend configuration: ' . $e->getMessage());
        }
    }//end getSearchBackendConfig()

    /**
     * Update search backend configuration.
     *
     * Sets which search backend should be active.
     *
     * @param string $backend Backend name ('solr', 'elasticsearch', etc).
     *
     * @return (int|string[])[] Updated backend configuration.
     *
     * @throws \RuntimeException If backend configuration update fails.
     *
     * @psalm-return array{active: 'elasticsearch'|'solr', available: list{'solr', 'elasticsearch'}, updated: int<1, max>}
     */
    public function updateSearchBackendConfig(string $backend): array
    {
        try {
            $availableBackends = ['solr', 'elasticsearch'];

            if (in_array($backend, $availableBackends, true) === false) {
                throw new InvalidArgumentException(
                    "Invalid backend '$backend'. Must be one of: " . implode(', ', $availableBackends)
                );
            }

            $backendConfig = [
                'active'    => $backend,
                'available' => $availableBackends,
                'updated'   => time(),
            ];

            $this->config->setAppValue($this->appName, 'search_backend', json_encode($backendConfig));

            $this->logger->info(
                'Search backend changed to: ' . $backend,
                [
                    'app'     => 'openregister',
                    'backend' => $backend,
                ]
            );

            return $backendConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update search backend configuration: ' . $e->getMessage());
        }//end try
    }//end updateSearchBackendConfig()
}//end class
