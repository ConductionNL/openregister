<?php

/**
 * OpenRegister's own health checks beyond the engine's database and filesystem.
 *
 * The AppHost engine asks an app's IHealthCheckProvider for extra checks. This
 * one adds three, each of `degraded` severity, so a failure turns the health
 * status to "degraded" with HTTP 200 and never to a 503:
 *
 * - `search_backend`: the search index (the chunk table the keyword and hybrid
 *   searches read) answers a one-row read;
 * - `webhooks`: in the last 15 minutes, not every delivery attempt failed;
 * - `database_latency`: a `SELECT 1` round-trip succeeds within 5 seconds.
 *
 * Failure messages are short codes; internals go to the log only.
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

use DateInterval;
use OCA\OpenRegister\AppHost\IHealthCheckProvider;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Search index, webhook delivery and database latency checks.
 *
 * @spec openspec/changes/production-observability
 */
final class OpenRegisterHealthChecks implements IHealthCheckProvider {

	/**
	 * The window of delivery attempts the webhook check looks at, in minutes.
	 */
	public const WEBHOOK_WINDOW_MINUTES = 15;

	/**
	 * The table the database-backed search reads.
	 */
	public const SEARCH_TABLE = 'openregister_chunks';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection                 $db       The database connection.
	 * @param WebhookLogMapperInterface     $logs     The webhook delivery log.
	 * @param DatabaseHealthMetricsProvider $database The timed database round-trip.
	 * @param ITimeFactory                  $time     The clock.
	 * @param LoggerInterface               $logger   The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly WebhookLogMapperInterface $logs,
		private readonly DatabaseHealthMetricsProvider $database,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the three checks.
	 *
	 * @return array<string, array{ok: bool, severity: string, message: string}>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function check(): array {
		return [
			'search_backend' => self::result(ok: $this->searchBackendAnswers(), message: 'unreachable'),
			'webhooks' => self::result(ok: $this->webhooksDeliver(), message: 'deliveries failing'),
			'database_latency' => self::result(ok: $this->database->isHealthy(), message: 'slow or unavailable'),
		];
	}//end check()

	/**
	 * One check's result, of degraded severity; the message is kept for a failure only.
	 *
	 * @param bool   $ok      Whether the check passed.
	 * @param string $message The failure code.
	 *
	 * @return array{ok: bool, severity: string, message: string}
	 */
	private static function result(bool $ok, string $message): array {
		if ($ok === true) {
			$message = '';
		}

		return ['ok' => $ok, 'severity' => 'degraded', 'message' => $message];
	}//end result()

	/**
	 * Whether the search index answers a one-row read.
	 *
	 * @return bool
	 */
	private function searchBackendAnswers(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from(self::SEARCH_TABLE)->setMaxResults(1);
			$result = $qb->executeQuery();
			$result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] search backend probe failed: '.$e->getMessage());
			return false;
		}

		return true;
	}//end searchBackendAnswers()

	/**
	 * False when the window holds failed delivery attempts and no successful one.
	 *
	 * A quiet window (no attempts) is healthy. A log that cannot be read counts
	 * as failing.
	 *
	 * @return bool
	 */
	private function webhooksDeliver(): bool {
		$since = $this->time->getDateTime()->sub(new DateInterval('PT'.self::WEBHOOK_WINDOW_MINUTES.'M'));
		try {
			$outcomes = $this->logs->outcomesSince(since: $since);
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] webhook log unreadable: '.$e->getMessage());
			return false;
		}

		return ($outcomes['failed'] > 0 && $outcomes['successful'] === 0) === false;
	}//end webhooksDeliver()
}//end class
