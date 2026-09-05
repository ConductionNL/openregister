<?php

/**
 * OpenRegister consumable GDPR data-subject-request service.
 *
 * The RBAC + tenant SCOPED, NON-admin-gated counterpart to the privileged
 * {@see \OCA\OpenRegister\Service\DsarService}. Any leaf app can resolve
 * this via dependency injection to fulfil a data-subject's rights on behalf
 * of an authenticated handler:
 *
 *   - art-15 access / art-20 portability — findSubjectData / assembleAccessExport
 *   - art-16 rectification               — rectify
 *   - art-17 erasure                     — erase (mode-parameterised, legal-hold aware)
 *   - art-18 restriction                 — setRestriction
 *   - art-21 objection                   — setObjection
 *
 * It REUSES the existing building blocks rather than duplicating them:
 *   - the GdprEntity PII index (openregister_entities ⋈ entity_relations)
 *     for cross-register discovery (same join shape as DsarService, with
 *     LIKE-wildcard escaping);
 *   - MagicMapper object loads with `_rbac`/`_multitenancy` LEFT ON, so a
 *     caller only ever reaches objects it is authorised to read/mutate
 *     (the cross-tenant bypass stays exclusive to the admin DsarService);
 *   - RetentionService::hasActiveLegalHold() + immutable archival status to
 *     BLOCK erasure of held objects (reported back as `held`);
 *   - ObjectEntity::setProcessingActivityId() so the existing immutable,
 *     hash-chained AuditTrailMapper records every fulfilment write under the
 *     configured DSAR processing activity.
 *
 * This is the GENERIC mechanic. Jurisdiction policy (Dutch AVG wording, AP
 * complaint reference, FG/DPO roles, BSN/BRP, 4-eyes, RvIG retention) lives
 * in the consuming app as a thin overlay.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Consumable, RBAC + tenant scoped data-subject-request orchestrator.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Covers all six data-subject rights behind one surface.
 */
class DataSubjectRequestService {

	/**
	 * Erase mode: pseudonymise the subject's matching field values in place.
	 *
	 * @var string
	 */
	public const ERASE_MODE_PSEUDONYMISE = 'pseudonymise';

	/**
	 * Erase mode: soft-delete the whole owning object.
	 *
	 * @var string
	 */
	public const ERASE_MODE_WHOLE_OBJECT = 'whole-object';

	/**
	 * Replacement token written into a pseudonymised field value.
	 *
	 * @var string
	 */
	private const PSEUDONYM_TOKEN = '[erased]';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db GdprEntity + entity_relations join.
	 * @param MagicMapper $objectMapper Object loader (RBAC/tenant scoped reads).
	 * @param ObjectService $objectService Audited write path (saveObject → audit trail).
	 * @param RetentionService $retentionService Legal-hold + immutability guard.
	 * @param DsarService $dsarService Reused only for the configured DSAR activity uuid.
	 * @param DataSubjectDeadline $deadline EU art-12 deadline maths.
	 * @param IUserSession $userSession Current user (erasure metadata).
	 * @param LoggerInterface $logger Logger.
	 * @param ArchivalRetentionGuard $archivalGuard Refuses erasure of a legally retained record.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly MagicMapper $objectMapper,
		private readonly ObjectService $objectService,
		private readonly RetentionService $retentionService,
		private readonly DsarService $dsarService,
		private readonly DataSubjectDeadline $deadline,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ArchivalRetentionGuard $archivalGuard,
	) {

	}//end __construct()

	/**
	 * Discover a subject's objects across all registers (art-15 / art-20).
	 *
	 * Walks the GdprEntity index for rows whose value matches the subject,
	 * joins entity_relations to the owning objects, then loads each object
	 * with RBAC + tenant scoping LEFT ON (unless the caller explicitly opts
	 * out) so the result is limited to what the caller may read.
	 *
	 * Returns a list of envelopes:
	 *   [['object' => <ObjectEntity json>, 'gdprEntities' => [{type,value,category,detectedAt}, ...]], ...]
	 *
	 * @param string $subjectId Subject identifier value (email, …).
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param string $mode `exact` (default) or `ilike`.
	 * @param bool $rbac Apply RBAC scoping (default true).
	 * @param bool $multitenancy Apply tenant scoping (default true).
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function findSubjectData(
		string $subjectId,
		?string $type = null,
		string $mode = 'exact',
		bool $rbac = true,
		bool $multitenancy = true,
	): array {
		$subjectId = trim($subjectId);
		if ($subjectId === '') {
			return [];
		}

		$grouped = $this->discover(subjectId: $subjectId, type: $type, mode: $mode);

		$envelopes = [];
		foreach ($grouped as $entry) {
			$object = $this->loadObject(entry: $entry, rbac: $rbac, multitenancy: $multitenancy);
			if ($object === null) {
				continue;
			}

			$envelopes[] = [
				'object' => $object->jsonSerialize(),
				'gdprEntities' => $entry['gdprEntities'],
			];
		}

		return $envelopes;
	}//end findSubjectData()

	/**
	 * Assemble a portable access export of the subject's data (art-15 / art-20).
	 *
	 * RBAC + tenant scoped (it builds on findSubjectData). Produces a
	 * serialisable bundle recording, per object, which PII attributes
	 * triggered the object's inclusion.
	 *
	 * @param string $subjectId Subject identifier value.
	 * @param string|null $type Optional GdprEntity type filter.
	 *
	 * @return array{subject: string, type: string|null, generatedAt: string, objectCount: int, objects: array}
	 */
	public function assembleAccessExport(string $subjectId, ?string $type = null): array {
		$envelopes = $this->findSubjectData(subjectId: $subjectId, type: $type);

		return [
			'subject' => trim($subjectId),
			'type' => $type,
			'generatedAt' => (new DateTime())->format(DateTime::ATOM),
			'objectCount' => count($envelopes),
			'objects' => $envelopes,
		];

	}//end assembleAccessExport()

	/**
	 * Rectify a single object's fields for the subject (art-16).
	 *
	 * RBAC + tenant scoped object load. Refuses if the object is in an
	 * immutable archival status. Pins the write to the DSAR processing
	 * activity for the audit trail.
	 *
	 * @param string $objectIdentifier Object id/uuid.
	 * @param array<string, mixed> $changes Property → new value map.
	 *
	 * @return array<string, mixed>|null Updated object envelope, or null on miss/refusal.
	 */
	public function rectify(string $objectIdentifier, array $changes): ?array {
		$object = $this->loadByIdentifier(identifier: $objectIdentifier);
		if ($object === null) {
			return null;
		}

		if ($this->retentionService->validateNotImmutable(object: $object) !== null) {
			$this->logger->warning(
				message: '[DSR] Rectify refused — object is in an immutable archival status',
				context: ['object' => $objectIdentifier]
			);
			return null;
		}

		$current = ($object->getObject() ?? []);
		$object->setObject(array_merge($current, $changes));
		$this->attribute(object: $object);

		return $this->persist(object: $object, op: 'rectify', identifier: $objectIdentifier);
	}//end rectify()

	/**
	 * Erase the subject's data across matching objects (art-17).
	 *
	 * The erase MODE is a parameter, not hard-coded, because apps differ:
	 *   - ERASE_MODE_PSEUDONYMISE: replace the subject's matching field
	 *     values in place (field-level), preserving the object;
	 *   - ERASE_MODE_WHOLE_OBJECT: soft-delete the whole owning object.
	 *
	 * Objects under an active legal hold or in an immutable archival status
	 * are NEVER erased; they are reported in the `held` bucket so the caller
	 * can surface a partial result rather than a false "complete".
	 *
	 * RETENTION WINS OVER ERASURE. A record on a schema declaring
	 * `x-openregister-archival` is held by the Archiefwet, and art-17(3)(b)
	 * stands down for it. Those rows are refused and reported in `withheld`,
	 * each carrying its ground, the sentence to pass back to the requester, and
	 * what the handler does next. Withholding one record never blocks the rest
	 * of the request.
	 *
	 * @param string $subjectId Subject identifier value.
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param string $eraseMode One of the ERASE_MODE_* constants.
	 * @param bool $dryRun When true, report matches/holds without mutating.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function erase(
		string $subjectId,
		?string $type = null,
		string $eraseMode = self::ERASE_MODE_PSEUDONYMISE,
		bool $dryRun = false,
	): array {
		if ($eraseMode !== self::ERASE_MODE_WHOLE_OBJECT) {
			$eraseMode = self::ERASE_MODE_PSEUDONYMISE;
		}

		$subjectId = trim($subjectId);
		$grouped = $this->discover(subjectId: $subjectId, type: $type, mode: 'exact');

		$summary = [
			'subject' => $subjectId,
			'type' => $type,
			'eraseMode' => $eraseMode,
			'dryRun' => $dryRun,
			'matchedCount' => count($grouped),
			'erased' => [],
			'held' => [],
			'failed' => [],
			// RETENTION WINS OVER ERASURE: records the Archiefwet keeps are
			// refused, named here with their ground, and reported back with the
			// rest of the answer to the data subject.
			'withheld' => [],
		];

		foreach ($grouped as $entry) {
			$object = $this->loadObject(entry: $entry, rbac: true, multitenancy: true);
			if ($object === null) {
				$summary['failed'][] = [
					'object' => $this->refOf(entry: $entry),
					'error' => 'Object could not be loaded (not found or not authorised)',
				];
				continue;
			}

			// RETENTION WINS OVER ERASURE, AND THE SCHEMA IS WHERE THE OBLIGATION
			// LIVES. `retentionGuard()` below reads the OBJECT's own
			// `retention.archiefstatus` and the legal hold on it; neither says
			// anything about `x-openregister-archival`, which is declared on the
			// SCHEMA. So a record on an archival schema that had not yet been
			// stamped `vernietigd` or `overgebracht` was erased here, past the
			// guard whose docblock claims to cover "immutable archival status".
			// It is asked of Schema::hasArchivalAnnotation() now, through the same
			// definition the four HTTP delete doors use.
			$refusal = $this->archivalGuard->erasureRefusal(object: $object);
			if ($refusal !== null) {
				$summary['withheld'][] = $refusal;
				continue;
			}

			// Retention guard — never erase a held / immutable object.
			$holdReason = $this->retentionGuard(object: $object);
			if ($holdReason !== null) {
				$summary['held'][] = [
					'uuid' => $object->getUuid(),
					'reason' => $holdReason,
				];
				continue;
			}

			if ($dryRun === true) {
				$summary['erased'][] = $this->refOf(entry: $entry, object: $object);
				continue;
			}

			$this->eraseOne(
				object: $object,
				subjectId: $subjectId,
				matched: $entry['gdprEntities'],
				eraseMode: $eraseMode,
				summary: $summary
			);
		}//end foreach

		// A RUN THAT KEPT DATA BACK IS NOT A COMPLETE ERASURE. `complete` used to
		// mean "nothing errored", so a request that lawfully held every single
		// record still answered `complete: true` and the data subject was told
		// their data was gone. Held and withheld rows now count against it too.
		// The buckets stay separate because the answers differ: `failed` is
		// retried, `held` waits for the hold to lift, `withheld` is explained.
		$summary['complete'] = ($summary['failed'] === []
			&& $summary['held'] === []
			&& $summary['withheld'] === []);
		$summary['failedCount'] = count($summary['failed']);
		$summary['heldCount'] = count($summary['held']);
		$summary['withheldCount'] = count($summary['withheld']);

		return $summary;
	}//end erase()

	/**
	 * Flag/unflag processing restriction on a single object (art-18).
	 *
	 * Writes a generic `_gdprRestriction` marker into the object payload
	 * (active flag + reason + timestamp), RBAC/tenant scoped, attributed
	 * to the DSAR activity. Jurisdiction wording stays in the leaf app.
	 *
	 * @param string $objectIdentifier Object id/uuid.
	 * @param bool $restricted Whether processing is restricted.
	 * @param string $reason Reason recorded on the marker.
	 *
	 * @return array<string, mixed>|null Updated object envelope, or null on miss.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function setRestriction(string $objectIdentifier, bool $restricted, string $reason): ?array {
		return $this->setMarker(
			objectIdentifier: $objectIdentifier,
			key: '_gdprRestriction',
			active: $restricted,
			reason: $reason,
			op: 'restrict'
		);

	}//end setRestriction()

	/**
	 * Flag/unflag objection on a single object (art-21).
	 *
	 * @param string $objectIdentifier Object id/uuid.
	 * @param bool $objected Whether the subject objects to processing.
	 * @param string $reason Reason recorded on the marker.
	 *
	 * @return array<string, mixed>|null Updated object envelope, or null on miss.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function setObjection(string $objectIdentifier, bool $objected, string $reason): ?array {
		return $this->setMarker(
			objectIdentifier: $objectIdentifier,
			key: '_gdprObjection',
			active: $objected,
			reason: $reason,
			op: 'object'
		);

	}//end setObjection()

	/**
	 * Compute the EU art-12 base due date (receivedAt + 1 month).
	 *
	 * @param DateTimeInterface $receivedAt When the request was received.
	 *
	 * @return DateTimeImmutable
	 */
	public function computeDueAt(DateTimeInterface $receivedAt): DateTimeImmutable {
		return $this->deadline->computeDueAt(receivedAt: $receivedAt);
	}//end computeDueAt()

	/**
	 * Extend a deadline once by two months (art-12(3)).
	 *
	 * @param DateTimeInterface $dueAt The current base due date.
	 *
	 * @return DateTimeImmutable
	 */
	public function extend(DateTimeInterface $dueAt): DateTimeImmutable {
		return $this->deadline->extend(dueAt: $dueAt);
	}//end extend()

	/**
	 * Whether a deadline has passed.
	 *
	 * @param DateTimeInterface $deadline The (possibly extended) deadline.
	 * @param DateTimeInterface|null $now Reference time (defaults to now).
	 *
	 * @return bool
	 */
	public function isOverdue(DateTimeInterface $deadline, ?DateTimeInterface $now = null): bool {
		return $this->deadline->isOverdue(deadline: $deadline, now: $now);
	}//end isOverdue()

	/**
	 * Erase one object per the selected mode, recording the result.
	 *
	 * @param ObjectEntity $object Object to erase.
	 * @param string $subjectId Subject value (for pseudonymise match + audit).
	 * @param array<int, array> $matched GdprEntity hits that triggered inclusion.
	 * @param string $eraseMode Erase mode constant.
	 * @param array<string, mixed> $summary Summary (mutated by reference).
	 *
	 * @return void
	 */
	private function eraseOne(
		ObjectEntity $object,
		string $subjectId,
		array $matched,
		string $eraseMode,
		array &$summary,
	): void {
		if ($eraseMode === self::ERASE_MODE_WHOLE_OBJECT) {
			$object->setDeleted(
				[
					'deletedBy' => $this->currentUserId(),
					'deletedReason' => 'gdpr-erasure',
					'deleted' => (new DateTime())->format(DateTime::ATOM),
					'subject' => $subjectId,
				]
			);
		}

		if ($eraseMode === self::ERASE_MODE_PSEUDONYMISE) {
			$this->pseudonymise(object: $object, subjectId: $subjectId, matched: $matched);
		}

		$this->attribute(object: $object);

		$ref = $this->refOf(entry: [], object: $object);
		try {
			$this->objectService->saveObject(
				object: $object,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				_rbac: true,
				_multitenancy: true
			);
			$summary['erased'][] = $ref;
		} catch (\Throwable $e) {
			$summary['failed'][] = ['object' => $ref, 'error' => $e->getMessage()];
			$this->logger->warning(
				message: '[DSR] Erase failed',
				context: ['object' => $ref, 'error' => $e->getMessage()]
			);
		}

	}//end eraseOne()

	/**
	 * Replace the subject's matching field values in place (pseudonymise).
	 *
	 * Walks the object payload and, for every scalar value equal to the
	 * subject id or to any matched GdprEntity value, substitutes the
	 * pseudonym token. Deterministic and reversible only via the audit log.
	 *
	 * @param ObjectEntity $object Object whose payload is mutated.
	 * @param string $subjectId Subject identifier value.
	 * @param array<int, array> $matched GdprEntity hits (each has a `value`).
	 *
	 * @return void
	 */
	private function pseudonymise(ObjectEntity $object, string $subjectId, array $matched): void {
		$needles = [strtolower($subjectId)];
		foreach ($matched as $hit) {
			$value = strtolower((string)($hit['value'] ?? ''));
			if ($value !== '') {
				$needles[] = $value;
			}
		}

		$needles = array_values(array_unique($needles));
		$payload = ($object->getObject() ?? []);
		$object->setObject($this->scrub(data: $payload, needles: $needles));

	}//end pseudonymise()

	/**
	 * Recursively scrub matching scalar values from a payload.
	 *
	 * @param array<mixed> $data Payload (or sub-array).
	 * @param array<int, string> $needles Lower-cased values to redact.
	 *
	 * @return array<mixed>
	 */
	private function scrub(array $data, array $needles): array {
		foreach ($data as $key => $value) {
			if (is_array($value) === true) {
				$data[$key] = $this->scrub(data: $value, needles: $needles);
				continue;
			}

			if (is_string($value) === true && in_array(strtolower($value), $needles, true) === true) {
				$data[$key] = self::PSEUDONYM_TOKEN;
			}
		}

		return $data;
	}//end scrub()

	/**
	 * Return a hold/immutability reason that BLOCKS erasure, or null.
	 *
	 * @param ObjectEntity $object Object to check.
	 *
	 * @return string|null `legal-hold`, an immutable status code, or null.
	 */
	private function retentionGuard(ObjectEntity $object): ?string {
		if ($this->retentionService->hasActiveLegalHold(object: $object) === true) {
			return 'legal-hold';
		}

		$immutable = $this->retentionService->validateNotImmutable(object: $object);
		if ($immutable !== null) {
			return $immutable;
		}

		return null;
	}//end retentionGuard()

	/**
	 * Set a generic boolean marker (with reason + timestamp) on an object.
	 *
	 * @param string $objectIdentifier Object id/uuid.
	 * @param string $key Payload key for the marker.
	 * @param bool $active Whether the marker is active.
	 * @param string $reason Reason recorded on the marker.
	 * @param string $op Operation label for logging.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function setMarker(string $objectIdentifier, string $key, bool $active, string $reason, string $op): ?array {
		$object = $this->loadByIdentifier(identifier: $objectIdentifier);
		if ($object === null) {
			return null;
		}

		if ($this->retentionService->validateNotImmutable(object: $object) !== null) {
			return null;
		}

		$payload = ($object->getObject() ?? []);
		$payload[$key] = [
			'active' => $active,
			'reason' => $reason,
			'setBy' => $this->currentUserId(),
			'setAt' => (new DateTime())->format(DateTime::ATOM),
		];
		$object->setObject($payload);
		$this->attribute(object: $object);

		return $this->persist(object: $object, op: $op, identifier: $objectIdentifier);
	}//end setMarker()

	/**
	 * Run the GdprEntity ⋈ entity_relations join, grouped by owning object.
	 *
	 * Mirrors the join shape used by DsarService but is consumed under
	 * RBAC/tenant scoping at object-load time. LIKE wildcards in the
	 * subject are escaped before anchoring.
	 *
	 * @param string $subjectId Subject value.
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param string $mode `exact` or `ilike`.
	 *
	 * @return array<string, array{object_id: int, object_uuid: string, gdprEntities: array}>
	 */
	private function discover(string $subjectId, ?string $type, string $mode): array {
		$hits = $this->matchEntities(subject: $subjectId, type: $type, mode: $mode);

		$grouped = [];
		foreach ($hits as $hit) {
			$uuid = (string)($hit['object_uuid'] ?? '');
			$id = (int)($hit['object_id'] ?? 0);
			$key = 'id:' . $id;
			if ($uuid !== '') {
				$key = 'uuid:' . $uuid;
			}

			if ($uuid === '' && $id <= 0) {
				continue;
			}

			$grouped[$key] ??= [
				'object_id' => $id,
				'object_uuid' => $uuid,
				'gdprEntities' => [],
			];

			$grouped[$key]['gdprEntities'][] = [
				'type' => (string)($hit['type'] ?? ''),
				'value' => (string)($hit['value'] ?? ''),
				'category' => (string)($hit['category'] ?? ''),
				'detectedAt' => (string)($hit['detected_at'] ?? ''),
			];
		}//end foreach

		return $grouped;
	}//end discover()

	/**
	 * The GdprEntity index lookup (one row per entity/object pair).
	 *
	 * @param string $subject Subject value.
	 * @param string|null $type Optional type filter.
	 * @param string $mode `exact` or `ilike`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function matchEntities(string $subject, ?string $type, string $mode): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct(
				[
					'e.id',
					'e.type',
					'e.value',
					'e.category',
					'e.detected_at',
					'r.object_id',
					'r.object_uuid',
				]
			)
				->from('openregister_entities', 'e')
				->innerJoin('e', 'openregister_entity_relations', 'r', $qb->expr()->eq('r.entity_id', 'e.id'))
				->where($qb->expr()->isNotNull('r.object_id'));

			$escaped = $this->db->escapeLikeParameter($subject);
			$pattern = $escaped;
			if ($mode === 'ilike') {
				$pattern = '%' . $escaped . '%';
			}

			$qb->andWhere($qb->expr()->iLike('e.value', $qb->createNamedParameter($pattern)));

			if ($type !== null && $type !== '') {
				$qb->andWhere($qb->expr()->eq('e.type', $qb->createNamedParameter($type)));
			}

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			return $rows;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DSR] GdprEntity lookup failed',
				context: ['subject' => $subject, 'type' => $type, 'error' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end matchEntities()

	/**
	 * Load the owning object for a grouped hit, RBAC/tenant scoped.
	 *
	 * @param array{object_id: int, object_uuid: string} $entry Grouped entry.
	 * @param bool $rbac Apply RBAC.
	 * @param bool $multitenancy Apply tenant scope.
	 *
	 * @return ObjectEntity|null
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function loadObject(array $entry, bool $rbac, bool $multitenancy): ?ObjectEntity {
		$uuid = (string)$entry['object_uuid'];
		$id = (int)$entry['object_id'];

		$identifier = $id;
		if ($uuid !== '') {
			$identifier = $uuid;
		}

		if ($identifier === 0) {
			return null;
		}

		try {
			return $this->objectMapper->find(
				$identifier,
				_rbac: $rbac,
				_multitenancy: $multitenancy
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[DSR] Object load skipped (not found or not authorised)',
				context: ['identifier' => $identifier, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end loadObject()

	/**
	 * Load a single object by id/uuid, RBAC/tenant scoped.
	 *
	 * @param string $identifier Object id or uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function loadByIdentifier(string $identifier): ?ObjectEntity {
		try {
			return $this->objectMapper->find($identifier, _rbac: true, _multitenancy: true);
		} catch (\Throwable $e) {
			return null;
		}

	}//end loadByIdentifier()

	/**
	 * Pin the write to the configured DSAR processing activity for the audit.
	 *
	 * @param ObjectEntity $object Object about to be persisted.
	 *
	 * @return void
	 */
	private function attribute(ObjectEntity $object): void {
		$activity = $this->dsarService->getDsarProcessingActivityUuid();
		if ($activity !== null) {
			$object->setProcessingActivityId($activity);
		}

	}//end attribute()

	/**
	 * Persist an object update and return its serialised envelope, or null.
	 *
	 * @param ObjectEntity $object Object to persist.
	 * @param string $op Operation label for logging.
	 * @param string $identifier Identifier for logging.
	 *
	 * @return array<string, mixed>|null
	 */
	private function persist(ObjectEntity $object, string $op, string $identifier): ?array {
		try {
			$saved = $this->objectService->saveObject(
				object: $object,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				_rbac: true,
				_multitenancy: true
			);
			return $saved->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DSR] ' . $op . ' update failed',
				context: ['object' => $identifier, 'error' => $e->getMessage()]
			);
			return null;
		}

	}//end persist()

	/**
	 * Build a compact object reference for a summary row.
	 *
	 * @param array<string, mixed> $entry Grouped entry (for fallback ids).
	 * @param ObjectEntity|null $object Loaded object (preferred).
	 *
	 * @return array<string, mixed>
	 */
	private function refOf(array $entry, ?ObjectEntity $object = null): array {
		if ($object !== null) {
			return [
				'uuid' => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
			];
		}

		return [
			'object_id' => ($entry['object_id'] ?? 0),
			'object_uuid' => ($entry['object_uuid'] ?? ''),
		];

	}//end refOf()

	/**
	 * Current acting user id, or `system`.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		return ($this->userSession->getUser()?->getUID() ?? 'system');
	}//end currentUserId()
}//end class
