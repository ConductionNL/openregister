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
 * See `openspec/changes/archive/2026-10-30-read-history-on-audit-trail/design.md`.
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
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\ProcessingRead;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Register object reads and read one user's read history back.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
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
	 * @param AuditTrailMapperInterface   $auditTrails The audit trail, which holds the read rows.
	 * @param SettingsServiceInterface    $settings    Reads `retention.auditTrailsEnabled`.
	 * @param ContainerInterface $container   Resolves the processing log lazily.
	 * @param LoggerInterface    $logger      Logger.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrails,
		private readonly SettingsServiceInterface $settings,
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
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-every-user-facing-read-of-an-object-is-logged-as-a-read
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the `audit` flag of GetObject::find()'s request.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-every-user-facing-read-of-an-object-is-logged-as-a-read
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
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
	 */
	public function registerProcessingRead(ObjectEntity $object, string $action = self::READ_ACTION): void {
		try {
			$service = $this->container->get(ProcessingLogService::class);
			$service->recordRead(object: $object, read: new ProcessingRead(action: $action));
			$service->flush();
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[AVG] processing-log read hook skipped',
				context: ['exception' => $e->getMessage()]
			);
		}

	}//end registerProcessingRead()

	/**
	 * Register a list or search read on the AVG processing log.
	 *
	 * One collapsed entry per register and schema in the result, through
	 * ProcessingLogService::recordReadList, which applies the same `logReads`
	 * opt-in as a single read and carries the query parameters. A row that
	 * is a rendered array is read by its `@self` block; a row that names no
	 * uuid, register and schema cannot be attributed and is reported in the
	 * log rather than dropped silently. Fail-soft, like registerProcessingRead().
	 *
	 * @param array<int|string, mixed> $objects The listed objects: ObjectEntity or rendered arrays.
	 * @param ProcessingListRead       $read    The query parameters and the action of the read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
	 */
	public function registerListRead(array $objects, ProcessingListRead $read): void {
		$groups = $this->groupedByTable(objects: $objects);
		if ($groups === []) {
			return;
		}

		try {
			$service = $this->container->get(ProcessingLogService::class);
			foreach ($groups as $group) {
				$service->recordReadList(objects: $group, read: new ProcessingRead(action: $read->action, query: $read->query));
			}

			$service->flush();
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[AVG] processing-log list read hook skipped',
				context: ['exception' => $e->getMessage()]
			);
		}

	}//end registerListRead()

	/**
	 * The listed rows as ObjectEntities grouped per register and schema; rows
	 * that cannot be attributed are counted and reported, not dropped silently.
	 *
	 * @param array<int|string, mixed> $objects The listed objects: ObjectEntity or rendered arrays.
	 *
	 * @return array<string, list<ObjectEntity>> The groups, keyed `register|schema`.
	 */
	private function groupedByTable(array $objects): array {
		$groups = [];
		$unattributed = 0;
		foreach ($objects as $row) {
			$object = $this->listedObject(row: $row);
			if ($object === null) {
				$unattributed++;
				continue;
			}

			$groups[$object->getRegister() . '|' . $object->getSchema()][] = $object;
		}

		if ($unattributed > 0) {
			$this->logger->warning(
				message: '[AVG] list read rows without uuid, register and schema were not processing-logged',
				context: ['rows' => $unattributed, 'of' => count($objects)]
			);
		}

		return $groups;

	}//end groupedByTable()

	/**
	 * A listed row as an ObjectEntity, or null when it cannot be attributed.
	 *
	 * @param mixed $row An ObjectEntity, or a rendered object array with an `@self` block.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function listedObject(mixed $row): ?ObjectEntity {
		if ($row instanceof ObjectEntity) {
			return $row;
		}

		$self = [];
		if (is_array($row) === true && is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$uuid = ($self['uuid'] ?? $self['id'] ?? null);
		if (is_scalar($uuid) === false || is_scalar($self['register'] ?? null) === false || is_scalar($self['schema'] ?? null) === false) {
			return null;
		}

		$data = $row;
		unset($data['@self']);

		$object = new ObjectEntity();
		$object->setUuid((string)$uuid);
		$object->setRegister((string)$self['register']);
		$object->setSchema((string)$self['schema']);
		if (is_scalar($self['organisation'] ?? null) === true) {
			$object->setOrganisation((string)$self['organisation']);
		}

		$object->setObject($data);
		return $object;
	}//end listedObject()

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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
	 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
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
