<?php

/**
 * The object source registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in ObjectSourceWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the object source registry, the built-in object source providers
 * and the DBAL services they use, and adds those providers to the registry.
 *
 * Application::register() calls register() once; Application::boot() calls boot() once.
 */
final class ObjectSourceRegistrar {

	/**
	 * Contract => the service that implements it, for callers outside the Service component.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = ObjectSourceWiring::BINDINGS;

	/**
	 * Register the ObjectSourceRegistry (shared), the built-in object source
	 * providers and the DBAL and Tables services they use.
	 *
	 * The registry is shared so `addProvider()` registrations made during
	 * `boot()` persist for the whole request; GetObject auto-wires it.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#1-provider-interface-registry
	 */
	public static function register(IRegistrationContext $context): void {
		ObjectSourceWiring::register(context: $context);
	}//end register()

	/**
	 * Register the built-in object-source providers with the shared
	 * ObjectSourceRegistry.
	 *
	 * Runs in boot() (post-registration) because addProvider() needs the
	 * registry instance. Guarded so one absent provider never takes the app
	 * down — a failing provider simply won't serve its bound schemas.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#5-caldav-vtodo-provider
	 */
	public static function boot($server): void {
		ObjectSourceWiring::boot(server: $server);
	}//end boot()
}//end class
