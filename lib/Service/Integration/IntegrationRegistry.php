<?php

/**
 * OpenRegister IntegrationRegistry
 *
 * Central registry for all pluggable integration providers. Providers
 * self-register at boot via addProvider() rather than a DI tag (Nextcloud
 * has no public queryAll for tagged services).
 *
 * @category Integration
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Registry that manages all available integration providers.
 *
 * Application.php calls bootBuiltinIntegrationProviders() at boot to add
 * all built-in providers. Third-party apps may call addProvider() from
 * their own boot() method to extend the registry.
 */
class IntegrationRegistry
{

    /**
     * Registered providers keyed by provider ID.
     *
     * @var array<string, IntegrationProviderInterface>
     */
    private array $providers = [];

    /**
     * Constructor for IntegrationRegistry.
     *
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Add a provider to the registry. First registration wins (skip-on-collision).
     *
     * @param IntegrationProviderInterface $provider Provider instance
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
                'IntegrationRegistry: skip duplicate provider registration',
                ['id' => $id]
            );

            return;
        }

        if ($provider->getLabel() === '' || $provider->getIcon() === '') {
            throw new InvalidArgumentException(
                "Provider '{$id}' must declare a non-empty label and icon."
            );
        }

        $this->providers[$id] = $provider;

        $this->logger->debug('IntegrationRegistry: registered provider', ['id' => $id]);
    }//end addProvider()

    /**
     * Retrieve all registered providers.
     *
     * @return array<string, IntegrationProviderInterface>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-5
     */
    public function all(): array
    {
        return $this->providers;
    }//end all()

    /**
     * Retrieve a single provider by ID, or null if not registered.
     *
     * @param string $id Provider ID
     *
     * @return IntegrationProviderInterface|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-5
     */
    public function get(string $id): ?IntegrationProviderInterface
    {
        return $this->providers[$id] ?? null;
    }//end get()

    /**
     * Return IDs of all enabled providers (isEnabled() === true).
     *
     * @return string[]
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-5
     */
    public function enabledIds(): array
    {
        return array_keys(
            array_filter(
                $this->providers,
                static fn (IntegrationProviderInterface $provider): bool => $provider->isEnabled()
            )
        );
    }//end enabledIds()
}//end class
