<?php

/**
 * IntegrationRegistry
 *
 * Holds all registered IntegrationProvider instances and exposes
 * the enabled set filtered by required-app availability (ADR-019).
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
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use InvalidArgumentException;

/**
 * Registry of all active IntegrationProvider instances (ADR-019).
 *
 * Providers are added via addProvider() at boot time. The enabled set
 * is the subset for which isEnabled() returns true.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
 */
class IntegrationRegistry
{

    /**
     * Registered providers keyed by id.
     *
     * @var array<string, IntegrationProvider>
     */
    private array $providers = [];

    /**
     * Register a provider.
     *
     * Silently skips if a provider with the same id is already registered
     * (first-wins collision policy per ADR-019 AD-13).
     *
     * @param IntegrationProvider $provider Provider to register
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
     */
    public function addProvider(IntegrationProvider $provider): void
    {
        $id = $provider->getId();
        if (isset($this->providers[$id]) === true) {
            return;
        }

        $this->providers[$id] = $provider;
    }//end addProvider()

    /**
     * Return all registered providers regardless of enabled state.
     *
     * @return array<string, IntegrationProvider>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
     */
    public function getAll(): array
    {
        return $this->providers;
    }//end getAll()

    /**
     * Return only enabled providers (required NC app installed).
     *
     * @return array<string, IntegrationProvider>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
     */
    public function getEnabled(): array
    {
        return array_filter(
            array: $this->providers,
            callback: static fn(IntegrationProvider $p) => $p->isEnabled(),
        );
    }//end getEnabled()

    /**
     * Return all registered provider ids.
     *
     * @return string[]
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
     */
    public function listIds(): array
    {
        return array_keys(array: $this->providers);
    }//end listIds()

    /**
     * Get a single provider by id.
     *
     * @param string $id Provider id
     *
     * @throws InvalidArgumentException When no provider with that id exists
     *
     * @return IntegrationProvider
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
     */
    public function get(string $id): IntegrationProvider
    {
        if (isset($this->providers[$id]) === false) {
            throw new InvalidArgumentException("No integration provider registered with id '{$id}'.");
        }

        return $this->providers[$id];
    }//end get()

    /**
     * Whether a provider with the given id is registered.
     *
     * @param string $id Provider id
     *
     * @return bool
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.5
     */
    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }//end has()
}//end class
