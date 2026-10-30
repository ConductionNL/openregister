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

use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCA\OpenRegister\Service\Task\TaskMetricsProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the AppHost observability services: the manifest loader, the health
 * check executor, the metric sources, the Prometheus renderer, the metrics
 * engine and the health and metrics controllers of the hosting app.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
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
		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class,
			function (ContainerInterface $container) {
				return new ManifestLoader(
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor::class,
			function (ContainerInterface $container) {
				return new HealthCheckExecutor(
					db: $container->get('OCP\IDBConnection'),
					tempManager: $container->get('OCP\ITempManager'),
					appManager: $container->get('OCP\App\IAppManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					container: $container,
					locator: $container->get(AppContainerLocator::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource::class,
			function (ContainerInterface $container) {
				return new ObjectMetricSource(
					objectService: $container->get(\OCA\OpenRegister\Service\ObjectService::class),
					registerMapper: $container->get(RegisterMapperInterface::class),
					schemaMapper: $container->get(SchemaMapperInterface::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource::class,
			function (ContainerInterface $container) {
				return new TableMetricSource(
					db: $container->get('OCP\IDBConnection'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource::class,
			function (ContainerInterface $container) {
				return new AppConfigMetricSource(
					appConfig: $container->get('OCP\IAppConfig')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource::class,
			function (ContainerInterface $container) {
				return new ProviderMetricSource(
					container: $container,
					locator: $container->get(AppContainerLocator::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\PrometheusRenderer::class,
			function (ContainerInterface $container) {
				return new PrometheusRenderer();
			}
		);

		// OpenRegister's own IMetricsProvider, under the alias
		// ProviderMetricSource looks up for this app id. It carries the one
		// metric the declarative kinds cannot express: overdue is
		// COALESCE(due_at, expires_at) < now, and a tableCount filter
		// compares one column to a literal. Registered here, in OR's own
		// container, because the source resolves the alias from the CALLING
		// app's container (#390) and OR is the calling app when it scrapes
		// itself.
		$context->registerService(
			\OCA\OpenRegister\AppHost\IMetricsProvider::class . '::' . $appId,
			function (ContainerInterface $container) {
				return new TaskMetricsProvider(
					mapper: $container->get(\OCA\OpenRegister\Db\TaskMapper::class),
					temporal: $container->get(\OCA\OpenRegister\Service\Task\TaskTemporalProjection::class)
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\AppHost\Observability\MetricsEngine::class,
			function (ContainerInterface $container) {
				return new MetricsEngine(
					objectSource: $container->get(\OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource::class),
					tableSource: $container->get(\OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource::class),
					appConfigSource: $container->get(\OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource::class),
					providerSource: $container->get(\OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource::class),
					renderer: $container->get(\OCA\OpenRegister\AppHost\Observability\PrometheusRenderer::class),
					manifestLoader: $container->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
					cacheFactory: $container->get('OCP\ICacheFactory'),
					config: $container->get('OCP\IConfig'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Self-dogfood the GET /api/health and /api/metrics routes at the
		// generic AppHost controllers. appinfo/routes.php aliases these URLs
		// to route names 'AppHost\Controller\GenericMetrics#index' /
		// 'AppHost\Controller\GenericHealth#index' — NC's RouteParser turns
		// that into the literal DI lookup key
		// 'AppHost\Controller\GenericHealthController' (buildControllerName()
		// appends 'Controller' to the route-name segment verbatim; it does
		// NOT prefix the app namespace for a route name that already
		// contains a backslash). Without an explicit binding under that
		// exact key, \OC\AppFramework\App::main() failed to resolve the
		// controller, was caught as a QueryException, and — because the
		// string coincidentally contains '\Controller\' — misread as "a
		// global route pointing at a disabled app", surfacing as a 503
		// "App controller is not enabled" HTML error page instead of the
		// health/metrics JSON contract (ADR-006). Every OTHER AppHost route
		// (dashboard/preferences/settings) is bound this same way, per
		// controller, by \OCA\OpenRegister\AppHost\Bootstrap for LEAF apps
		// that call Bootstrap::register(); OpenRegister's own self-adoption
		// never calls Bootstrap::register() and must bind these two
		// controllers directly.
		$context->registerService(
			'AppHost\\Controller\\GenericHealthController',
			function (ContainerInterface $container) use ($appId) {
				return new GenericHealthController(
					appName: $appId,
					request: $container->get('OCP\IRequest'),
					manifestLoader: $container->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
					executor: $container->get(\OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor::class)
				);
			}
		);

		$context->registerService(
			'AppHost\\Controller\\GenericMetricsController',
			function (ContainerInterface $container) use ($appId) {
				return new GenericMetricsController(
					appName: $appId,
					request: $container->get('OCP\IRequest'),
					manifestLoader: $container->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
					engine: $container->get(\OCA\OpenRegister\AppHost\Observability\MetricsEngine::class)
				);
			}
		);
	}//end register()
}//end class
