<?php

/**
 * Golden pin for MetricsEngine::render().
 *
 * Records, for a fixed set of scenarios, the rendered exposition, every cache
 * write and every warning logged, before the engine was split into smaller
 * methods. The PHP version label is masked so no case depends on the PHP
 * build, and no case depends on today's date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\ObservabilityProbes;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every MetricsEngine::render() outcome recorded on the pre-refactor code.
 */
class MetricsEngineGoldenTest extends TestCase {
	/**
	 * Cache writes seen by the current scenario: key, payload, ttl.
	 *
	 * @var array<int, array{string, mixed, int}>
	 */
	private array $cacheWrites = [];

	/**
	 * Warnings logged by the current scenario.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Source calls seen by the current scenario, as `source:metric`.
	 *
	 * @var array<int, string>
	 */
	private array $sourceCalls = [];

	/**
	 * Distributed-cache prefixes requested by the current scenario.
	 *
	 * @var array<int, string>
	 */
	private array $cachePrefixes = [];

	/**
	 * The scenarios, each with its recorded outcome.
	 *
	 * @return array<string, array{string, string}> Scenario name and recorded JSON outcome.
	 */
	public static function recordedScenarios(): array {
		return [
			'no metrics, cache unavailable' => ['none', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n","writes":[],"warnings":[],"calls":[],"prefixes":[]}'],
			'every kind, uncached' => ['kinds', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_cases Help for cases\\n# TYPE app_cases gauge\\napp_cases{src=\\"object\\"} 5\\n\\n# HELP app_amount Help for amount\\n# TYPE app_amount gauge\\napp_amount{src=\\"object\\"} 6\\n\\n# HELP app_rows Help for rows\\n# TYPE app_rows gauge\\napp_rows{src=\\"table\\"} 4\\n\\n# HELP app_flag Help for flag\\n# TYPE app_flag gauge\\napp_flag{src=\\"appConfig\\"} 4\\n\\n# HELP app_custom Help for custom\\n# TYPE app_custom counter\\napp_custom{src=\\"provider\\"} 6\\n\\n","writes":[],"warnings":[],"calls":["object:app:cases","object:app:amount","table:app:rows","appConfig:app:flag","provider:app:custom"],"prefixes":[]}'],
			'unknown kind yields nothing' => ['unknown', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n","writes":[],"warnings":[],"calls":[],"prefixes":[]}'],
			'failing source is logged and skipped' => ['failing', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_cases Help for cases\\n# TYPE app_cases gauge\\napp_cases{src=\\"object\\"} 5\\n\\n# HELP app_amount Help for amount\\n# TYPE app_amount gauge\\napp_amount{src=\\"object\\"} 6\\n\\n# HELP app_flag Help for flag\\n# TYPE app_flag gauge\\napp_flag{src=\\"appConfig\\"} 4\\n\\n# HELP app_custom Help for custom\\n# TYPE app_custom counter\\napp_custom{src=\\"provider\\"} 6\\n\\n","writes":[],"warnings":["[AppHost\\\\Metrics] metric \\"rows\\" (kind tableCount, app app) failed: boom from table"],"calls":["object:app:cases","object:app:amount","table:app:rows","appConfig:app:flag","provider:app:custom"],"prefixes":[]}'],
			'ttl set but cache unavailable' => ['ttlNoCache', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits Help for hits\\n# TYPE app_hits counter\\napp_hits{src=\\"object\\"} 4\\n\\n","writes":[],"warnings":[],"calls":["object:app:hits"],"prefixes":[]}'],
			'cache miss writes payload' => ['miss', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits Help for hits\\n# TYPE app_hits counter\\napp_hits{src=\\"object\\"} 4\\n\\n","writes":[["app:hits","[{\\"name\\":\\"hits\\",\\"type\\":\\"counter\\",\\"help\\":\\"Help for hits\\",\\"samples\\":[{\\"labels\\":{\\"src\\":\\"object\\"},\\"value\\":4}]}]",60]],"warnings":[],"calls":["object:app:hits"],"prefixes":["openregister_apphost_metrics"]}'],
			'cache hit with valid payload' => ['hit', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits From cache\\n# TYPE app_hits counter\\napp_hits{a=\\"b\\"} 7\\n\\n","writes":[],"warnings":[],"calls":[],"prefixes":["openregister_apphost_metrics"]}'],
			'cache hit with non-array payload' => ['hitScalar', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits Help for hits\\n# TYPE app_hits counter\\napp_hits{src=\\"object\\"} 4\\n\\n","writes":[["app:hits","[{\\"name\\":\\"hits\\",\\"type\\":\\"counter\\",\\"help\\":\\"Help for hits\\",\\"samples\\":[{\\"labels\\":{\\"src\\":\\"object\\"},\\"value\\":4}]}]",60]],"warnings":[],"calls":["object:app:hits"],"prefixes":["openregister_apphost_metrics"]}'],
			'cache hit with row missing type' => ['hitBadRow', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits Help for hits\\n# TYPE app_hits counter\\napp_hits{src=\\"object\\"} 4\\n\\n","writes":[["app:hits","[{\\"name\\":\\"hits\\",\\"type\\":\\"counter\\",\\"help\\":\\"Help for hits\\",\\"samples\\":[{\\"labels\\":{\\"src\\":\\"object\\"},\\"value\\":4}]}]",60]],"warnings":[],"calls":["object:app:hits"],"prefixes":["openregister_apphost_metrics"]}'],
			'cache hit with non-array row' => ['hitNonArrayRow', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits Help for hits\\n# TYPE app_hits counter\\napp_hits{src=\\"object\\"} 4\\n\\n","writes":[["app:hits","[{\\"name\\":\\"hits\\",\\"type\\":\\"counter\\",\\"help\\":\\"Help for hits\\",\\"samples\\":[{\\"labels\\":{\\"src\\":\\"object\\"},\\"value\\":4}]}]",60]],"warnings":[],"calls":["object:app:hits"],"prefixes":["openregister_apphost_metrics"]}'],
			'cache hit with non-array samples and no help' => ['hitLoose', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_loose \\n# TYPE app_loose gauge\\n\\n","writes":[],"warnings":[],"calls":[],"prefixes":["openregister_apphost_metrics"]}'],
			'cache hit with non-string value' => ['hitNonString', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_hits Help for hits\\n# TYPE app_hits counter\\napp_hits{src=\\"object\\"} 4\\n\\n","writes":[["app:hits","[{\\"name\\":\\"hits\\",\\"type\\":\\"counter\\",\\"help\\":\\"Help for hits\\",\\"samples\\":[{\\"labels\\":{\\"src\\":\\"object\\"},\\"value\\":4}]}]",60]],"warnings":[],"calls":["object:app:hits"],"prefixes":["openregister_apphost_metrics"]}'],
			'zero ttl never touches the cache' => ['zeroTtl', '{"output":"# HELP app_info Application information\\n# TYPE app_info gauge\\napp_info{version=\\"1.2.3\\",php_version=\\"<php>\\",nextcloud_version=\\"version=unknown\\"} 1\\n\\n# HELP app_up Whether the application is up\\n# TYPE app_up gauge\\napp_up 1\\n\\n# HELP app_cases Help for cases\\n# TYPE app_cases gauge\\napp_cases{src=\\"object\\"} 5\\n\\n# HELP app_amount Help for amount\\n# TYPE app_amount gauge\\napp_amount{src=\\"object\\"} 6\\n\\n# HELP app_rows Help for rows\\n# TYPE app_rows gauge\\napp_rows{src=\\"table\\"} 4\\n\\n# HELP app_flag Help for flag\\n# TYPE app_flag gauge\\napp_flag{src=\\"appConfig\\"} 4\\n\\n# HELP app_custom Help for custom\\n# TYPE app_custom counter\\napp_custom{src=\\"provider\\"} 6\\n\\n","writes":[],"warnings":[],"calls":["object:app:cases","object:app:amount","table:app:rows","appConfig:app:flag","provider:app:custom"],"prefixes":[]}'],
		];
	}//end recordedScenarios()

	/**
	 * Each scenario still renders, caches and logs as recorded.
	 *
	 * @param string $scenario The scenario name.
	 * @param string $expected The recorded outcome, as JSON.
	 *
	 * @return void
	 */
	#[DataProvider('recordedScenarios')]
	public function testScenarioMatchesRecording(string $scenario, string $expected): void {
		$this->assertSame($expected, $this->runScenario($scenario));
	}//end testScenarioMatchesRecording()
	/**
	 * Run one scenario and render its outcome as JSON.
	 *
	 * @param string $scenario The scenario name.
	 *
	 * @return string The outcome.
	 */
	private function runScenario(string $scenario): string {
		[$descriptors, $available, $cached] = $this->scenarioSetup($scenario);

		$engine = $this->engine(available: $available, cached: $cached, failTable: ($scenario === 'failing'));
		$output = $engine->render(new ObservabilityManifest('app', new ObservabilityProbes([], $descriptors)));

		return (string)json_encode(
			[
				'output' => str_replace(PHP_VERSION, '<php>', $output),
				'writes' => $this->cacheWrites,
				'warnings' => $this->warnings,
				'calls' => $this->sourceCalls,
				'prefixes' => $this->cachePrefixes,
			]
		);
	}//end runScenario()

	/**
	 * The descriptors, cache availability and pre-filled cache entry of a scenario.
	 *
	 * @param string $scenario The scenario name.
	 *
	 * @return array{array<int, MetricDescriptor>, bool, mixed} The setup.
	 */
	private function scenarioSetup(string $scenario): array {
		$cachedOne = [new MetricDescriptor('hits', 'counter', 'objectCount', ['kind' => 'objectCount', 'schema' => 's'], 'Hits', 60)];
		$valid = '[{"name":"hits","type":"counter","help":"From cache","samples":[{"labels":{"a":"b"},"value":7}]}]';

		return match ($scenario) {
			'none' => [[], false, null],
			'kinds' => [$this->everyKind(), false, null],
			'unknown' => [[new MetricDescriptor('odd', 'gauge', 'bogus', ['kind' => 'bogus'])], false, null],
			'failing' => [$this->everyKind(), false, null],
			'ttlNoCache' => [$cachedOne, false, null],
			'miss' => [$cachedOne, true, null],
			'hit' => [$cachedOne, true, $valid],
			'hitScalar' => [$cachedOne, true, '"x"'],
			'hitBadRow' => [$cachedOne, true, '[{"name":"a","samples":[]}]'],
			'hitNonArrayRow' => [$cachedOne, true, '[5]'],
			'hitLoose' => [$cachedOne, true, '[{"name":"loose","type":"gauge","samples":"x"}]'],
			'hitNonString' => [$cachedOne, true, ['x']],
			'zeroTtl' => [$this->everyKind(), true, $valid],
			default => throw new RuntimeException('Unknown scenario '.$scenario),
		};
	}//end scenarioSetup()

	/**
	 * One uncached descriptor per supported kind.
	 *
	 * @return array<int, MetricDescriptor> The descriptors.
	 */
	private function everyKind(): array {
		return [
			new MetricDescriptor('cases', 'gauge', 'objectCount', ['kind' => 'objectCount', 'schema' => 'case']),
			new MetricDescriptor('amount', 'gauge', 'objectSum', ['kind' => 'objectSum', 'schema' => 'case', 'field' => 'x']),
			new MetricDescriptor('rows', 'gauge', 'tableCount', ['kind' => 'tableCount', 'table' => 't']),
			new MetricDescriptor('flag', 'gauge', 'appConfig', ['kind' => 'appConfig', 'key' => 'k']),
			new MetricDescriptor('custom', 'counter', 'provider', ['kind' => 'provider']),
		];
	}//end everyKind()

	/**
	 * Build the engine with recording doubles.
	 *
	 * @param bool  $available Whether the distributed cache is available.
	 * @param mixed $cached    The value the cache returns for any key.
	 * @param bool  $failTable Whether the table source throws.
	 *
	 * @return MetricsEngine The engine.
	 */
	private function engine(bool $available, mixed $cached, bool $failTable): MetricsEngine {
		$manifestLoader = $this->createMock(ManifestLoader::class);
		$manifestLoader->method('appVersion')->willReturn('1.2.3');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default): string => $key.'='.$default);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->warnings[] = (string)$message;
		});

		return new MetricsEngine(
			$this->source(ObjectMetricSource::class, 'object', false),
			$this->source(TableMetricSource::class, 'table', $failTable),
			$this->source(AppConfigMetricSource::class, 'appConfig', false),
			$this->source(ProviderMetricSource::class, 'provider', false),
			new PrometheusRenderer(),
			$manifestLoader,
			$this->cacheFactory($available, $cached),
			$config,
			$logger
		);
	}//end engine()

	/**
	 * A source double that records its calls and returns one sample per metric.
	 *
	 * @param class-string $class The source class.
	 * @param string       $label The label recorded per call.
	 * @param bool         $fail  Whether it throws instead.
	 *
	 * @return mixed The double.
	 */
	private function source(string $class, string $label, bool $fail): mixed {
		$source = $this->createMock($class);
		$source->method('collect')->willReturnCallback(function (string $appId, MetricDescriptor $descriptor) use ($label, $fail): array {
			$this->sourceCalls[] = $label.':'.$appId.':'.$descriptor->name;
			if ($fail === true) {
				throw new RuntimeException('boom from '.$label);
			}

			return [new MetricSample($descriptor->name, $descriptor->type, 'Help for '.$descriptor->name, [['labels' => ['src' => $label], 'value' => strlen($descriptor->name)]])];
		});

		return $source;
	}//end source()

	/**
	 * A cache factory whose distributed cache returns a fixed value and records writes.
	 *
	 * @param bool  $available Whether the cache is available.
	 * @param mixed $cached    The value returned for any key.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function cacheFactory(bool $available, mixed $cached): ICacheFactory {
		$test = $this;
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cached);
		$cache->method('set')->willReturnCallback(static function (string $key, mixed $value, int $ttl = 0) use ($test): bool {
			$test->recordWrite($key, $value, $ttl);
			return true;
		});

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->method('createDistributed')->willReturnCallback(function (string $prefix = '') use ($cache): ICache {
			$this->cachePrefixes[] = $prefix;
			return $cache;
		});

		return $factory;
	}//end cacheFactory()

	/**
	 * Record one cache write.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The payload.
	 * @param int    $ttl   The TTL.
	 *
	 * @return void
	 */
	public function recordWrite(string $key, mixed $value, int $ttl): void {
		$this->cacheWrites[] = [$key, $value, $ttl];
	}//end recordWrite()
}//end class
