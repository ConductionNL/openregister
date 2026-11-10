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
use OCA\OpenRegister\AppHost\Observability\AppProviderResolver;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\LocalHealthChecks;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCA\OpenRegister\Service\Task\TaskMetricsProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The wiring behind ObservabilityRegistrar.
 *
 * ObservabilityRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the AppHost observability services: the manifest loader, the health
 * check executor, the metric sources, the Prometheus renderer, the metrics
 * engine and the health and metrics controllers of the hosting app.
 *
 * Application::register() calls register() once.
 */
final class ObservabilityWiring {

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
		self::registerEngineServices(context: $context);

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
				return $container->get(TaskMetricsProviderInterface::class);
			}
		);

		$context->registerService(MetricsEngine::class, self::metricsEngine(...));

		self::registerControllers(context: $context, appId: $appId);
	}//end register()

	/**
	 * Register the manifest loader, the health-check executor, the four metric
	 * sources and the Prometheus renderer, in that order.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerEngineServices(IRegistrationContext $context): void {
		$context->registerService(ManifestLoader::class, self::manifestLoader(...));
		$context->registerService(HealthCheckExecutor::class, self::healthCheckExecutor(...));
		$context->registerService(ObjectMetricSource::class, self::objectMetricSource(...));
		$context->registerService(TableMetricSource::class, self::tableMetricSource(...));
		$context->registerService(AppConfigMetricSource::class, self::appConfigMetricSource(...));
		$context->registerService(ProviderMetricSource::class, self::providerMetricSource(...));
		$context->registerService(
			PrometheusRenderer::class,
			function (ContainerInterface $container) {
				return new PrometheusRenderer();
			}
		);
	}//end registerEngineServices()

	/**
	 * Self-dogfood the GET /api/health and /api/metrics routes at the
	 * generic AppHost controllers.
	 *
	 * appinfo/routes.php aliases these URLs
	 * to route names 'AppHost\Controller\GenericMetrics#index' /
	 * 'AppHost\Controller\GenericHealth#index' — NC's RouteParser turns
	 * that into the literal DI lookup key
	 * 'AppHost\Controller\GenericHealthController' (buildControllerName()
	 * appends 'Controller' to the route-name segment verbatim; it does
	 * NOT prefix the app namespace for a route name that already
	 * contains a backslash). Without an explicit binding under that
	 * exact key, \OC\AppFramework\App::main() failed to resolve the
	 * controller, was caught as a QueryException, and — because the
	 * string coincidentally contains '\Controller\' — misread as "a
	 * global route pointing at a disabled app", surfacing as a 503
	 * "App controller is not enabled" HTML error page instead of the
	 * health/metrics JSON contract (ADR-006). Every OTHER AppHost route
	 * (dashboard/preferences/settings) is bound this same way, per
	 * controller, by \OCA\OpenRegister\AppHost\Bootstrap for LEAF apps
	 * that call Bootstrap::register(); OpenRegister's own self-adoption
	 * never calls Bootstrap::register() and must bind these two
	 * controllers directly.
	 *
	 * @param IRegistrationContext $context The registration context.
	 * @param string               $appId   The id of the hosting app.
	 *
	 * @return void
	 */
	private static function registerControllers(IRegistrationContext $context, string $appId): void {
		$context->registerService(
			'AppHost\\Controller\\GenericHealthController',
			function (ContainerInterface $container) use ($appId) {
				return self::healthController(container: $container, appId: $appId);
			}
		);

		$context->registerService(
			'AppHost\\Controller\\GenericMetricsController',
			function (ContainerInterface $container) use ($appId) {
				return self::metricsController(container: $container, appId: $appId);
			}
		);
	}//end registerControllers()

	/**
	 * Build the observability manifest loader.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return ManifestLoader
	 */
	private static function manifestLoader(ContainerInterface $container): ManifestLoader {
		return new ManifestLoader(
			appManager: $container->get('OCP\App\IAppManager'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end manifestLoader()

	/**
	 * Build the health-check executor.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return HealthCheckExecutor
	 */
	private static function healthCheckExecutor(ContainerInterface $container): HealthCheckExecutor {
		return new HealthCheckExecutor(
			localChecks: $container->get(LocalHealthChecks::class),
			providers: $container->get(AppProviderResolver::class),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end healthCheckExecutor()

	/**
	 * Build the object-count metric source.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return ObjectMetricSource
	 */
	private static function objectMetricSource(ContainerInterface $container): ObjectMetricSource {
		return new ObjectMetricSource(
			objectService: $container->get(\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::class),
			lookups: $container->get(SchemaScopeLookups::class)
		);
	}//end objectMetricSource()

	/**
	 * Build the table-count metric source.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return TableMetricSource
	 */
	private static function tableMetricSource(ContainerInterface $container): TableMetricSource {
		return new TableMetricSource(
			db: $container->get('OCP\IDBConnection'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end tableMetricSource()

	/**
	 * Build the app-config metric source.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return AppConfigMetricSource
	 */
	private static function appConfigMetricSource(ContainerInterface $container): AppConfigMetricSource {
		return new AppConfigMetricSource(
			appConfig: $container->get('OCP\IAppConfig')
		);
	}//end appConfigMetricSource()

	/**
	 * Build the provider metric source.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return ProviderMetricSource
	 */
	private static function providerMetricSource(ContainerInterface $container): ProviderMetricSource {
		return new ProviderMetricSource(
			providers: $container->get(AppProviderResolver::class),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end providerMetricSource()

	/**
	 * Build the metrics engine over the registered sources and renderer.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return MetricsEngine
	 */
	private static function metricsEngine(ContainerInterface $container): MetricsEngine {
		return new MetricsEngine(
			objectSource: $container->get(ObjectMetricSource::class),
			tableSource: $container->get(TableMetricSource::class),
			appConfigSource: $container->get(AppConfigMetricSource::class),
			providerSource: $container->get(ProviderMetricSource::class),
			renderer: $container->get(PrometheusRenderer::class),
			manifestLoader: $container->get(ManifestLoader::class),
			cacheFactory: $container->get('OCP\ICacheFactory'),
			config: $container->get('OCP\IConfig'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end metricsEngine()

	/**
	 * Build the generic health controller for the hosting app.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param string             $appId     The id of the hosting app.
	 *
	 * @return GenericHealthController
	 */
	private static function healthController(ContainerInterface $container, string $appId): GenericHealthController {
		return new GenericHealthController(
			appName: $appId,
			request: $container->get('OCP\IRequest'),
			manifestLoader: $container->get(ManifestLoader::class),
			executor: $container->get(HealthCheckExecutor::class)
		);
	}//end healthController()

	/**
	 * Build the generic metrics controller for the hosting app.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param string             $appId     The id of the hosting app.
	 *
	 * @return GenericMetricsController
	 */
	private static function metricsController(ContainerInterface $container, string $appId): GenericMetricsController {
		return new GenericMetricsController(
			appName: $appId,
			request: $container->get('OCP\IRequest'),
			manifestLoader: $container->get(ManifestLoader::class),
			engine: $container->get(MetricsEngine::class)
		);
	}//end metricsController()
}//end class
