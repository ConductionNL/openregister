<?php

/**
 * Integration Registry
 *
 * Central registry for all integration providers. Providers are added via
 * addProvider() at boot() time. The registry exposes enabled providers,
 * resolves providers by ID, and lists known integration IDs for schema
 * validation (replaces the hardcoded LinkedEntityService::TYPE_COLUMN_MAP).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use Psr\Log\LoggerInterface;

/**
 * Registry for integration providers.
 *
 * Providers register themselves at boot via addProvider(). The first provider
 * registered for a given ID wins (skip-on-collision policy) so consuming apps
 * can override built-in providers.
 */
class IntegrationRegistry
{

    /**
     * Map of provider ID → provider instance.
     *
     * @var array<string, IntegrationProviderInterface>
     */
    private array $providers = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Register a provider. First-wins on collision.
     *
     * @param IntegrationProviderInterface $provider The provider to register.
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-5
     */
    public function addProvider(IntegrationProviderInterface $provider): void
    {
        $id = $provider->getId();

        if (isset($this->providers[$id]) === true) {
            $this->logger->debug(
                message: "[IntegrationRegistry] Skip duplicate provider '{$id}' — first wins."
            );
            return;
        }

        $this->providers[$id] = $provider;
    }//end addProvider()

    /**
     * Get all registered providers.
     *
     * @return list<IntegrationProviderInterface>
     */
    public function getAll(): array
    {
        return array_values($this->providers);
    }//end getAll()

    /**
     * Get only enabled providers (required NC app installed).
     *
     * @return list<IntegrationProviderInterface>
     */
    public function getEnabled(): array
    {
        return array_values(
            array_filter(
                array: $this->providers,
                callback: static fn(IntegrationProviderInterface $p) => $p->isEnabled()
            )
        );
    }//end getEnabled()

    /**
     * Retrieve a provider by ID.
     *
     * @param string $id Provider ID.
     *
     * @return IntegrationProviderInterface|null Provider or null when not found.
     */
    public function get(string $id): ?IntegrationProviderInterface
    {
        return $this->providers[$id] ?? null;
    }//end get()

    /**
     * List all registered provider IDs (for schema validator).
     *
     * @return list<string>
     */
    public function listIds(): array
    {
        return array_keys($this->providers);
    }//end listIds()
}//end class
