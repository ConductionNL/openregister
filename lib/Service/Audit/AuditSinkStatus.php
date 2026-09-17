<?php

/**
 * What the audit sink has been doing, as the operations console reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTime;
use OCP\IAppConfig;
use Throwable;

/**
 * The sink's health, kept where an operator can see it.
 *
 * The failure mode this exists for is not a crash. It is the destination
 * quietly ceasing to accept, with everybody believing the trail is being
 * watched, for six months. So the status is persisted rather than logged: a
 * log line about a broken sink is read by the same nobody who was reading the
 * sink.
 *
 * `unshipped` is a RUNNING COUNT, never reset by a later success on its own.
 * It is the size of the gap, and a gap that repairs itself when the next write
 * happens to succeed is a gap nobody ever learns the size of. It clears only
 * when an operator acknowledges it.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class AuditSinkStatus {
	/**
	 * The app the configuration belongs to.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * The configuration key the status is kept under.
	 *
	 * @var string
	 */
	private const KEY = 'audit_sink_status';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the status is kept.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Read the status back.
	 *
	 * @return array<string, mixed> The status, with every key always present.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function read(): array {
		$stored = [];

		try {
			$raw = $this->appConfig->getValueString(self::APP, self::KEY, '');
			if ($raw !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded) === true) {
					$stored = $decoded;
				}
			}
		} catch (Throwable $e) {
			$stored = [];
		}

		return [
			'healthy' => (bool)($stored['healthy'] ?? true),
			'lastSuccessAt' => ($stored['lastSuccessAt'] ?? null),
			'lastFailureAt' => ($stored['lastFailureAt'] ?? null),
			'lastError' => ($stored['lastError'] ?? null),
			'unshipped' => (int)($stored['unshipped'] ?? 0),
			'acknowledgedAt' => ($stored['acknowledgedAt'] ?? null),
		];
	}//end read()

	/**
	 * Record that an entry reached the sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function recordSuccess(): void {
		$status = $this->read();
		$status['lastSuccessAt'] = (new DateTime())->format('c');

		// Healthy again only once the gap has been acknowledged. A sink that
		// reports itself healthy while entries are still missing is the exact
		// reassurance this change exists to withdraw.
		if ($status['unshipped'] === 0) {
			$status['healthy'] = true;
			$status['lastError'] = null;
		}

		$this->write(status: $status);
	}//end recordSuccess()

	/**
	 * Record that an entry did not reach the sink.
	 *
	 * @param string $error What went wrong, for the operator.
	 *
	 * @return bool True when this is the FIRST failure of a healthy sink, which
	 *              is when the failure is worth its own audit entry.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function recordFailure(string $error): bool {
		$status = $this->read();
		$first = ($status['healthy'] === true);

		$status['healthy'] = false;
		$status['lastFailureAt'] = (new DateTime())->format('c');
		$status['lastError'] = $error;
		$status['unshipped'] = ($status['unshipped'] + 1);

		$this->write(status: $status);

		return $first;
	}//end recordFailure()

	/**
	 * An operator says they have dealt with the gap.
	 *
	 * @return array<string, mixed> The cleared status.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function acknowledge(): array {
		$status = $this->read();
		$status['unshipped'] = 0;
		$status['healthy'] = true;
		$status['acknowledgedAt'] = (new DateTime())->format('c');

		$this->write(status: $status);

		return $status;
	}//end acknowledge()

	/**
	 * Persist the status.
	 *
	 * @param array<string, mixed> $status The status to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function write(array $status): void {
		try {
			$this->appConfig->setValueString(
				self::APP,
				self::KEY,
				(string)json_encode($status)
			);
		} catch (Throwable $e) {
			// The sink's bookkeeping must never take an audited write down
			// with it. The entry is already in the database, which is the
			// trail; this is the report about the copy.
			return;
		}
	}//end write()
}//end class
