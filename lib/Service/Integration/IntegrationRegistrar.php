<?php

/**
 * The integration registry registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in IntegrationWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the integration registry, the external integration router, the
 * built-in integration providers and the services around them, and adds the
 * providers and the leaf loader to the registry at boot.
 *
 * Application::register() calls register() once; Application::boot() calls boot() once.
 */
final class IntegrationRegistrar {

	/**
	 * Contract => the service that implements it.
	 *
	 * Callers that only read the registry type against its read-side contracts;
	 * registration (addProvider, withProviders, setLeafLoader) stays on
	 * IntegrationRegistry. The lookup and dispatch controllers type against the
	 * provider contracts.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = IntegrationWiring::BINDINGS;

	/**
	 * Register the IntegrationRegistry + ExternalIntegrationRouter
	 * services used by the pluggable integration registry.
	 *
	 * Both are shared per-request singletons. Apps that ship their own
	 * IntegrationProvider implementations register them via
	 * `$this->container->get(IntegrationRegistry::class)->addProvider(...)`
	 * from their own Application::register() hook — see
	 * `openspec/changes/pluggable-integration-registry/proposal.md` (AD-1).
	 * The read-side contracts in BINDINGS alias the same shared registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		IntegrationWiring::register(context: $context);
	}//end register()

	/**
	 * Resolve every BuiltinProviders/* class and register it with the
	 * shared IntegrationRegistry.
	 *
	 * Kept separate from the DI registration in register() because
	 * addProvider() needs the registry instance — i.e. it has to run after
	 * the container has fully bootstrapped. `boot()` is the canonical
	 * post-registration hook for that.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function boot($server): void {
		IntegrationWiring::boot(server: $server);
	}//end boot()

	/**
	 * Install the lazy leaf loader on the shared IntegrationRegistry.
	 *
	 * The loader is a closure that reads the LeafRegistry catalogue, which
	 * dispatches `RegisterLeafProvidersEvent` once and lands any data-provider
	 * leaves on the IntegrationRegistry. Installing it here (rather than
	 * dispatching now) keeps the collect-event LAZY: it fires only when
	 * something actually reads the registry or the leaf catalogue in a request
	 * (ADR-066). Guarded so a registry-less build still boots.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public static function bootLeafRegistry($server): void {
		IntegrationWiring::bootLeafRegistry(server: $server);
	}//end bootLeafRegistry()
}//end class
