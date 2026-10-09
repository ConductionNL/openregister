<?php

/**
 * ReadHistoryService: every read of an object is registered here, and "what
 * did I open lately" is read back from the same record.
 *
 * One front door for read registration, two records behind it:
 *
 *  - the audit trail's `read` row, written for every audited read while the
 *    instance setting `retention.auditTrailsEnabled` is on;
 *  - the AVG processing log (verwerkingenlogging), written for every read of
 *    an object whose schema opts in with `x-openregister-processing.logReads`.
 *
 * The two keep their own storage on purpose. The processing log has legal
 * properties the audit trail does not share: it cannot be switched off with
 * the instance audit setting, a `_audit: false` internal load does not skip
 * it, it is pruned on its own retention, and only the FG/admin surface reads
 * it. Folding it into the audit trail would weaken at least the first of
 * those, so both are written through this class and neither changes shape.
 * See `openspec/changes/read-history-on-audit-trail/design.md`.
 *
 * The read side ("recently opened", the `_recent` lens) reads ONLY the audit
 * trail. The processing log is kept for accountability and is never read to
 * drive a convenience feature (doelbinding, AVG art. 5(1)(b)).
 *
 * Nothing is throttled on the write side. The old view table refreshed one
 * row at most once a minute; the audit trail is a legal record, so every
 * audited read stays its own row and the history collapses repeated reads at
 * query time instead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Register object reads and read one user's read history back.
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */
class ReadHistoryService {

	/**
	 * How many distinct objects one user's read history answers.
	 *
	 * The cap the old view table kept. It bounds the `_recent` lens, not the
	 * audit trail, which keeps every row for its own retention.
	 *
	 * @var integer
	 */
	public const HISTORY_LIMIT = 100;

	/**
	 * The audit action a read is recorded under.
	 *
	 * @var string
	 */
	public const READ_ACTION = 'read';

	/**
	 * Why the `_recent` lens is empty: the audit trail is switched off.
	 *
	 * @var string
	 */
	public const REASON_AUDIT_TRAIL_DISABLED = 'audit-trail-disabled';

	/**
	 * Why the `_recent` lens is empty: there is no user to answer for.
	 *
	 * @var string
	 */
	public const REASON_ANONYMOUS = 'anonymous';

	/**
	 * Why the `_recent` lens is empty: the read history could not be read.
	 *
	 * @var string
	 */
	public const REASON_UNAVAILABLE = 'read-history-unavailable';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper   $auditTrails The audit trail, which holds the read rows.
	 * @param SettingsService    $settings    Reads `retention.auditTrailsEnabled`.
	 * @param ContainerInterface $container   Resolves the processing log lazily.
	 * @param LoggerInterface    $logger      Logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrails,
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the audit trail records reads on this instance.
	 *
	 * Falls back to on when the setting cannot be read, the same posture
	 * `GetObject` always had: an unreadable setting must not silently stop
	 * the audit trail.
	 *
	 * @return boolean True when audit trails are enabled.
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/audit-trail-immutable/spec.md#requirement-every-user-facing-read-of-an-object-is-logged-as-a-read
	 */
	public function isAuditTrailEnabled(): bool {
		try {
			$retention = $this->settings->getRetentionSettingsOnly();
			return ($retention['auditTrailsEnabled'] ?? true) !== false;
		} catch (\Exception $e) {
			return true;
		}

	}//end isAuditTrailEnabled()

	/**
	 * Register an audited read of an object on the audit trail.
	 *
	 * Writes the `read` row when the caller did not opt out (`$audit`) and the
	 * instance records audit trails. A write failure is NOT swallowed: the
	 * audit trail is a legal record and its failure must stay visible, exactly
	 * as it was when `GetObject` wrote the row itself.
	 *
	 * @param ObjectEntity $object The object that was read.
	 * @param bool         $audit  False for an internal load that must not be audited.
	 *
	 * @return AuditTrail|null The row written, or null when none was due.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the `$_audit` flag of GetObject::find().
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/audit-trail-immutable/spec.md#requirement-every-user-facing-read-of-an-object-is-logged-as-a-read
	 */
	public function registerAuditRead(ObjectEntity $object, bool $audit = true): ?AuditTrail {
		if ($audit === false || $this->isAuditTrailEnabled() === false) {
			return null;
		}

		return $this->auditTrails->createAuditTrail(old: null, new: $object, action: self::READ_ACTION);

	}//end registerAuditRead()

	/**
	 * Register a read of an object on the AVG processing log.
	 *
	 * Delegates to ProcessingLogService unchanged: the schema opt-in, the
	 * attribution, the fallback activity, the subject identifier and the
	 * storage are all its own. Neither the instance audit setting nor a
	 * `_audit: false` flag reaches this path, because the processing log must
	 * record every processing of personal data (AVG art. 5(2), art. 30).
	 *
	 * Fail-soft, like the hook it replaces: a logging failure never breaks
	 * the read. ProcessingLogService keeps a failed flush buffered for retry.
	 *
	 * @param ObjectEntity $object The object that was read.
	 * @param string       $action `read` or `export`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
	 */
	public function registerProcessingRead(ObjectEntity $object, string $action = self::READ_ACTION): void {
		try {
			$service = $this->container->get(ProcessingLogService::class);
			$service->logRead(object: $object, action: $action);
			$service->flush();
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[AVG] processing-log read hook skipped',
				context: ['exception' => $e->getMessage()]
			);
		}

	}//end registerProcessingRead()

	/**
	 * Resolve the `_recent` lens for one caller.
	 *
	 * Answers whether the lens can answer at all, why not when it cannot, and
	 * the caller's history when it can. The audit trail switched off is an
	 * empty lens with a reason, never a shadow log kept elsewhere.
	 *
	 * @param string|null $userId The caller's uid, or null when anonymous.
	 *
	 * @return array{available: bool, reason: string|null, views: array<string, string>}
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
	 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 */
	public function resolveRecentLens(?string $userId): array {
		if ($userId === null || $userId === '') {
			return ['available' => false, 'reason' => self::REASON_ANONYMOUS, 'views' => []];
		}

		if ($this->isAuditTrailEnabled() === false) {
			return ['available' => false, 'reason' => self::REASON_AUDIT_TRAIL_DISABLED, 'views' => []];
		}

		// Distinct objects, each with the moment of its latest audited read,
		// newest first. A row tombstoned by retention has an empty `user`, so
		// it drops out of every history on its own.
		try {
			$views = $this->auditTrails->findLatestReadsByUser(userId: $userId, limit: self::HISTORY_LIMIT);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ReadHistoryService] read history lookup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return ['available' => false, 'reason' => self::REASON_UNAVAILABLE, 'views' => []];
		}

		return ['available' => true, 'reason' => null, 'views' => $views];

	}//end resolveRecentLens()
}//end class
