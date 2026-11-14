<?php

/**
 * A circuit breaker per dependency, with its state in a shared memcache.
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
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Closed, open and half-open, per key (design D-1).
 *
 * - Closed: calls pass; each outcome is counted in the current bucket. The
 *   breaker opens when the window holds at least `minCalls` calls, at least
 *   `minFailures` failures, and failures are at least `failurePercent` of them.
 * - Open: `allow()` throws {@see DependencyUnavailableException} until the
 *   cool-down ends, without touching the dependency.
 * - Half-open: after the cool-down exactly one caller wins the probe slot and
 *   goes through. Its success closes the breaker; its failure reopens it with
 *   the cool-down doubled, up to `maxCoolDown`.
 *
 * 🔴 FAIL OPEN. Without a memcache, or when the memcache throws, the breaker
 * lets every call through and logs at warning: a broken cache must never take
 * the API down (hydra ADR-102).
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class CircuitBreaker {

	/**
	 * Prefix of every cache key this breaker writes.
	 */
	private const PREFIX = 'breaker_';

	/**
	 * Constructor.
	 *
	 * @param IMemcache|null    $cache      The state store; null lets every call through.
	 * @param BreakerThresholds $thresholds When to open and how long to wait.
	 * @param ITimeFactory      $clock      The clock.
	 * @param LoggerInterface   $logger     The logger.
	 * @param bool              $shared     Whether the store is shared by every worker.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IMemcache $cache,
		private readonly BreakerThresholds $thresholds,
		private readonly ITimeFactory $clock,
		private readonly LoggerInterface $logger,
		public readonly bool $shared,
	) {
	}//end __construct()

	/**
	 * Let a call to the dependency through, or refuse it while the breaker is open.
	 *
	 * After the cool-down the one caller that wins the probe slot is let
	 * through; it must report its outcome with recordSuccess() or recordFailure().
	 *
	 * @param string $key The breaker key.
	 *
	 * @return void
	 *
	 * @throws DependencyUnavailableException While the breaker is open, or another caller holds the probe.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function allow(string $key): void {
		$openUntil = $this->read(key: $key, part: 'open');
		if ($openUntil === null) {
			return;
		}

		$now = $this->clock->getTime();
		if ($now < $openUntil) {
			throw new DependencyUnavailableException(key: $key, retryAfter: ($openUntil - $now));
		}

		if ($this->claimProbe(key: $key) === true) {
			return;
		}

		throw new DependencyUnavailableException(key: $key, retryAfter: 1);
	}//end allow()

	/**
	 * Run a call through the breaker: refused while open, counted either way.
	 *
	 * Every exception the call throws counts as a failure and is rethrown.
	 *
	 * @param string   $key  The breaker key.
	 * @param callable $call The call to the dependency.
	 *
	 * @return mixed What the call returned.
	 *
	 * @throws DependencyUnavailableException While the breaker is open.
	 * @throws Throwable What the call threw.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function call(string $key, callable $call): mixed {
		$this->allow(key: $key);

		try {
			$result = $call();
		} catch (Throwable $failure) {
			$this->recordFailure(key: $key);
			throw $failure;
		}

		$this->recordSuccess(key: $key);
		return $result;
	}//end call()

	/**
	 * Count a successful call; a successful probe closes the breaker.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function recordSuccess(string $key): void {
		if ($this->read(key: $key, part: 'open') !== null) {
			$this->reset(key: $key);
			return;
		}

		$this->count(key: $key, part: 'calls');
	}//end recordSuccess()

	/**
	 * Count a failed call; open the breaker when the window says so, or reopen it after a failed probe.
	 *
	 * @param string   $key        The breaker key.
	 * @param int|null $retryAfter A `Retry-After` the dependency sent; a longer one becomes the cool-down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function recordFailure(string $key, ?int $retryAfter = null): void {
		if ($this->read(key: $key, part: 'open') !== null) {
			$doubled = min($this->thresholds->maxCoolDown, (2 * ($this->read(key: $key, part: 'cool') ?? $this->thresholds->coolDown)));
			$this->open(key: $key, coolDown: max($doubled, ($retryAfter ?? 0)));
			return;
		}

		$this->count(key: $key, part: 'calls');
		$this->count(key: $key, part: 'failures');
		if ($this->shouldOpen(key: $key) === true) {
			$this->open(key: $key, coolDown: max($this->thresholds->coolDown, ($retryAfter ?? 0)));
		}
	}//end recordFailure()

	/**
	 * Close the breaker and forget its window, as after a successful probe or an administrator's reset.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function reset(string $key): void {
		$this->attempt(
			key: $key,
			action: function () use ($key): void {
				foreach (['open', 'cool', 'opened', 'probe'] as $part) {
					$this->cache?->remove($this->cacheKey(key: $key, part: $part));
				}

				foreach ($this->bucketStarts() as $start) {
					$this->cache?->remove($this->cacheKey(key: $key, part: 'calls_' . $start));
					$this->cache?->remove($this->cacheKey(key: $key, part: 'failures_' . $start));
				}
			}
		);
	}//end reset()

	/**
	 * The breaker's state for the operations console.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return array{key: string, dependency: string, state: string, calls: int, failures: int, openedAt: int|null, nextProbeAt: int|null, shared: bool}
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function state(string $key): array {
		$openUntil = $this->read(key: $key, part: 'open');
		$state     = 'closed';
		if ($openUntil !== null) {
			$state = 'half-open';
			if ($this->clock->getTime() < $openUntil) {
				$state = 'open';
			}
		}

		[$calls, $failures] = $this->window(key: $key);

		return [
			'key' => $key,
			'dependency' => DependencyUnavailableException::classOf(key: $key),
			'state' => $state,
			'calls' => $calls,
			'failures' => $failures,
			'openedAt' => $this->read(key: $key, part: 'opened'),
			'nextProbeAt' => $openUntil,
			'shared' => $this->shared,
		];
	}//end state()

	/**
	 * Whether the window holds enough failures to open.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return bool True to open.
	 */
	private function shouldOpen(string $key): bool {
		[$calls, $failures] = $this->window(key: $key);

		return $calls >= $this->thresholds->minCalls
			&& $failures >= $this->thresholds->minFailures
			&& ($failures * 100) >= ($this->thresholds->failurePercent * $calls);
	}//end shouldOpen()

	/**
	 * The calls and failures counted over the window.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return array{0: int, 1: int} Calls and failures.
	 */
	private function window(string $key): array {
		$calls    = 0;
		$failures = 0;
		foreach ($this->bucketStarts() as $start) {
			$calls    += ($this->read(key: $key, part: 'calls_' . $start) ?? 0);
			$failures += ($this->read(key: $key, part: 'failures_' . $start) ?? 0);
		}

		return [$calls, $failures];
	}//end window()

	/**
	 * The start of every bucket in the current window, newest first.
	 *
	 * @return array<int, int> The bucket starts.
	 */
	private function bucketStarts(): array {
		$bucket  = max(1, $this->thresholds->bucket);
		$current = ($this->clock->getTime() - ($this->clock->getTime() % $bucket));
		$starts  = [];
		for ($start = $current; $start > ($current - $this->thresholds->window); $start -= $bucket) {
			$starts[] = $start;
		}

		return $starts;
	}//end bucketStarts()

	/**
	 * Add one to a counter in the current bucket.
	 *
	 * @param string $key  The breaker key.
	 * @param string $part `calls` or `failures`.
	 *
	 * @return void
	 */
	private function count(string $key, string $part): void {
		$name = $this->cacheKey(key: $key, part: $part . '_' . $this->bucketStarts()[0]);
		$this->attempt(
			key: $key,
			action: function () use ($name): void {
				if ($this->cache?->inc($name) === 1) {
					// Expire with the window, or a bucket would count forever.
					$this->cache->set($name, 1, ($this->thresholds->window + $this->thresholds->bucket));
				}
			}
		);
	}//end count()

	/**
	 * Open the breaker for a cool-down.
	 *
	 * @param string $key      The breaker key.
	 * @param int    $coolDown The cool-down in seconds.
	 *
	 * @return void
	 */
	private function open(string $key, int $coolDown): void {
		$now  = $this->clock->getTime();
		$keep = ($coolDown + $this->thresholds->maxCoolDown + $this->thresholds->window);
		$this->attempt(
			key: $key,
			action: function () use ($key, $now, $coolDown, $keep): void {
				$this->cache?->set($this->cacheKey(key: $key, part: 'open'), ($now + $coolDown), $keep);
				$this->cache?->set($this->cacheKey(key: $key, part: 'cool'), $coolDown, $keep);
				$this->cache?->set($this->cacheKey(key: $key, part: 'opened'), $now, $keep);
				$this->cache?->remove($this->cacheKey(key: $key, part: 'probe'));
			}
		);
		$this->logger->warning(sprintf('[CircuitBreaker] %s opened for %d seconds', $key, $coolDown));
	}//end open()

	/**
	 * Claim the single probe slot of a half-open breaker.
	 *
	 * @param string $key The breaker key.
	 *
	 * @return bool True for the one caller that may probe; true as well when the store cannot decide.
	 */
	private function claimProbe(string $key): bool {
		try {
			return (bool) $this->cache?->add($this->cacheKey(key: $key, part: 'probe'), 1, max(1, $this->thresholds->coolDown));
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[CircuitBreaker] %s: probe claim failed, letting the call through: %s', $key, $e->getMessage()));
			return true;
		}
	}//end claimProbe()

	/**
	 * Read an integer from the store; null when absent, unreadable or without a store.
	 *
	 * @param string $key  The breaker key.
	 * @param string $part The value's name.
	 *
	 * @return int|null The value.
	 */
	private function read(string $key, string $part): ?int {
		try {
			$value = $this->cache?->get($this->cacheKey(key: $key, part: $part));
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[CircuitBreaker] %s: state unreadable, letting the call through: %s', $key, $e->getMessage()));
			return null;
		}

		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int) $value;
		}

		return null;
	}//end read()

	/**
	 * Run a write against the store; a failure is logged and ignored.
	 *
	 * @param string   $key    The breaker key.
	 * @param callable $action The write.
	 *
	 * @return void
	 */
	private function attempt(string $key, callable $action): void {
		try {
			$action();
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[CircuitBreaker] %s: state not written: %s', $key, $e->getMessage()));
		}
	}//end attempt()

	/**
	 * The cache key of one of a breaker's values.
	 *
	 * @param string $key  The breaker key.
	 * @param string $part The value's name.
	 *
	 * @return string The cache key.
	 */
	private function cacheKey(string $key, string $part): string {
		return self::PREFIX . hash('sha256', $key) . '_' . $part;
	}//end cacheKey()
}//end class
