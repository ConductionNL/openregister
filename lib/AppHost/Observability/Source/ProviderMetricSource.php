<?php

/**
 * OpenRegister AppHost — Provider Metric Source
 *
 * Executes the `provider` escape-hatch descriptor: resolves the calling app's
 * IMetricsProvider via the container alias
 * `OCA\OpenRegister\AppHost\IMetricsProvider::{appId}` (ADR-035 pattern) and
 * merges its MetricSample output into the response. Used by truly imperative
 * metrics (shillinq customer-bridge, nldesign CSS parsing).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability\Source
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

namespace OCA\OpenRegister\AppHost\Observability\Source;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricSourceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provider escape-hatch source via container-alias discovery.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.5
 */
class ProviderMetricSource implements MetricSourceInterface {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (fallback alias lookup).
	 * @param AppContainerLocator $locator Reaches the calling app's own container.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly AppContainerLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.5
	 */
	public function kind(): string {
		return 'provider';
	}//end kind()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor (no params).
	 *
	 * @return MetricSample[]
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.5
	 */
	public function collect(string $appId, MetricDescriptor $descriptor): array {
		$alias = IMetricsProvider::class . '::' . $appId;

		try {
			// Resolve the provider from the CALLING APP's own container — where
			// the app registered its `IMetricsProvider::<appId>` alias — not from
			// OpenRegister's container, which never sees another app's DI
			// registrations (Nextcloud app containers are isolated). Resolving
			// against `$this->container` here silently returned [] for every
			// consuming app (same root cause as the MCP-provider fix, #390).
			$container = $this->locator->findOr(appId: $appId, fallback: $this->container);

			if ($container->has($alias) === false) {
				return [];
			}

			$provider = $container->get($alias);
			if (($provider instanceof IMetricsProvider) === false) {
				return [];
			}

			$samples = [];
			foreach ($provider->metrics() as $sample) {
				if ($sample instanceof MetricSample) {
					$samples[] = $sample;
				}
			}

			return $samples;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[AppHost\\Metrics] provider for app "%s" threw: %s', $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}//end try
	}//end collect()
}//end class
