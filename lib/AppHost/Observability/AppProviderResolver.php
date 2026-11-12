<?php

/**
 * OpenRegister AppHost — App Provider Resolver
 *
 * Resolves an app's escape-hatch provider (IMetricsProvider, IHealthCheckProvider) from the calling app's own container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use Psr\Container\ContainerInterface;

/**
 * Finds the provider an app registered under `<interface>::<appId>`.
 *
 * The alias lives in the CALLING app's own container: Nextcloud app containers
 * are isolated, so OpenRegister's container never sees another app's
 * registrations (#390). OpenRegister's container is the fallback for an app
 * without a registered container of its own.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.5
 */
class AppProviderResolver {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface  $container OpenRegister's container (fallback lookup).
	 * @param AppContainerLocator $locator   Reaches the calling app's own container.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly AppContainerLocator $locator,
	) {
	}//end __construct()

	/**
	 * The provider an app registered for an interface.
	 *
	 * @param string       $appId     Calling app id.
	 * @param class-string $interface The provider interface.
	 *
	 * @return object|null The provider, or null when the app registered none of that type.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.5
	 */
	public function resolve(string $appId, string $interface): ?object {
		$alias     = $interface . '::' . $appId;
		$container = $this->locator->findOr(appId: $appId, fallback: $this->container);
		if ($container->has($alias) === false) {
			return null;
		}

		$provider = $container->get($alias);
		if (($provider instanceof $interface) === false) {
			return null;
		}

		return $provider;
	}//end resolve()

	/**
	 * OpenRegister's own container.
	 *
	 * @return ContainerInterface The container.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-2.1
	 */
	public function own(): ContainerInterface {
		return $this->container;
	}//end own()
}//end class
