<?php

/**
 * The AppHost observability registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in ObservabilityWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the AppHost observability services: the manifest loader, the health
 * check executor, the metric sources, the Prometheus renderer, the metrics
 * engine and the health and metrics controllers of the hosting app.
 *
 * Application::register() calls register() once.
 */
final class ObservabilityRegistrar {

	/**
	 * Register the AppHost declarative observability engine (ADR-040).
	 *
	 * Wires the manifest loader, the health-check executor, the four metric
	 * sources, the Prometheus renderer and the metrics engine. The generic
	 * controllers are auto-resolved by Nextcloud's container (their `$appName`
	 * is supplied by the alias registration in each adopting leaf app's own
	 * Application.php). Services that need the DI container itself (lazy
	 * orAvailable + IMetricsProvider/IHealthCheckProvider alias discovery) get
	 * explicit factory closures; the rest auto-wire.
	 *
	 * @param IRegistrationContext $context The registration context.
	 * @param string               $appId   The id of the hosting app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-observability/spec.md
	 */
	public static function register(IRegistrationContext $context, string $appId): void {
		ObservabilityWiring::register(context: $context, appId: $appId);
	}//end register()
}//end class
