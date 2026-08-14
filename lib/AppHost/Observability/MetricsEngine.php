<?php

/**
 * OpenRegister AppHost — Metrics Engine
 *
 * Orchestrates the closed set of metric sources for an app's manifest,
 * always prepends the implicit `{app}_info` and `{app}_up` metrics, honours
 * per-metric `cacheTtl` via the distributed cache, and renders the whole set
 * to Prometheus text 0.0.4. Exposition format and the implicit metrics are
 * engine-owned so adopting apps cannot drift them.
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

use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renders an app's declarative + implicit metrics to Prometheus text.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
 */
class MetricsEngine {
	/**
	 * Constructor.
	 *
	 * @param ObjectMetricSource $objectSource objectCount/objectSum.
	 * @param TableMetricSource $tableSource tableCount.
	 * @param AppConfigMetricSource $appConfigSource appConfig.
	 * @param ProviderMetricSource $providerSource provider escape hatch.
	 * @param PrometheusRenderer $renderer Text renderer.
	 * @param ManifestLoader $manifestLoader App version resolution.
	 * @param ICacheFactory $cacheFactory Distributed cache (cacheTtl).
	 * @param IConfig $config NC version (implicit info).
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ObjectMetricSource $objectSource,
		private readonly TableMetricSource $tableSource,
		private readonly AppConfigMetricSource $appConfigSource,
		private readonly ProviderMetricSource $providerSource,
		private readonly PrometheusRenderer $renderer,
		private readonly ManifestLoader $manifestLoader,
		private readonly ICacheFactory $cacheFactory,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render the full Prometheus exposition for an app's manifest.
	 *
	 * @param ObservabilityManifest $manifest The app's observability config.
	 *
	 * @return string Prometheus text 0.0.4.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
	 */
	public function render(ObservabilityManifest $manifest): string {
		$samples = $this->implicitMetrics(appId: $manifest->appId);

		foreach ($manifest->metrics as $descriptor) {
			foreach ($this->collectDescriptor(appId: $manifest->appId, descriptor: $descriptor) as $sample) {
				$samples[] = $sample;
			}
		}

		return $this->renderer->render(appId: $manifest->appId, samples: $samples);
	}//end render()

	/**
	 * Collect one descriptor, honouring cacheTtl.
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor Metric descriptor.
	 *
	 * @return MetricSample[]
	 */
	private function collectDescriptor(string $appId, MetricDescriptor $descriptor): array {
		if ($descriptor->cacheTtl <= 0 || $this->cacheFactory->isAvailable() === false) {
			return $this->dispatch(appId: $appId, descriptor: $descriptor);
		}

		$cache = $this->cacheFactory->createDistributed('openregister_apphost_metrics');
		$cacheKey = $appId . ':' . $descriptor->name;

		$cached = $cache->get($cacheKey);
		if (is_string($cached) === true) {
			$restored = $this->decodeSamples(payload: $cached);
			if ($restored !== null) {
				return $restored;
			}
		}

		$samples = $this->dispatch(appId: $appId, descriptor: $descriptor);
		$cache->set($cacheKey, $this->encodeSamples(samples: $samples), $descriptor->cacheTtl);
		return $samples;
	}//end collectDescriptor()

	/**
	 * Route a descriptor to its source.
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor Metric descriptor.
	 *
	 * @return MetricSample[]
	 */
	private function dispatch(string $appId, MetricDescriptor $descriptor): array {
		try {
			switch ($descriptor->kind) {
				case 'objectCount':
				case 'objectSum':
					return $this->objectSource->collect(appId: $appId, descriptor: $descriptor);
				case 'tableCount':
					return $this->tableSource->collect(appId: $appId, descriptor: $descriptor);
				case 'appConfig':
					return $this->appConfigSource->collect(appId: $appId, descriptor: $descriptor);
				case 'provider':
					return $this->providerSource->collect(appId: $appId, descriptor: $descriptor);
				default:
					return [];
			}
		} catch (Throwable $e) {
			$logMessage = sprintf(
				'[AppHost\\Metrics] metric "%s" (kind %s, app %s) failed: %s',
				$descriptor->name,
				$descriptor->kind,
				$appId,
				$e->getMessage()
			);
			$this->logger->warning(
				message: $logMessage,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}//end try
	}//end dispatch()

	/**
	 * Build the implicit `{app}_info` and `{app}_up` metrics.
	 *
	 * @param string $appId Calling app id.
	 *
	 * @return MetricSample[]
	 */
	private function implicitMetrics(string $appId): array {
		$version = $this->manifestLoader->appVersion(appId: $appId);
		$ncVersion = $this->config->getSystemValueString('version', 'unknown');

		$info = new MetricSample(
			name: 'info',
			type: 'gauge',
			help: 'Application information',
			samples: [
				[
					'labels' => [
						'version' => $version,
						'php_version' => PHP_VERSION,
						'nextcloud_version' => $ncVersion,
					],
					'value' => 1,
				],
			]
		);

		$up = MetricSample::single(name: 'up', type: 'gauge', help: 'Whether the application is up', value: 1);

		return [$info, $up];
	}//end implicitMetrics()

	/**
	 * Encode samples for the cache.
	 *
	 * @param MetricSample[] $samples Samples.
	 *
	 * @return string JSON payload.
	 */
	private function encodeSamples(array $samples): string {
		$plain = [];
		foreach ($samples as $sample) {
			$plain[] = [
				'name' => $sample->name,
				'type' => $sample->type,
				'help' => $sample->help,
				'samples' => $sample->samples,
			];
		}

		return (string)json_encode($plain);
	}//end encodeSamples()

	/**
	 * Decode cached samples.
	 *
	 * @param string $payload JSON payload.
	 *
	 * @return MetricSample[]|null Null when the payload is unusable.
	 */
	private function decodeSamples(string $payload): ?array {
		$decoded = json_decode($payload, associative: true);
		if (is_array($decoded) === false) {
			return null;
		}

		$samples = [];
		foreach ($decoded as $row) {
			if (is_array($row) === false || isset($row['name'], $row['type'], $row['samples']) === false) {
				return null;
			}

			$rowSamples = [];
			if (is_array($row['samples']) === true) {
				$rowSamples = $row['samples'];
			}

			$samples[] = new MetricSample(
				name: (string)$row['name'],
				type: (string)$row['type'],
				help: (string)($row['help'] ?? ''),
				samples: $rowSamples
			);
		}

		return $samples;
	}//end decodeSamples()
}//end class
