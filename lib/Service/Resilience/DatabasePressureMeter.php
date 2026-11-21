<?php

/**
 * Counts Open Register API requests per bucket and says when the database is under pressure.
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

use Doctrine\DBAL\Exception\DriverException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * The database-pressure meter (design D-4, D-8).
 *
 * Per 10 second bucket it counts the API requests, those slower than the
 * slow threshold and those that ended in a database error. Pressure is high
 * when, over the last 60 seconds, there were at least the minimum number of
 * requests and either the slow share or the error share reached its
 * threshold. The counts live in the distributed memcache when there is one,
 * else in a local one (per worker). A cache that throws never reads as
 * pressure: the meter fails open, so it cannot take the API down.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class DatabasePressureMeter {

	/**
	 * The app id, for the appconfig thresholds.
	 */
	private const APP_ID = 'openregister';

	/**
	 * The appconfig key prefix the thresholds live under.
	 */
	private const CONFIG_PREFIX = 'load_shedding_pressure_';

	/**
	 * The window the counts cover, in seconds.
	 */
	public const WINDOW = 60;

	/**
	 * The size of one counting bucket, in seconds.
	 */
	public const BUCKET = 10;

	/**
	 * The defaults of the administered thresholds.
	 */
	public const DEFAULTS = [
		'min_requests' => 50,
		'slow_ms' => 2000,
		'slow_percent' => 20,
		'error_percent' => 10,
	];

	/**
	 * The counter store and whether it is shared, resolved once.
	 *
	 * @var array{0: IMemcache|null, 1: bool}|null
	 */
	private ?array $store = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory The cache factory.
	 * @param IAppConfig      $appConfig    The thresholds and the switch.
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
	 * Count one finished API request.
	 *
	 * @param float $seconds       How long it took.
	 * @param bool  $databaseError Whether it ended in a database error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function record(float $seconds, bool $databaseError): void {
		$this->count(part: 'requests');
		if (($seconds * 1000) > $this->threshold(key: 'slow_ms', max: PHP_INT_MAX)) {
			$this->count(part: 'slow');
		}

		if ($databaseError === true) {
			$this->count(part: 'errors');
		}
	}//end record()

	/**
	 * Count one finished API request by what it ended in.
	 *
	 * A Doctrine driver error anywhere in the exception's chain, also when
	 * Nextcloud wrapped it, counts as a database error.
	 *
	 * @param float          $seconds How long it took.
	 * @param Throwable|null $error   The exception it ended in, or null for a response.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function recordOutcome(float $seconds, ?Throwable $error): void {
		$databaseError = false;
		for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof DriverException) {
				$databaseError = true;
				break;
			}
		}

		$this->record(seconds: $seconds, databaseError: $databaseError);
	}//end recordOutcome()

	/**
	 * Whether a controller method is to be refused now: it carries #[Sheddable] and the database is under pressure.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method about to run.
	 *
	 * @return bool True to refuse it.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function sheds(object $controller, string $methodName): bool {
		try {
			$sheddable = ((new ReflectionMethod($controller, $methodName))->getAttributes(Sheddable::class) !== []);
		} catch (Throwable) {
			return false;
		}

		return $sheddable === true && $this->underPressure() === true;
	}//end sheds()

	/**
	 * Whether pressure shedding is switched on (it is unless an administrator turned it off).
	 *
	 * @return bool True when on.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function enabled(): bool {
		try {
			return $this->appConfig->getValueBool(self::APP_ID, self::CONFIG_PREFIX . 'enabled', true);
		} catch (Throwable $e) {
			$this->logger->warning('[DatabasePressureMeter] switch unreadable, shedding stays on: ' . $e->getMessage());
			return true;
		}
	}//end enabled()

	/**
	 * Whether the database is under pressure and shedding is on.
	 *
	 * @return bool True to refuse sheddable routes.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function underPressure(): bool {
		if ($this->enabled() === false) {
			return false;
		}

		return $this->pressure(counts: $this->window());
	}//end underPressure()

	/**
	 * The meter's state for the operations console.
	 *
	 * @return array{requests: int, slow: int, errors: int, underPressure: bool, enabled: bool, shared: bool}
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function state(): array {
		$counts  = $this->window();
		$enabled = $this->enabled();

		return [
			'requests' => $counts['requests'],
			'slow' => $counts['slow'],
			'errors' => $counts['errors'],
			'underPressure' => ($enabled === true && $this->pressure(counts: $counts) === true),
			'enabled' => $enabled,
			'shared' => $this->resolve()[1],
		];
	}//end state()

	/**
	 * Whether window counts amount to pressure.
	 *
	 * @param array{requests: int, slow: int, errors: int} $counts The window's counts.
	 *
	 * @return bool True under pressure.
	 */
	private function pressure(array $counts): bool {
		if ($counts['requests'] < $this->threshold(key: 'min_requests', max: PHP_INT_MAX)) {
			return false;
		}

		$slowShare  = ($counts['slow'] * 100) >= ($this->threshold(key: 'slow_percent', max: 100) * $counts['requests']);
		$errorShare = ($counts['errors'] * 100) >= ($this->threshold(key: 'error_percent', max: 100) * $counts['requests']);

		return $slowShare === true || $errorShare === true;
	}//end pressure()

	/**
	 * A threshold from appconfig; an unreadable or out-of-range value falls back to its default.
	 *
	 * @param string $key The threshold's name, a key of DEFAULTS.
	 * @param int    $max The highest allowed value.
	 *
	 * @return int The value.
	 */
	private function threshold(string $key, int $max): int {
		$default = self::DEFAULTS[$key];
		try {
			$value = $this->appConfig->getValueInt(self::APP_ID, self::CONFIG_PREFIX . $key, $default);
		} catch (Throwable $e) {
			$this->logger->warning(sprintf('[DatabasePressureMeter] %s unreadable, using default %d: %s', $key, $default, $e->getMessage()));
			return $default;
		}

		if ($value < 1 || $value > $max) {
			$this->logger->warning(sprintf('[DatabasePressureMeter] %s=%d out of range, using default %d', $key, $value, $default));
			return $default;
		}

		return $value;
	}//end threshold()

	/**
	 * The counts over the window.
	 *
	 * @return array{requests: int, slow: int, errors: int} The counts.
	 */
	private function window(): array {
		$counts = ['requests' => 0, 'slow' => 0, 'errors' => 0];
		$cache  = $this->resolve()[0];
		if ($cache === null) {
			return $counts;
		}

		try {
			foreach ($this->bucketStarts() as $start) {
				foreach (array_keys($counts) as $part) {
					$value = $cache->get('pressure_' . $part . '_' . $start);
					if (is_numeric($value) === true) {
						$counts[$part] += (int) $value;
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('[DatabasePressureMeter] counts unreadable, no pressure assumed: ' . $e->getMessage());
			return ['requests' => 0, 'slow' => 0, 'errors' => 0];
		}

		return $counts;
	}//end window()

	/**
	 * Add one to a counter in the current bucket.
	 *
	 * @param string $part `requests`, `slow` or `errors`.
	 *
	 * @return void
	 */
	private function count(string $part): void {
		$cache = $this->resolve()[0];
		if ($cache === null) {
			return;
		}

		$name = 'pressure_' . $part . '_' . $this->bucketStarts()[0];
		try {
			if ($cache->inc($name) === 1) {
				// Expire with the window, or a bucket would count forever.
				$cache->set($name, 1, (self::WINDOW + self::BUCKET));
			}
		} catch (Throwable $e) {
			$this->logger->warning('[DatabasePressureMeter] count not written: ' . $e->getMessage());
		}
	}//end count()

	/**
	 * The start of every bucket in the current window, newest first.
	 *
	 * @return array<int, int> The bucket starts.
	 */
	private function bucketStarts(): array {
		$now     = $this->clock->getTime();
		$current = ($now - ($now % self::BUCKET));
		$starts  = [];
		for ($start = $current; $start > ($current - self::WINDOW); $start -= self::BUCKET) {
			$starts[] = $start;
		}

		return $starts;
	}//end bucketStarts()

	/**
	 * The counter store: the distributed memcache, else a local one, else none.
	 *
	 * @return array{0: IMemcache|null, 1: bool} The store and whether it is shared.
	 */
	private function resolve(): array {
		if ($this->store !== null) {
			return $this->store;
		}

		$this->store = [null, false];
		try {
			if ($this->cacheFactory->isAvailable() === true) {
				$distributed = $this->cacheFactory->createDistributed('openregister_pressure');
				if ($distributed instanceof IMemcache) {
					$this->store = [$distributed, true];
					return $this->store;
				}
			}

			$local = $this->cacheFactory->createLocal('openregister_pressure');
			if ($local instanceof IMemcache) {
				$this->store = [$local, false];
			}
		} catch (Throwable $e) {
			$this->logger->warning('[DatabasePressureMeter] no cache, pressure is never seen: ' . $e->getMessage());
		}

		return $this->store;
	}//end resolve()
}//end class
