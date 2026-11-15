<?php

/**
 * The database connection health gauge.
 *
 * `db_connection_healthy` is 1 when a `SELECT 1` round-trip succeeds within
 * five seconds, else 0. It runs a query and reads the clock, which no
 * declarative kind does, so it takes the `provider` escape hatch.
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

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes whether the database answers in time.
 *
 * @spec openspec/changes/production-observability
 */
final class DatabaseHealthMetricsProvider implements IMetricsProvider {

	/**
	 * The metric name, without the app prefix the renderer adds.
	 */
	public const METRIC_NAME = 'db_connection_healthy';

	/**
	 * The slowest round-trip still counted healthy, in seconds.
	 */
	public const MAX_SECONDS = 5.0;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The database connection.
	 * @param ITimeFactory    $time   The clock (microsecond precision).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The gauge: 1 for a round-trip that succeeded within the limit, 0 otherwise.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		return [
			new MetricSample(
				name: self::METRIC_NAME,
				type: 'gauge',
				help: 'Whether the database answered a round-trip within 5 seconds',
				samples: [['labels' => [], 'value' => (int) $this->isHealthy()]]
			),
		];
	}//end metrics()

	/**
	 * Run the round-trip and judge it: true when it succeeded within the limit.
	 *
	 * A failed or slow round-trip is logged at warning level.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function isHealthy(): bool {
		$started = $this->seconds();
		try {
			$result = $this->db->executeQuery('SELECT 1');
			$result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] database round-trip failed: '.$e->getMessage());
			return false;
		}

		$elapsed = $this->seconds() - $started;
		if ($elapsed > self::MAX_SECONDS) {
			$this->logger->warning('[Observability] database round-trip took '.(int) round($elapsed * 1000).' ms');
			return false;
		}

		return true;
	}//end isHealthy()

	/**
	 * The clock as fractional seconds.
	 *
	 * @return float
	 */
	private function seconds(): float {
		return (float) $this->time->getDateTime()->format('U.u');
	}//end seconds()
}//end class
