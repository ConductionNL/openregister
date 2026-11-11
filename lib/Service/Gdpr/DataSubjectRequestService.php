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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectEntityIndex;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectLoader;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectObjectWriter;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectWriteLabel;
use OCA\OpenRegister\Service\Gdpr\Subject\SubjectRetentionGate;
use Psr\Log\LoggerInterface;

/**
 * Consumable, RBAC + tenant scoped data-subject-request orchestrator.
 *
 * Discovery runs through {@see SubjectEntityIndex}, every object load through
 * {@see SubjectObjectLoader} (RBAC + tenant scoped), every refusal through
 * {@see SubjectRetentionGate} and every write through {@see SubjectObjectWriter}
 * (DSAR-attributed, audited, scoped). This class decides only what each right
 * does with the objects those collaborators hand it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Covers all six data-subject rights behind one surface.
 */
class DataSubjectRequestService implements DataSubjectRequestServiceInterface {

	/**
	 * Replacement token written into a pseudonymised field value.
	 *
	 * @var string
	 */
	private const PSEUDONYM_TOKEN = '[erased]';

	/**
	 * The payload key each marker operation writes: art-18 restriction, art-21 objection.
	 *
	 * @var array<string, string>
	 */
	private const MARKER_KEYS = [
		'restrict' => '_gdprRestriction',
		'object' => '_gdprObjection',
	];

	/**
	 * Constructor.
	 *
	 * @param SubjectRecords $records Discovery, scoped loads and audited writes of a subject's objects.
	 * @param SubjectRetentionGate $retentionGate Archival / legal-hold / immutability refusals.
	 * @param DataSubjectDeadline  $deadline      EU art-12 deadline maths.
	 * @param LoggerInterface      $logger        Logger.
	 */
	public function __construct(
		private readonly SubjectRecords $records,
		private readonly SubjectRetentionGate $retentionGate,
		private readonly DataSubjectDeadline $deadline,
		private readonly LoggerInterface $logger,
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
	 * @param SubjectQuery $query        The subject value, optional GdprEntity type and match mode.
	 * @param bool         $rbac         Apply RBAC scoping (default true).
	 * @param bool         $multitenancy Apply tenant scoping (default true).
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function findSubjectData(SubjectQuery $query, bool $rbac = true, bool $multitenancy = true): array {
		return $this->serialiseHits(hits: $this->findSubjectObjects(query: $query, rbac: $rbac, multitenancy: $multitenancy));
	}//end findSubjectData()

	/**
	 * The discovery hits as the HTTP envelope: the object's json and its hits.
	 *
	 * @param array<int, array{object: ObjectEntity, gdprEntities: array}> $hits The loaded hits.
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 */
	private function serialiseHits(array $hits): array {
		return array_map(
			static fn (array $hit): array => [
				'object' => $hit['object']->jsonSerialize(),
				'gdprEntities' => $hit['gdprEntities'],
			],
			$hits
		);
	}//end serialiseHits()

	/**
	 * The same cross-register discovery, handing back the loaded entities.
	 *
	 * {@see findSubjectData()} serialises every hit, which is what an HTTP
	 * caller wants and exactly what a caller that has to ASK something of the
	 * object cannot use: a hold, a schema and a destruction scope are all
	 * questions of the ObjectEntity, not of its json. The erasure preview
	 * needs those, so the discovery it shares with the export is exposed here
	 * once rather than copied with the join.
	 *
	 * @param SubjectQuery $query The subject value, optional GdprEntity type and match mode.
	 * @param bool $rbac Apply RBAC scoping (default true).
	 * @param bool $multitenancy Apply tenant scoping (default true).
	 *
	 * @return array<int, array{object: ObjectEntity, gdprEntities: array}> The loaded hits.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function findSubjectObjects(SubjectQuery $query, bool $rbac = true, bool $multitenancy = true): array {
		$subjectId = trim($query->subjectId);
		if ($subjectId === '') {
			return [];
		}

		$hits = [];
		$trimmed = new SubjectQuery(subjectId: $subjectId, type: $query->type, mode: $query->mode);
		foreach ($this->records->index->discover(query: $trimmed) as $entry) {
			$object = $this->records->loader->loadEntry(entry: $entry, rbac: $rbac, multitenancy: $multitenancy);
			if ($object !== null) {
				$hits[] = ['object' => $object, 'gdprEntities' => $entry['gdprEntities']];
			}
		}

		return $hits;
	}//end findSubjectObjects()

	/**
	 * Pseudonymise one already-loaded object, through the audited write path.
	 *
	 * The per-object half of {@see erase()}, so a caller that has already
	 * decided WHICH objects to pseudonymise — the erasure preview has, and it
	 * decided per object rather than per request — does not have to re-run the
	 * discovery and re-make the decision to reach the same write.
	 *
	 * @param SubjectMatch $match     The object to scrub and the GdprEntity hits that triggered inclusion.
	 * @param string       $subjectId Subject identifier value.
	 *
	 * @return array<string, mixed>|null The persisted envelope, or null on failure.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function pseudonymiseObject(SubjectMatch $match, string $subjectId): ?array {
		$this->pseudonymise(match: $match, subjectId: $subjectId);
		$this->records->writer->attribute(object: $match->object);

		return $this->records->writer->persistAs(
			object: $match->object,
			label: new SubjectWriteLabel(op: 'erase', identifier: (string)($match->object->getUuid() ?? ''))
		);
	}//end pseudonymiseObject()

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
		$envelopes = $this->findSubjectData(query: new SubjectQuery(subjectId: $subjectId, type: $type));

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
		$object = $this->records->loader->loadByIdentifier(identifier: $objectIdentifier);
		if ($object === null || $this->refusesRectify(object: $object, identifier: $objectIdentifier) === true) {
			return null;
		}

		$current = ($object->getObject() ?? []);
		$object->setObject(array_merge($current, $changes));
		$this->records->writer->attribute(object: $object);

		return $this->records->writer->persistAs(object: $object, label: new SubjectWriteLabel(op: 'rectify', identifier: $objectIdentifier));
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
	 * The subject is trimmed and an unknown erase mode pseudonymises.
	 *
	 * @param ErasureRequest $request The subject, type filter, erase mode and dry-run switch.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function erase(ErasureRequest $request): array {
		$eraseMode = $this->normaliseEraseMode(eraseMode: $request->eraseMode);
		$subjectId = trim($request->subjectId);

		return $this->eraseFor(request: new ErasureRequest(subjectId: $subjectId, type: $request->type, eraseMode: $eraseMode, dryRun: $request->dryRun));
	}//end erase()

	/**
	 * Erase the subject's data across matching objects, for one normalised request.
	 *
	 * @param ErasureRequest $request The erasure: trimmed subject, type filter, normalised mode, dry run.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function eraseFor(ErasureRequest $request): array {
		$grouped = $this->records->index->discover(query: new SubjectQuery(subjectId: $request->subjectId, type: $request->type));
		$run = new ErasureRun(request: $request, matchedCount: count($grouped));

		foreach ($grouped as $entry) {
			$this->eraseEntry(entry: $entry, run: $run);
		}

		return $run->close();
	}//end eraseFor()

	/**
	 * Flag/unflag processing restriction on a single object (art-18).
	 *
	 * Writes a generic `_gdprRestriction` marker into the object payload
	 * (active flag + reason + timestamp), RBAC/tenant scoped, attributed
	 * to the DSAR activity. Jurisdiction wording stays in the leaf app.
	 *
	 * @param string         $objectIdentifier Object id/uuid.
	 * @param ProcessingFlag $flag             Whether processing is restricted, and the reason recorded on the marker.
	 *
	 * @return array<string, mixed>|null Updated object envelope, or null on miss.
	 */
	public function setRestriction(string $objectIdentifier, ProcessingFlag $flag): ?array {
		return $this->setMarker(
			objectIdentifier: $objectIdentifier,
			marker: new ProcessingMarker(op: 'restrict', active: $flag->active, reason: $flag->reason)
		);

	}//end setRestriction()

	/**
	 * Flag/unflag objection on a single object (art-21).
	 *
	 * @param string         $objectIdentifier Object id/uuid.
	 * @param ProcessingFlag $flag             Whether the subject objects to processing, and the reason recorded on the marker.
	 *
	 * @return array<string, mixed>|null Updated object envelope, or null on miss.
	 */
	public function setObjection(string $objectIdentifier, ProcessingFlag $flag): ?array {
		return $this->setMarker(
			objectIdentifier: $objectIdentifier,
			marker: new ProcessingMarker(op: 'object', active: $flag->active, reason: $flag->reason)
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
	 * Any mode other than whole-object erases by pseudonymising.
	 *
	 * @param string $eraseMode Requested erase mode.
	 *
	 * @return string One of the ERASE_MODE_* constants.
	 */
	private function normaliseEraseMode(string $eraseMode): string {
		if ($eraseMode !== self::ERASE_MODE_WHOLE_OBJECT) {
			return self::ERASE_MODE_PSEUDONYMISE;
		}

		return $eraseMode;
	}//end normaliseEraseMode()

	/**
	 * Decide and (unless refused or dry) erase one discovered object.
	 *
	 * Loaded RBAC + tenant scoped; an object the caller cannot load is
	 * reported `failed`. Refusals are asked before any write.
	 *
	 * @param array<string, mixed> $entry Grouped index entry.
	 * @param ErasureRun           $run   The erasure in progress: request and summary.
	 *
	 * @return void
	 */
	private function eraseEntry(array $entry, ErasureRun $run): void {
		$object = $this->records->loader->loadEntry(entry: $entry, rbac: true, multitenancy: true);
		if ($object === null) {
			$run->record(bucket: 'failed', row: $this->loadFailure(entry: $entry));
			return;
		}

		$verdict = $this->verdictFor(object: $object, dryRun: $run->request->dryRun);
		if ($verdict !== null) {
			$run->record(bucket: $verdict[0], row: $verdict[1]);
			return;
		}

		$this->eraseOne(match: new SubjectMatch(object: $object, matched: $entry['gdprEntities']), run: $run);
	}//end eraseEntry()

	/**
	 * The summary row of an object that could not be loaded.
	 *
	 * @param array<string, mixed> $entry Grouped index entry.
	 *
	 * @return array{object: array<string, mixed>, error: string}
	 */
	private function loadFailure(array $entry): array {
		return [
			'object' => $this->refOf(entry: $entry),
			'error' => 'Object could not be loaded (not found or not authorised)',
		];
	}//end loadFailure()

	/**
	 * Where a loaded object goes without a write, or null when it is erased.
	 *
	 * Withheld (archival) and held (legal hold / immutable) come first; a
	 * dry run then reports the object as erasable without touching it.
	 *
	 * @param ObjectEntity $object Loaded object.
	 * @param bool         $dryRun Whether nothing is written.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null [bucket, row], or null to erase.
	 */
	private function verdictFor(ObjectEntity $object, bool $dryRun): ?array {
		$block = $this->retentionGate->erasureBlock(object: $object);
		if ($block !== null) {
			return $block;
		}

		if ($dryRun === true) {
			return ['erased', $this->refOf(entry: [], object: $object)];
		}

		return null;
	}//end verdictFor()

	/**
	 * Erase one object per the selected mode, recording the result.
	 *
	 * @param SubjectMatch $match The object to erase and the hits that triggered inclusion.
	 * @param ErasureRun   $run   The erasure in progress: subject (for pseudonymise match + audit), mode, summary.
	 *
	 * @return void
	 */
	private function eraseOne(SubjectMatch $match, ErasureRun $run): void {
		$object = $match->object;
		$this->applyEraseMode(match: $match, request: $run->request);
		$this->records->writer->attribute(object: $object);

		$ref = $this->refOf(entry: [], object: $object);
		try {
			$this->records->writer->save(object: $object);
			$run->record(bucket: 'erased', row: $ref);
		} catch (\Throwable $e) {
			$run->record(bucket: 'failed', row: ['object' => $ref, 'error' => $e->getMessage()]);
			$this->logger->warning(
				message: '[DSR] Erase failed',
				context: ['object' => $ref, 'error' => $e->getMessage()]
			);
		}
	}//end eraseOne()

	/**
	 * Mutate the object per the erase mode: soft-delete stamp or pseudonymise.
	 *
	 * @param SubjectMatch   $match   The object to erase and the hits that triggered inclusion.
	 * @param ErasureRequest $request The erasure: subject value and mode.
	 *
	 * @return void
	 */
	private function applyEraseMode(SubjectMatch $match, ErasureRequest $request): void {
		if ($request->eraseMode === self::ERASE_MODE_WHOLE_OBJECT) {
			$match->object->setDeleted($this->erasureStamp(subjectId: $request->subjectId));
		}

		if ($request->eraseMode === self::ERASE_MODE_PSEUDONYMISE) {
			$this->pseudonymise(match: $match, subjectId: $request->subjectId);
		}
	}//end applyEraseMode()

	/**
	 * The soft-delete metadata of a whole-object erasure.
	 *
	 * @param string $subjectId Subject value.
	 *
	 * @return array<string, string>
	 */
	private function erasureStamp(string $subjectId): array {
		return [
			'deletedBy' => $this->records->writer->currentUserId(),
			'deletedReason' => 'gdpr-erasure',
			'deleted' => (new DateTime())->format(DateTime::ATOM),
			'subject' => $subjectId,
		];
	}//end erasureStamp()

	/**
	 * Replace the subject's matching field values in place (pseudonymise).
	 *
	 * Walks the object payload and, for every scalar value equal to the
	 * subject id or to any matched GdprEntity value, substitutes the
	 * pseudonym token. Deterministic and reversible only via the audit log.
	 *
	 * @param SubjectMatch $match     Object whose payload is mutated, with the GdprEntity hits (each has a `value`).
	 * @param string       $subjectId Subject identifier value.
	 *
	 * @return void
	 */
	private function pseudonymise(SubjectMatch $match, string $subjectId): void {
		$object = $match->object;
		$needles = [strtolower($subjectId)];
		foreach ($match->matched as $hit) {
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
	 * Whether rectify must refuse an immutable object (logged when it does).
	 *
	 * @param ObjectEntity $object     Loaded object.
	 * @param string       $identifier Object id/uuid as requested.
	 *
	 * @return bool
	 */
	private function refusesRectify(ObjectEntity $object, string $identifier): bool {
		if ($this->retentionGate->isImmutable(object: $object) === false) {
			return false;
		}

		$this->logger->warning(
			message: '[DSR] Rectify refused — object is in an immutable archival status',
			context: ['object' => $identifier]
		);
		return true;
	}//end refusesRectify()

	/**
	 * Set a generic boolean marker (with reason + timestamp) on an object.
	 *
	 * @param string           $objectIdentifier Object id/uuid.
	 * @param ProcessingMarker $marker           The marker: its op (names the payload key in MARKER_KEYS), active flag and reason.
	 *
	 * @return array<string, mixed>|null
	 */
	private function setMarker(string $objectIdentifier, ProcessingMarker $marker): ?array {
		$object = $this->records->loader->loadByIdentifier(identifier: $objectIdentifier);
		if ($object === null || $this->retentionGate->isImmutable(object: $object) === true) {
			return null;
		}

		$payload = ($object->getObject() ?? []);
		$payload[self::MARKER_KEYS[$marker->op]] = $this->marker(marker: $marker);
		$object->setObject($payload);
		$this->records->writer->attribute(object: $object);

		return $this->records->writer->persistAs(object: $object, label: new SubjectWriteLabel(op: $marker->op, identifier: $objectIdentifier));
	}//end setMarker()

	/**
	 * The marker value written by setMarker().
	 *
	 * @param ProcessingMarker $marker The marker: active flag and reason.
	 *
	 * @return array{active: bool, reason: string, setBy: string, setAt: string}
	 */
	private function marker(ProcessingMarker $marker): array {
		return [
			'active' => $marker->active,
			'reason' => $marker->reason,
			'setBy' => $this->records->writer->currentUserId(),
			'setAt' => (new DateTime())->format(DateTime::ATOM),
		];
	}//end marker()

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
}//end class
