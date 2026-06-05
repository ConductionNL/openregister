<?php

/**
 * OpenRegister Integration Registry
 *
 * Central registry for all integration providers. Collects providers registered
 * via DI tag 'IntegrationProvider' and exposes them for UI rendering and schema
 * validation. Implements the three-stage filter from ADR-019.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use Psr\Log\LoggerInterface;

/**
 * Central registry for all integration providers.
 *
 * Stage-1 filter: getEnabled() returns only providers where isEnabled() is true.
 * Stage-2 filter (schema.linkedTypes whitelist) is applied by the schema layer.
 * Stage-3 filter (page-level excludeIntegrations) is applied by the frontend.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-4
 */
class IntegrationRegistry
{

    /**
     * Registered providers indexed by id.
     *
     * @var array<string, IntegrationProvider>
     */
    private array $providers = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register a provider.
     *
     * Called during DI setup for each IntegrationProvider-tagged service.
     *
     * @param IntegrationProvider $provider The provider to register.
     *
     * @return void
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function register(IntegrationProvider $provider): void
    {
        $id = $provider->getId();

        if (array_key_exists(key: $id, array: $this->providers) === true) {
            $this->logger->warning(
                message: '[IntegrationRegistry] Duplicate integration provider id, overwriting',
                context: ['id' => $id]
            );
        }

        $this->providers[$id] = $provider;
    }//end register()

    /**
     * Get all currently enabled providers (Stage-1 filter).
     *
     * @return IntegrationProvider[] Enabled providers.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function getEnabled(): array
    {
        return array_values(
            array: array_filter(
                array: $this->providers,
                callback: static function (IntegrationProvider $p): bool {
                    return $p->isEnabled();
                }
            )
        );
    }//end getEnabled()

    /**
     * Get a specific provider by id, or null if not registered.
     *
     * @param string $id The integration id.
     *
     * @return IntegrationProvider|null The provider or null.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function get(string $id): ?IntegrationProvider
    {
        return $this->providers[$id] ?? null;
    }//end get()

    /**
     * List all registered integration ids (including disabled ones).
     *
     * Used by schema validator to verify referenceType values.
     *
     * @return string[] Array of integration ids.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function listIds(): array
    {
        return array_keys(array: $this->providers);
    }//end listIds()

    /**
     * Get OCS capabilities data for all enabled integrations.
     *
     * Each integration entry includes its id, label, group, authStatus if applicable.
     *
     * @return array<string, array<string,mixed>> Map of id => capability data.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-4
     */
    public function getCapabilities(): array
    {
        $capabilities = [];

        foreach ($this->getEnabled() as $provider) {
            $entry = [
                'id'         => $provider->getId(),
                'label'      => $provider->getLabel(),
                'icon'       => $provider->getIcon(),
                'group'      => $provider->getGroup(),
                'authStatus' => $provider->health(),
                'storage'    => $provider->getStorageStrategy(),
            ];

            $authReqs = $provider->authRequirements();
            if ($authReqs !== null) {
                $entry['authRequirements'] = $authReqs;
            }

            $capabilities[$provider->getId()] = $entry;
        }

        return $capabilities;
    }//end getCapabilities()

}//end class
