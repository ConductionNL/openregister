<?php

/**
 * ObjectSourceRegistry — discovers and resolves ObjectSourceProvider
 * implementations registered at app bootstrap.
 *
 * Mirrors IntegrationRegistry's registration model: each app that ships an
 * ObjectSourceProvider calls `addProvider()` from its own `Application` boot
 * hook. The registry is a single per-request shared service so every app sees
 * the same instance. Duplicate ids follow a first-wins policy and log a warning
 * (matching the integration-registry collision policy).
 *
 * The read path (GetObject) resolves a provider by the id declared in a schema's
 * `x-openregister-object-source.provider` and delegates find/findAll/count to it
 * when the provider is enabled.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Psr\Log\LoggerInterface;

/**
 * Registry of all ObjectSourceProvider implementations on this NC instance.
 */
class ObjectSourceRegistry {

	/**
	 * Registered providers, keyed by id.
	 *
	 * @var array<string, ObjectSourceProvider>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for collision warnings.
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
	 * Duplicate id: first registration wins, second logs a warning.
	 *
	 * @param ObjectSourceProvider $provider The provider to register.
	 *
	 * @return bool True when accepted, false when rejected (duplicate id).
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
	 */
	public function addProvider(ObjectSourceProvider $provider): bool {
		$id = $provider->getId();
		if (isset($this->providers[$id]) === true) {
			$this->logger->warning(
				sprintf(
					'[ObjectSourceRegistry] duplicate provider id "%s" — keeping first registration',
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
	 * @param array<int, ObjectSourceProvider> $providers Provider instances.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
	 */
	public function withProviders(array $providers): void {
		$this->providers = [];
		foreach ($providers as $provider) {
			$this->addProvider(provider: $provider);
		}
	}//end withProviders()

	/**
	 * Look up a provider by id.
	 *
	 * @param string $id The provider id (e.g. 'caldav-vtodo').
	 *
	 * @return ObjectSourceProvider|null Provider, or null when unknown.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
	 */
	public function get(string $id): ?ObjectSourceProvider {
		return $this->providers[$id] ?? null;
	}//end get()

	/**
	 * List every registered provider, irrespective of isEnabled().
	 *
	 * @return array<int, ObjectSourceProvider>
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
	 */
	public function list(): array {
		return array_values($this->providers);
	}//end list()

	/**
	 * Return the ids of every registered provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
	 */
	public function listIds(): array {
		return array_keys($this->providers);
	}//end listIds()
}//end class
