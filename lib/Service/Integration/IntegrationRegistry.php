<?php

/**
 * IntegrationRegistry — discovers and dispatches to IntegrationProvider
 * implementations registered at app bootstrap.
 *
 * Acts as stage 1 of the three-stage filter (AD-5):
 *   1. registry — what providers exist on this instance
 *   2. schema   — which of those are relevant for a given schema (Schema::linkedTypes)
 *   3. component — which of those should show on this surface (CnObjectSidebar etc.)
 *
 * Registration model: each app that ships an IntegrationProvider calls
 * `IntegrationRegistry::addProvider()` from its own `Application::register()`
 * hook. The registry is a single per-request service (registered via NC's
 * IRegistrationContext::registerService with `$shared=true`) so all apps
 * see the same instance.
 *
 * The spec (proposal.md AD-1) refers to this as "DI-tag-based" — that's
 * the intent, but modern Nextcloud doesn't expose a public
 * `IAppContainer::queryAll(<tag>)` method, so we use explicit
 * registration at bootstrap. The semantics are identical: providers
 * declare themselves, the registry collects them, the rest of the
 * codebase reads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use Psr\Log\LoggerInterface;

/**
 * Registry of all IntegrationProvider implementations on this NC instance.
 *
 * Providers register via `addProvider()` from their owning app's
 * bootstrap. Duplicate ids: first registration wins, second logs a
 * warning (AD-13). External providers without a declared OpenConnector
 * source are rejected at registration time so misconfigurations fail
 * at bootstrap, not on first call.
 */
class IntegrationRegistry
{

    /**
     * Registered providers, keyed by id.
     *
     * @var array<string, IntegrationProvider>
     */
    private array $providers = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger for collision and config warnings.
     *
     * @return void
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register a provider with the registry.
     *
     * Apps call this from their own `Application::register()` hook,
     * typically wrapping the call in a factory so the provider is only
     * instantiated when its dependencies are ready:
     *
     * ```php
     * $context->registerService(MyProvider::class, function ($c) { ... });
     * \OC::$server->get(IntegrationRegistry::class)->addProvider(
     *     \OC::$server->get(MyProvider::class)
     * );
     * ```
     *
     * Validation rules:
     *   - duplicate id: first registration wins, second logs a warning;
     *   - external storage without OpenConnector source: rejected with
     *     a warning.
     *
     * @param IntegrationProvider $provider The provider to register.
     *
     * @return bool True when the provider was accepted, false when
     *              rejected (duplicate or misconfigured).
     */
    public function addProvider(IntegrationProvider $provider): bool
    {
        $id = $provider->getId();
        if (isset($this->providers[$id]) === true) {
            $this->logger->warning(
                sprintf(
                    '[IntegrationRegistry] duplicate provider id "%s" — keeping first registration',
                    $id
                )
            );
            return false;
        }

        // External providers MUST declare an OpenConnector source —
        // the registry rejects mis-configured externals so the
        // failure surfaces at boot, not on first call.
        if ($provider->getStorageStrategy() === 'external'
            && $provider->getOpenConnectorSource() === null
        ) {
            $this->logger->warning(
                sprintf(
                    '[IntegrationRegistry] provider "%s" declares storage=external but no OpenConnector source — skipping',
                    $id
                )
            );
            return false;
        }

        $this->providers[$id] = $provider;
        return true;
    }//end addProvider()

    /**
     * Replace the entire provider set in one call (test seam).
     *
     * Useful for unit tests that don't want to spin up app bootstraps.
     * Production code SHOULD use `addProvider()` instead.
     *
     * @param array<int, IntegrationProvider> $providers Provider instances.
     *
     * @return void
     */
    public function withProviders(array $providers): void
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }//end withProviders()

    /**
     * List every registered provider, irrespective of isEnabled().
     *
     * @return array<int, IntegrationProvider>
     */
    public function list(): array
    {
        return array_values($this->providers);
    }//end list()

    /**
     * Return the ids of every registered provider.
     *
     * Used by `Schema::validateLinkedTypesValue()` (task 7) as the
     * authoritative existence check, replacing the hardcoded
     * `VALID_LINKED_TYPES` constant.
     *
     * @return array<int, string>
     */
    public function listIds(): array
    {
        return array_keys($this->providers);
    }//end listIds()

    /**
     * Look up a provider by id.
     *
     * @param string $id Stable integration id (e.g. 'files', 'xwiki').
     *
     * @return IntegrationProvider|null Provider, or null when unknown.
     */
    public function get(string $id): ?IntegrationProvider
    {
        return $this->providers[$id] ?? null;
    }//end get()

    /**
     * Whether the given id is a registered integration.
     *
     * Used by Schema::validateLinkedTypesValue() (task 7) as part of
     * the registry-driven validation path. Distinct from `get()` to
     * keep the schema validator's read intent obvious.
     *
     * @param string $id Stable integration id to check.
     *
     * @return bool True when the id is registered.
     */
    public function isValidIntegrationId(string $id): bool
    {
        return isset($this->providers[$id]);
    }//end isValidIntegrationId()

    /**
     * Return only the providers that are currently usable.
     *
     * A provider is "enabled" when `isEnabled()` returns true —
     * typically meaning its required NC app is installed and external
     * providers have their OpenConnector source configured.
     *
     * @return array<int, IntegrationProvider>
     */
    public function getEnabled(): array
    {
        $enabled = [];
        foreach ($this->providers as $provider) {
            if ($provider->isEnabled() === true) {
                $enabled[] = $provider;
            }
        }

        return $enabled;
    }//end getEnabled()

}//end class
