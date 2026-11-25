<?php

/**
 * The storage growth threshold.
 *
 * `storage_growth_bytes` is the size of the vectors added in the last 24
 * hours, and `storage_growth_exceeded` is 1 when it is over the administrator's
 * threshold (`storage_growth_alert_bytes` app config), with a warning logged.
 * It runs a query and reads the clock, so it takes the `provider` escape hatch.
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

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily vector storage growth against the configured alert threshold.
 *
 * Without a threshold (unset, zero or not a number) the growth is still
 * published and `storage_growth_exceeded` is 0. A growth that cannot be read
 * is published as no sample for the growth and 0 for the threshold, with a
 * warning, so a broken query never raises an alert on its own.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */
class StorageGrowthMetricsProvider implements IMetricsProvider {

	/**
	 * The app config key holding the daily growth threshold in bytes.
	 *
	 * @var string
	 */
	public const THRESHOLD_KEY = 'storage_growth_alert_bytes';

	/**
	 * The window the growth is measured over, in seconds.
	 *
	 * @var integer
	 */
	public const WINDOW_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db        Reads the vectors added in the window.
	 * @param IAppConfig      $appConfig Holds the threshold.
	 * @param ITimeFactory    $time      The clock the window ends at.
	 * @param LoggerInterface $logger    Logs a crossed threshold or a failed read.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The growth gauge and the threshold flag.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
	 */
	public function metrics(): array {
		$growth = $this->growthBytes();
		$threshold = $this->threshold();
		$exceeded = ($growth !== null && $threshold !== null && $growth > $threshold);
		if ($exceeded === true) {
			$this->logger->warning(
				'[Observability] vector storage grew '.$growth.' bytes in 24 hours, over the alert threshold of '.$threshold.' bytes'
			);
		}

		$growthSamples = [];
		if ($growth !== null) {
			$growthSamples[] = ['labels' => [], 'value' => $growth];
		}

		return [
			new MetricSample(
				name: 'storage_growth_bytes',
				type: 'gauge',
				help: 'Bytes of vectors added in the last 24 hours',
				samples: $growthSamples
			),
			new MetricSample(
				name: 'storage_growth_exceeded',
				type: 'gauge',
				help: 'Whether the last 24 hours of vector storage growth is over the configured threshold',
				samples: [['labels' => [], 'value' => (int) $exceeded]]
			),
		];
	}//end metrics()

	/**
	 * The bytes of the vectors added in the window, or null when they cannot be read.
	 *
	 * @return int|null
	 */
	private function growthBytes(): ?int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('SUM(LENGTH(embedding))'))
				->from('openregister_vectors')
				->where(
					$qb->expr()->gte(
						'created_at',
						$qb->createNamedParameter($this->time->getTime() - self::WINDOW_SECONDS, IQueryBuilder::PARAM_INT)
					)
				);
			$result = $qb->executeQuery();
			$bytes = $result->fetchOne();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] vector storage growth could not be read: '.$e->getMessage());
			return null;
		}

		return (int) $bytes;
	}//end growthBytes()

	/**
	 * The configured threshold in bytes, or null when none is set.
	 *
	 * @return int|null
	 */
	private function threshold(): ?int {
		$value = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::THRESHOLD_KEY, ''));
		if (ctype_digit($value) === false || (int) $value === 0) {
			return null;
		}

		return (int) $value;
	}//end threshold()
}//end class
