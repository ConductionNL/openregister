<?php

/**
 * The error rate and p95 response time alert thresholds over the last window.
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
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compares the request totals with the snapshot of the previous run.
 *
 * The request rows in `openregister_metrics` are cumulative, so a window is the
 * difference between two snapshots: `RequestAlertJob` runs this every five minutes,
 * and each run judges the requests answered since the one before. The error rate is
 * the share of 5xx answers; the p95 response time is read from the duration buckets,
 * interpolated linearly inside the bucket that holds the 95th percentile (above the
 * largest bound it counts as that bound). A threshold that is unset, zero or not a
 * whole number is off. A window without requests keeps the previous outcome.
 *
 * A condition that starts sends one notification to every administrator; no second
 * one follows until a window has passed below the threshold. The outcomes are kept in
 * the snapshot, which `RequestAlertMetricsProvider` publishes as gauges.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */
class RequestAlertEvaluator {

	/**
	 * The app config key of the error rate threshold, in percent of requests.
	 *
	 * @var string
	 */
	public const ERROR_RATE_KEY = 'error_rate_alert_percent';

	/**
	 * The app config key of the p95 response time threshold, in milliseconds.
	 *
	 * @var string
	 */
	public const RESPONSE_TIME_KEY = 'response_time_p95_alert_ms';

	/**
	 * The app config key of the last snapshot and outcomes (JSON).
	 *
	 * @var string
	 */
	public const SNAPSHOT_KEY = 'request_alert_snapshot';

	/**
	 * The notification object type of a request alert.
	 *
	 * @var string
	 */
	public const NOTIFICATION_OBJECT = 'observability_alert';

	/**
	 * The notification subject of a crossed error rate.
	 *
	 * @var string
	 */
	public const SUBJECT_ERROR_RATE = 'observability_error_rate';

	/**
	 * The notification subject of a crossed p95 response time.
	 *
	 * @var string
	 */
	public const SUBJECT_RESPONSE_TIME = 'observability_response_time';

	/**
	 * The percentile the response time threshold applies to.
	 *
	 * @var float
	 */
	private const PERCENTILE = 0.95;

	/**
	 * Constructor.
	 *
	 * @param RequestMetricsStore  $store         The cumulative request rows.
	 * @param IAppConfig           $appConfig     Holds the thresholds and the snapshot.
	 * @param ITimeFactory         $time          The clock of the snapshot.
	 * @param INotificationManager $notifications Sends the alerts.
	 * @param IGroupManager        $groups        Finds the administrators.
	 * @param LoggerInterface      $logger        Logs a crossed threshold.
	 */
	public function __construct(
		private readonly RequestMetricsStore $store,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly INotificationManager $notifications,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Judge the window since the previous snapshot and store the new one; the outcomes.
	 *
	 * @return array{errorRateExceeded: bool, responseTimeExceeded: bool}
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	public function evaluate(): array {
		$previous = $this->snapshot();
		$current  = $this->currentTotals();
		$outcome  = ['errorRateExceeded' => $previous['errorRateExceeded'], 'responseTimeExceeded' => $previous['responseTimeExceeded']];
		$window   = self::window(previous: $previous, current: $current);

		if ($window !== null && $window['requests'] > 0) {
			$minutes = max(1, (int) round(($current['at'] - $previous['at']) / 60));
			$outcome['errorRateExceeded'] = $this->judgeErrorRate(window: $window, minutes: $minutes, wasExceeded: $previous['errorRateExceeded']);
			$outcome['responseTimeExceeded'] = $this->judgeResponseTime(window: $window, minutes: $minutes, wasExceeded: $previous['responseTimeExceeded']);
		}

		$summary = null;
		if ($window !== null) {
			$summary = [
				'minutes' => max(1, (int) round(($current['at'] - $previous['at']) / 60)),
				'requests' => $window['requests'],
				'errors' => $window['errors'],
				'averageMs' => self::averageMs(window: $window),
			];
		}

		if ($window === null) {
			$outcome = ['errorRateExceeded' => false, 'responseTimeExceeded' => false];
		}

		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			self::SNAPSHOT_KEY,
			(string) json_encode([...$current, ...$outcome, 'lastWindow' => $summary])
		);

		return $outcome;
	}//end evaluate()

	/**
	 * The stored outcomes, both false when there is no snapshot or no threshold.
	 *
	 * @return array{errorRateExceeded: bool, responseTimeExceeded: bool}
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	public function outcomes(): array {
		$snapshot = $this->snapshot();
		return [
			'errorRateExceeded' => ($snapshot['errorRateExceeded'] && $this->threshold(key: self::ERROR_RATE_KEY) !== null),
			'responseTimeExceeded' => ($snapshot['responseTimeExceeded'] && $this->threshold(key: self::RESPONSE_TIME_KEY) !== null),
		];
	}//end outcomes()

	/**
	 * The last judged window: its length, requests, 5xx answers and average duration, or null before the second run.
	 *
	 * @return array{minutes: int, requests: int, errors: int, averageMs: int|null}|null
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function lastWindow(): ?array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::SNAPSHOT_KEY, ''), true);
		$window  = null;
		if (is_array($decoded) === true) {
			$window = ($decoded['lastWindow'] ?? null);
		}

		if (is_array($window) === false) {
			return null;
		}

		$average = null;
		if (is_int($window['averageMs'] ?? null) === true) {
			$average = $window['averageMs'];
		}

		return [
			'minutes' => (int) ($window['minutes'] ?? 0),
			'requests' => (int) ($window['requests'] ?? 0),
			'errors' => (int) ($window['errors'] ?? 0),
			'averageMs' => $average,
		];
	}//end lastWindow()

	/**
	 * The average duration of a window's requests in whole milliseconds, null without timed requests.
	 *
	 * @param array{requests: int, errors: int, sumMs: int, buckets: array<string, int>} $window The window.
	 *
	 * @return int|null
	 */
	private static function averageMs(array $window): ?int {
		$timed = array_sum($window['buckets']);
		if ($timed <= 0) {
			return null;
		}

		return (int) round($window['sumMs'] / $timed);
	}//end averageMs()

	/**
	 * The p95 of a set of non-cumulative bucket counts, in milliseconds, or null without observations.
	 *
	 * @param array<int|string, int> $buckets Count per bound in ms, `inf` for above the largest.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	public static function percentileMs(array $buckets): ?float {
		$total = array_sum($buckets);
		if ($total <= 0) {
			return null;
		}

		$rank  = (self::PERCENTILE * (float) $total);
		$lower = 0.0;
		$seen  = 0.0;
		foreach (RequestMetricsRecorder::DURATION_BUCKETS_MS as $bound) {
			$count = (float) ($buckets[(string) $bound] ?? 0);
			$upper = (float) $bound;
			if ($count > 0.0 && ($seen + $count) >= $rank) {
				return ($lower + (($upper - $lower) * (($rank - $seen) / $count)));
			}

			$seen += $count;
			$lower = $upper;
		}

		return $lower;
	}//end percentileMs()

	/**
	 * Judge the error rate of a window, alerting when it starts exceeding.
	 *
	 * @param array{requests: int, errors: int, sumMs: int, buckets: array<string, int>} $window      The window.
	 * @param int                                                            $minutes     Its length.
	 * @param bool                                                           $wasExceeded The previous outcome.
	 *
	 * @return bool Whether it exceeds.
	 */
	private function judgeErrorRate(array $window, int $minutes, bool $wasExceeded): bool {
		$threshold = $this->threshold(key: self::ERROR_RATE_KEY);
		if ($threshold === null) {
			return false;
		}

		$rate     = (100 * $window['errors'] / $window['requests']);
		$exceeded = ($rate > $threshold);
		if ($exceeded === true && $wasExceeded === false) {
			$this->logger->warning(
				'[Observability] error rate '.round($rate, 1).'% over the last '.$minutes.' minutes is over the alert threshold of '.$threshold.'%'
			);
			$this->alert(
				subject: self::SUBJECT_ERROR_RATE,
				parameters: ['ratePercent' => round($rate, 1), 'thresholdPercent' => $threshold, 'windowMinutes' => $minutes]
			);
		}

		return $exceeded;
	}//end judgeErrorRate()

	/**
	 * Judge the p95 response time of a window, alerting when it starts exceeding.
	 *
	 * @param array{requests: int, errors: int, sumMs: int, buckets: array<string, int>} $window      The window.
	 * @param int                                                            $minutes     Its length.
	 * @param bool                                                           $wasExceeded The previous outcome.
	 *
	 * @return bool Whether it exceeds.
	 */
	private function judgeResponseTime(array $window, int $minutes, bool $wasExceeded): bool {
		$threshold = $this->threshold(key: self::RESPONSE_TIME_KEY);
		$p95       = self::percentileMs(buckets: $window['buckets']);
		if ($threshold === null || $p95 === null) {
			return false;
		}

		$exceeded = ($p95 > $threshold);
		if ($exceeded === true && $wasExceeded === false) {
			$this->logger->warning(
				'[Observability] p95 response time '.round($p95).' ms over the last '.$minutes.' minutes is over the alert threshold of '.$threshold.' ms'
			);
			$this->alert(
				subject: self::SUBJECT_RESPONSE_TIME,
				parameters: ['p95Ms' => (int) round($p95), 'thresholdMs' => $threshold, 'windowMinutes' => $minutes]
			);
		}

		return $exceeded;
	}//end judgeResponseTime()

	/**
	 * The totals now: requests, 5xx answers and the duration buckets summed over every route.
	 *
	 * @return array{at: int, requests: int, errors: int, sumMs: int, buckets: array<string, int>}
	 */
	private function currentTotals(): array {
		$requests = 0;
		$errors   = 0;
		$sumMs    = 0;
		foreach ($this->store->requestTotals() as $row) {
			$requests += $row['count'];
			$sumMs    += $row['sumMs'];
			if (str_starts_with($row['status'], '5') === true) {
				$errors += $row['count'];
			}
		}

		$buckets = [];
		foreach ($this->store->bucketTotals() as $row) {
			$buckets[$row['bucket']] = (($buckets[$row['bucket']] ?? 0) + $row['count']);
		}

		return ['at' => $this->time->getTime(), 'requests' => $requests, 'errors' => $errors, 'sumMs' => $sumMs, 'buckets' => $buckets];
	}//end currentTotals()

	/**
	 * The difference between two snapshots, or null when there is no usable previous one.
	 *
	 * A total that went down (rows removed by the retention cleanup) starts a new baseline.
	 *
	 * @param array<string, mixed>                                                      $previous The previous snapshot.
	 * @param array{at: int, requests: int, errors: int, sumMs: int, buckets: array<string, int>} $current  The totals now.
	 *
	 * @return array{requests: int, errors: int, sumMs: int, buckets: array<string, int>}|null
	 */
	private static function window(array $previous, array $current): ?array {
		if ($previous['at'] === null || $current['requests'] < $previous['requests'] || $current['errors'] < $previous['errors']) {
			return null;
		}

		$buckets = [];
		foreach ($current['buckets'] as $bound => $count) {
			$buckets[(string) $bound] = max(0, ($count - (int) ($previous['buckets'][(string) $bound] ?? 0)));
		}

		return [
			'requests' => ($current['requests'] - $previous['requests']),
			'errors' => ($current['errors'] - $previous['errors']),
			'sumMs' => max(0, ($current['sumMs'] - $previous['sumMs'])),
			'buckets' => $buckets,
		];
	}//end window()

	/**
	 * The stored snapshot, with `at` null when there is none.
	 *
	 * @return array{at: int|null, requests: int, errors: int, sumMs: int, buckets: array<string, int>,
	 *     errorRateExceeded: bool, responseTimeExceeded: bool}
	 */
	private function snapshot(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::SNAPSHOT_KEY, ''), true);
		if (is_array($decoded) === false || is_int($decoded['at'] ?? null) === false) {
			return [
				'at' => null,
				'requests' => 0,
				'errors' => 0,
				'sumMs' => 0,
				'buckets' => [],
				'errorRateExceeded' => false,
				'responseTimeExceeded' => false,
			];
		}

		$stored = [];
		if (is_array($decoded['buckets'] ?? null) === true) {
			$stored = $decoded['buckets'];
		}

		$buckets = [];
		foreach ($stored as $bound => $count) {
			$buckets[(string) $bound] = (int) $count;
		}

		return [
			'at' => $decoded['at'],
			'requests' => (int) ($decoded['requests'] ?? 0),
			'errors' => (int) ($decoded['errors'] ?? 0),
			'sumMs' => (int) ($decoded['sumMs'] ?? 0),
			'buckets' => $buckets,
			'errorRateExceeded' => (($decoded['errorRateExceeded'] ?? false) === true),
			'responseTimeExceeded' => (($decoded['responseTimeExceeded'] ?? false) === true),
		];
	}//end snapshot()

	/**
	 * A configured threshold, or null when it is unset, zero or not a whole number.
	 *
	 * @param string $key The app config key.
	 *
	 * @return int|null
	 */
	private function threshold(string $key): ?int {
		$value = trim($this->appConfig->getValueString(AppIdentity::APP_ID, $key, ''));
		if (ctype_digit($value) === false || (int) $value === 0) {
			return null;
		}

		return (int) $value;
	}//end threshold()

	/**
	 * Send one alert to every administrator; a delivery failure is logged, never thrown.
	 *
	 * @param string               $subject    The notification subject.
	 * @param array<string, mixed> $parameters Its parameters.
	 *
	 * @return void
	 */
	private function alert(string $subject, array $parameters): void {
		try {
			$admins = $this->groups->get('admin');
			if ($admins === null) {
				return;
			}

			foreach ($admins->getUsers() as $user) {
				$notification = $this->notifications->createNotification();
				$notification->setApp(AppIdentity::APP_ID)
					->setUser($user->getUID())
					->setDateTime(new DateTime('@'.$this->time->getTime()))
					->setObject(self::NOTIFICATION_OBJECT, $subject)
					->setSubject($subject, $parameters);
				$this->notifications->notify($notification);
			}
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] request alert could not be delivered: '.$e->getMessage());
		}
	}//end alert()
}//end class
