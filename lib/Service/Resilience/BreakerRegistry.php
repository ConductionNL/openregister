<?php

/**
 * Names the breaker keys and hands out breakers over the shared cache.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place breaker keys are spelled and thresholds are read (design D-1, D-8).
 *
 * State lives in `createDistributed('openregister_breakers')`, cast to
 * `IMemcache` for atomic increments, the same pattern as `CallerRateLimiter`.
 * Without a distributed memcache it falls back to `createLocal()`, so each
 * worker learns on its own and the console says so (`shared() === false`).
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class BreakerRegistry {

	/**
	 * The app id, for the appconfig thresholds.
	 */
	private const APP_ID = 'openregister';

	/**
	 * The appconfig group the thresholds live in.
	 */
	private const CONFIG_GROUP = 'load_shedding';

	/**
	 * The shared breaker, built once per request.
	 *
	 * @var CircuitBreaker|null
	 */
	private ?CircuitBreaker $breaker = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory The cache factory.
	 * @param IAppConfig      $appConfig    The thresholds source.
	 * @param ITimeFactory    $clock        The clock.
	 * @param LoggerInterface $logger       The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $clock,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The breaker key for an outbound HTTP host, or a call site's named dependency.
	 *
	 * @param string $hostOrName The host, or the `openregister_dependency` the call site named.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public static function outboundKey(string $hostOrName): string {
		return 'outbound:' . strtolower(trim($hostOrName));
	}//end outboundKey()

	/**
	 * The breaker key for a webhook receiver host.
	 *
	 * @param string $host The receiver host.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public static function webhookKey(string $host): string {
		return 'webhook:' . strtolower(trim($host));
	}//end webhookKey()

	/**
	 * The breaker key for an outside database source.
	 *
	 * @param string|int $sourceId The source id.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public static function sourceKey(string|int $sourceId): string {
		return 'source:' . (string) $sourceId;
	}//end sourceKey()

	/**
	 * The breaker key for the LLM provider.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public static function llmKey(): string {
		return 'llm';
	}//end llmKey()

	/**
	 * The shared breaker for this request.
	 *
	 * @return CircuitBreaker The breaker.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function breaker(): CircuitBreaker {
		if ($this->breaker === null) {
			[$cache, $shared] = $this->resolveCache();
			$this->breaker    = new CircuitBreaker(
				cache: $cache,
				thresholds: $this->thresholds(),
				clock: $this->clock,
				logger: $this->logger,
				shared: $shared
			);
		}

		return $this->breaker;
	}//end breaker()

	/**
	 * The thresholds, from appconfig with the design defaults as fallback.
	 *
	 * A value that cannot be read or is out of range falls back to its default
	 * and is logged, so a bad setting never takes the breaker out (hydra ADR-102).
	 *
	 * @return BreakerThresholds The thresholds.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function thresholds(): BreakerThresholds {
		$defaults = new BreakerThresholds();

		return new BreakerThresholds(
			minCalls: $this->positiveInt(key: 'breaker_min_calls', default: $defaults->minCalls),
			minFailures: $this->positiveInt(key: 'breaker_min_failures', default: $defaults->minFailures),
			failurePercent: $this->boundedInt(key: 'breaker_failure_percent', default: $defaults->failurePercent, min: 1, max: 100),
			coolDown: $this->positiveInt(key: 'breaker_cool_down', default: $defaults->coolDown),
			maxCoolDown: $this->positiveInt(key: 'breaker_max_cool_down', default: $defaults->maxCoolDown),
			window: $defaults->window,
			bucket: $defaults->bucket,
		);
	}//end thresholds()

	/**
	 * A positive integer threshold, or its default.
	 *
	 * @param string $key     The appconfig key.
	 * @param int    $default The default.
	 *
	 * @return int The value.
	 */
	private function positiveInt(string $key, int $default): int {
		return $this->boundedInt(key: $key, default: $default, min: 1, max: PHP_INT_MAX);
	}//end positiveInt()

	/**
	 * An integer threshold within bounds, or its default with a warning.
	 *
	 * @param string $key     The appconfig key.
	 * @param int    $default The default.
	 * @param int    $min     The lowest allowed value.
	 * @param int    $max     The highest allowed value.
	 *
	 * @return int The value.
	 */
	private function boundedInt(string $key, int $default, int $min, int $max): int {
		try {
			$value = $this->appConfig->getValueInt(self::APP_ID, self::CONFIG_GROUP . '_' . $key, $default);
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[BreakerRegistry] %s unreadable, using default %d: %s', $key, $default, $e->getMessage()));
			return $default;
		}

		if ($value < $min || $value > $max) {
			$this->logger->warning(sprintf('[BreakerRegistry] %s=%d out of range, using default %d', $key, $value, $default));
			return $default;
		}

		return $value;
	}//end boundedInt()

	/**
	 * The breaker state store: the distributed memcache, else a local one.
	 *
	 * @return array{0: IMemcache|null, 1: bool} The store (null lets calls through) and whether it is shared.
	 */
	private function resolveCache(): array {
		try {
			if ($this->cacheFactory->isAvailable() === true) {
				$distributed = $this->cacheFactory->createDistributed('openregister_breakers');
				if ($distributed instanceof IMemcache) {
					return [$distributed, true];
				}
			}

			$local = $this->cacheFactory->createLocal('openregister_breakers');
			if ($local instanceof IMemcache) {
				return [$local, false];
			}
		} catch (Throwable $e) {
			$this->logger->warning('[BreakerRegistry] no breaker cache, every call passes: ' . $e->getMessage());
		}

		return [null, false];
	}//end resolveCache()
}//end class
