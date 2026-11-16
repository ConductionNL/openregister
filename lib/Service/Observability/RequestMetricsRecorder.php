<?php

/**
 * Records each API request in APCu and flushes the counters to the database every 60 seconds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The per-request recording path of the request and error metrics.
 *
 * A request costs a few APCu atomic increments, never a database write.
 * Every 60 seconds one request of the PHP process pool wins an APCu marker
 * and flushes: it takes each counter's value out with an atomic subtract
 * (increments that race with the flush stay for the next one) and adds it to
 * the cumulative rows of RequestMetricsStore. A row that cannot be written is
 * put back into APCu, so a failing database delays counts instead of losing
 * them. The flush runs inside a web request on purpose: APCu memory is per
 * process pool, so a cron job run from the CLI would see none of it.
 *
 * Without APCu nothing is recorded.
 *
 * @spec openspec/changes/production-observability
 */
class RequestMetricsRecorder {

	/**
	 * The prefix of every counter key.
	 */
	public const PREFIX = 'openregister_reqm:';

	/**
	 * The marker that elects the flushing request.
	 */
	public const FLUSH_MARKER = 'openregister_reqm_flush';

	/**
	 * Seconds between flushes.
	 */
	public const FLUSH_INTERVAL = 60;

	/**
	 * The upper bounds of the request duration buckets, in milliseconds.
	 */
	public const DURATION_BUCKETS_MS = [10, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

	/**
	 * The overflow bucket.
	 */
	public const BUCKET_INF = 'inf';

	/**
	 * Constructor.
	 *
	 * @param ApcuCounters        $apcu   The APCu counters.
	 * @param RequestMetricsStore $store  The durable rows.
	 * @param LoggerInterface     $logger The logger.
	 */
	public function __construct(
		private readonly ApcuCounters $apcu,
		private readonly RequestMetricsStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Count one answered request and, when its duration is known, put it in its bucket.
	 *
	 * @param string   $method     The HTTP method.
	 * @param string   $route      The route key (for example `objects.index`).
	 * @param int      $status     The HTTP status.
	 * @param int|null $durationMs The request duration, null when unknown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function record(string $method, string $route, int $status, ?int $durationMs): void {
		if ($this->apcu->available() === false) {
			return;
		}

		$method = self::clean(value: strtoupper($method));
		$route  = self::clean(value: $route);

		$this->apcu->increment(key: self::PREFIX.'r|'.$method.'|'.$route.'|'.$status, amount: 1);
		if ($durationMs === null) {
			return;
		}

		$durationMs = max(0, $durationMs);
		$this->apcu->increment(key: self::PREFIX.'t|'.$method.'|'.$route.'|'.$status, amount: $durationMs);
		$this->apcu->increment(key: self::PREFIX.'b|'.$method.'|'.$route.'|'.self::bucketOf(durationMs: $durationMs), amount: 1);
	}//end record()

	/**
	 * Count one error of a type.
	 *
	 * @param string $type The error type (`database`, `validation`, `other`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function recordError(string $type): void {
		$this->apcu->increment(key: self::PREFIX.'e|'.self::clean(value: $type), amount: 1);
	}//end recordError()

	/**
	 * Flush when this request wins the 60-second marker; the number of rows written, 0 otherwise.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function flushIfDue(): int {
		if ($this->apcu->claim(key: self::FLUSH_MARKER, ttl: self::FLUSH_INTERVAL) === false) {
			return 0;
		}

		return $this->flush();
	}//end flushIfDue()

	/**
	 * Move every counter into the durable rows; the number of rows written.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function flush(): int {
		$taken = $this->apcu->entries(prefix: self::PREFIX);
		foreach ($taken as $key => $value) {
			$this->apcu->subtract(key: $key, amount: $value);
		}

		$written = 0;
		foreach (self::rowsOf(taken: $taken) as $row) {
			try {
				$this->store->add(
					metricType: $row['metricType'],
					entityType: $row['entityType'],
					entityId: $row['entityId'],
					status: $row['status'],
					count: $row['count'],
					sumMs: $row['sumMs']
				);
				$written++;
			} catch (Throwable $e) {
				foreach ($row['keys'] as $key => $value) {
					$this->apcu->increment(key: $key, amount: $value);
				}

				$this->logger->warning('[Observability] request metrics flush failed, kept for the next flush: '.$e->getMessage());
			}
		}

		return $written;
	}//end flush()

	/**
	 * The bucket of a duration: the smallest bound it does not exceed, `inf` above the largest.
	 *
	 * @param int $durationMs The duration.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability
	 */
	public static function bucketOf(int $durationMs): string {
		foreach (self::DURATION_BUCKETS_MS as $bound) {
			if ($durationMs <= $bound) {
				return (string) $bound;
			}
		}

		return self::BUCKET_INF;
	}//end bucketOf()

	/**
	 * The store rows of the taken counters, each with the keys it was built from.
	 *
	 * A request row joins the count key (`r`) and the duration sum key (`t`)
	 * of one method, route and status. Keys that do not parse are dropped.
	 *
	 * @param array<string, int> $taken The counters taken out of APCu.
	 *
	 * @return array<string, array<string, mixed>> Rows of the row() shape.
	 */
	private static function rowsOf(array $taken): array {
		$rows = [];
		foreach ($taken as $key => $value) {
			$parts = explode('|', substr($key, strlen(self::PREFIX)));
			$kind  = array_shift($parts);

			if (($kind === 'r' || $kind === 't') && count($parts) === 3) {
				$id = 'r|'.implode('|', $parts);
				$rows[$id] ??= self::row(metricType: RequestMetricsStore::METRIC_REQUEST, entityType: $parts[0], entityId: $parts[1], status: $parts[2]);
				if ($kind === 'r') {
					$rows[$id]['count'] += $value;
				} else {
					$rows[$id]['sumMs'] += $value;
				}

				$rows[$id]['keys'][$key] = $value;
				continue;
			}

			if ($kind === 'b' && count($parts) === 3) {
				$rows['b|'.implode('|', $parts)] = [
					...self::row(metricType: RequestMetricsStore::METRIC_BUCKET, entityType: $parts[0], entityId: $parts[1], status: $parts[2]),
					'count' => $value,
					'keys' => [$key => $value],
				];
				continue;
			}

			if ($kind === 'e' && count($parts) === 1) {
				$rows['e|'.$parts[0]] = [
					...self::row(metricType: RequestMetricsStore::METRIC_ERROR, entityType: $parts[0], entityId: '', status: ''),
					'count' => $value,
					'keys' => [$key => $value],
				];
			}
		}//end foreach

		return $rows;
	}//end rowsOf()

	/**
	 * An empty store row.
	 *
	 * @param string $metricType The metric type.
	 * @param string $entityType The method or error type.
	 * @param string $entityId   The route.
	 * @param string $status     The status or bucket.
	 *
	 * @return array{metricType: string, entityType: string, entityId: string, status: string, count: int, sumMs: int, keys: array<string, int>}
	 */
	private static function row(string $metricType, string $entityType, string $entityId, string $status): array {
		return [
			'metricType' => $metricType,
			'entityType' => $entityType,
			'entityId' => $entityId,
			'status' => $status,
			'count' => 0,
			'sumMs' => 0,
			'keys' => [],
		];
	}//end row()

	/**
	 * A key part without the separator or whitespace, at most 64 characters.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function clean(string $value): string {
		$value = (string) preg_replace('/[|\s]+/', '_', $value);
		if ($value === '') {
			return 'unknown';
		}

		return substr($value, 0, 64);
	}//end clean()
}//end class
