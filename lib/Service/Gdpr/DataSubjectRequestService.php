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
	 * Constructor.
	 *
	 * @param SubjectEntityIndex   $index         GdprEntity ⋈ entity_relations discovery.
	 * @param SubjectObjectLoader  $loader        RBAC/tenant scoped object loads.
	 * @param SubjectObjectWriter  $writer        DSAR-attributed, audited, scoped writes.
	 * @param SubjectRetentionGate $retentionGate Archival / legal-hold / immutability refusals.
	 * @param DataSubjectDeadline  $deadline      EU art-12 deadline maths.
	 * @param LoggerInterface      $logger        Logger.
	 */
	public function __construct(
		private readonly SubjectEntityIndex $index,
		private readonly SubjectObjectLoader $loader,
		private readonly SubjectObjectWriter $writer,
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
	 * @param string $subjectId Subject identifier value (email, …).
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param string $mode `exact` (default) or `ilike`.
	 * @param bool $rbac Apply RBAC scoping (default true).
	 * @param bool $multitenancy Apply tenant scoping (default true).
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function findSubjectData(
		string $subjectId, ?string $type = null, string $mode = 'exact', bool $rbac = true, bool $multitenancy = true
	): array {
		$hits = $this->findSubjectObjects(subjectId: $subjectId, type: $type, mode: $mode, rbac: $rbac, multitenancy: $multitenancy);

		return array_map(
			static fn (array $hit): array => [
				'object' => $hit['object']->jsonSerialize(),
				'gdprEntities' => $hit['gdprEntities'],
			],
			$hits
		);
	}//end findSubjectData()

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
	 * @param string $subjectId Subject identifier value (email, …).
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param string $mode `exact` (default) or `ilike`.
	 * @param bool $rbac Apply RBAC scoping (default true).
	 * @param bool $multitenancy Apply tenant scoping (default true).
	 *
	 * @return array<int, array{object: ObjectEntity, gdprEntities: array}> The loaded hits.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function findSubjectObjects(
		string $subjectId, ?string $type = null, string $mode = 'exact', bool $rbac = true, bool $multitenancy = true
	): array {
		$subjectId = trim($subjectId);
		if ($subjectId === '') {
			return [];
		}

		$hits = [];
		foreach ($this->index->discover(subjectId: $subjectId, type: $type, mode: $mode) as $entry) {
			$object = $this->loader->loadEntry(entry: $entry, rbac: $rbac, multitenancy: $multitenancy);
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
	 * @param ObjectEntity $object The object to scrub.
	 * @param string $subjectId Subject identifier value.
	 * @param array<int, array> $matched GdprEntity hits that triggered inclusion.
	 *
	 * @return array<string, mixed>|null The persisted envelope, or null on failure.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function pseudonymiseObject(ObjectEntity $object, string $subjectId, array $matched): ?array {
		$this->pseudonymise(object: $object, subjectId: $subjectId, matched: $matched);
		$this->writer->attribute(object: $object);

		return $this->writer->persist(
			object: $object,
			op: 'erase',
			identifier: (string)($object->getUuid() ?? '')
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
		$object = $this->loader->loadByIdentifier(identifier: $objectIdentifier);
		if ($object === null || $this->refusesRectify(object: $object, identifier: $objectIdentifier) === true) {
			return null;
		}

		$current = ($object->getObject() ?? []);
		$object->setObject(array_merge($current, $changes));
		$this->writer->attribute(object: $object);

		return $this->writer->persist(object: $object, op: 'rectify', identifier: $objectIdentifier);
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
		$eraseMode = $this->normaliseEraseMode(eraseMode: $eraseMode);
		$subjectId = trim($subjectId);
		$grouped = $this->index->discover(subjectId: $subjectId, type: $type, mode: 'exact');
		$summary = $this->openSummary(subjectId: $subjectId, type: $type, eraseMode: $eraseMode, dryRun: $dryRun, matchedCount: count($grouped));

		foreach ($grouped as $entry) {
			$this->eraseEntry(entry: $entry, subjectId: $subjectId, eraseMode: $eraseMode, dryRun: $dryRun, summary: $summary);
		}

		return $this->closeSummary(summary: $summary);
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
	 * The empty erasure summary, before any candidate is decided.
	 *
	 * @param string      $subjectId    Trimmed subject value.
	 * @param string|null $type         GdprEntity type filter.
	 * @param string      $eraseMode    Normalised erase mode.
	 * @param bool        $dryRun       Whether nothing is written.
	 * @param int         $matchedCount Number of objects the index matched.
	 *
	 * @return array<string, mixed>
	 */
	private function openSummary(string $subjectId, ?string $type, string $eraseMode, bool $dryRun, int $matchedCount): array {
		return [
			'subject' => $subjectId,
			'type' => $type,
			'eraseMode' => $eraseMode,
			'dryRun' => $dryRun,
			'matchedCount' => $matchedCount,
			'erased' => [],
			SubjectRetentionGate::BUCKET_HELD => [],
			'failed' => [],
			// RETENTION WINS OVER ERASURE: records the Archiefwet keeps are
			// refused, named here with their ground, and reported back with the
			// rest of the answer to the data subject.
			SubjectRetentionGate::BUCKET_WITHHELD => [],
		];
	}//end openSummary()

	/**
	 * Close the summary: completeness and per-bucket counts.
	 *
	 * A RUN THAT KEPT DATA BACK IS NOT A COMPLETE ERASURE. `complete` used to
	 * mean "nothing errored", so a request that lawfully held every single
	 * record still answered `complete: true` and the data subject was told
	 * their data was gone. Held and withheld rows now count against it too.
	 * The buckets stay separate because the answers differ: `failed` is
	 * retried, `held` waits for the hold to lift, `withheld` is explained.
	 *
	 * @param array<string, mixed> $summary The filled summary.
	 *
	 * @return array<string, mixed>
	 */
	private function closeSummary(array $summary): array {
		$summary['complete'] = ($summary['failed'] === []
			&& $summary['held'] === []
			&& $summary['withheld'] === []);
		$summary['failedCount'] = count($summary['failed']);
		$summary['heldCount'] = count($summary['held']);
		$summary['withheldCount'] = count($summary['withheld']);

		return $summary;
	}//end closeSummary()

	/**
	 * Decide and (unless refused or dry) erase one discovered object.
	 *
	 * Loaded RBAC + tenant scoped; an object the caller cannot load is
	 * reported `failed`. Refusals are asked before any write.
	 *
	 * @param array<string, mixed> $entry     Grouped index entry.
	 * @param string               $subjectId Subject value.
	 * @param string               $eraseMode Normalised erase mode.
	 * @param bool                 $dryRun    Whether nothing is written.
	 * @param array<string, mixed> $summary   Summary (mutated by reference).
	 *
	 * @return void
	 */
	private function eraseEntry(array $entry, string $subjectId, string $eraseMode, bool $dryRun, array &$summary): void {
		$object = $this->loader->loadEntry(entry: $entry, rbac: true, multitenancy: true);
		if ($object === null) {
			$summary['failed'][] = $this->loadFailure(entry: $entry);
			return;
		}

		$verdict = $this->verdictFor(object: $object, entry: $entry, dryRun: $dryRun);
		if ($verdict !== null) {
			$summary[$verdict[0]][] = $verdict[1];
			return;
		}

		$this->eraseOne(object: $object, subjectId: $subjectId, matched: $entry['gdprEntities'], eraseMode: $eraseMode, summary: $summary);
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
	 * @param ObjectEntity         $object Loaded object.
	 * @param array<string, mixed> $entry  Grouped index entry.
	 * @param bool                 $dryRun Whether nothing is written.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null [bucket, row], or null to erase.
	 */
	private function verdictFor(ObjectEntity $object, array $entry, bool $dryRun): ?array {
		$block = $this->retentionGate->erasureBlock(object: $object);
		if ($block !== null) {
			return $block;
		}

		if ($dryRun === true) {
			return ['erased', $this->refOf(entry: $entry, object: $object)];
		}

		return null;
	}//end verdictFor()

	/**
	 * Erase one object per the selected mode, recording the result.
	 *
	 * @param ObjectEntity         $object    Object to erase.
	 * @param string               $subjectId Subject value (for pseudonymise match + audit).
	 * @param array<int, array>    $matched   GdprEntity hits that triggered inclusion.
	 * @param string               $eraseMode Erase mode constant.
	 * @param array<string, mixed> $summary   Summary (mutated by reference).
	 *
	 * @return void
	 */
	private function eraseOne(ObjectEntity $object, string $subjectId, array $matched, string $eraseMode, array &$summary): void {
		$this->applyEraseMode(object: $object, subjectId: $subjectId, matched: $matched, eraseMode: $eraseMode);
		$this->writer->attribute(object: $object);

		$ref = $this->refOf(entry: [], object: $object);
		try {
			$this->writer->save(object: $object);
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
	 * Mutate the object per the erase mode: soft-delete stamp or pseudonymise.
	 *
	 * @param ObjectEntity      $object    Object to erase.
	 * @param string            $subjectId Subject value.
	 * @param array<int, array> $matched   GdprEntity hits that triggered inclusion.
	 * @param string            $eraseMode Erase mode constant.
	 *
	 * @return void
	 */
	private function applyEraseMode(ObjectEntity $object, string $subjectId, array $matched, string $eraseMode): void {
		if ($eraseMode === self::ERASE_MODE_WHOLE_OBJECT) {
			$object->setDeleted($this->erasureStamp(subjectId: $subjectId));
		}

		if ($eraseMode === self::ERASE_MODE_PSEUDONYMISE) {
			$this->pseudonymise(object: $object, subjectId: $subjectId, matched: $matched);
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
			'deletedBy' => $this->writer->currentUserId(),
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
	 * @param string $objectIdentifier Object id/uuid.
	 * @param string $key              Payload key for the marker.
	 * @param bool   $active           Whether the marker is active.
	 * @param string $reason           Reason recorded on the marker.
	 * @param string $op               Operation label for logging.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function setMarker(string $objectIdentifier, string $key, bool $active, string $reason, string $op): ?array {
		$object = $this->loader->loadByIdentifier(identifier: $objectIdentifier);
		if ($object === null || $this->retentionGate->isImmutable(object: $object) === true) {
			return null;
		}

		$payload = ($object->getObject() ?? []);
		$payload[$key] = $this->marker(active: $active, reason: $reason);
		$object->setObject($payload);
		$this->writer->attribute(object: $object);

		return $this->writer->persist(object: $object, op: $op, identifier: $objectIdentifier);
	}//end setMarker()

	/**
	 * The marker value written by setMarker().
	 *
	 * @param bool   $active Whether the marker is active.
	 * @param string $reason Reason recorded on the marker.
	 *
	 * @return array{active: bool, reason: string, setBy: string, setAt: string}
	 */
	private function marker(bool $active, string $reason): array {
		return [
			'active' => $active,
			'reason' => $reason,
			'setBy' => $this->writer->currentUserId(),
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
