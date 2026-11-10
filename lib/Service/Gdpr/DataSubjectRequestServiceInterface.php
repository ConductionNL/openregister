<?php

/**
 * DataSubjectRequestServiceInterface: what callers outside the component use of DataSubjectRequestService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The data-subject request operations the GDPR rights endpoints call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DataSubjectRequestServiceInterface {

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
	public function assembleAccessExport(string $subjectId, ?string $type = null): array;

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
	): array;

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
	): array;

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
	public function rectify(string $objectIdentifier, array $changes): ?array;

	/**
	 * Flag/unflag objection on a single object (art-21).
	 *
	 * @param string         $objectIdentifier Object id/uuid.
	 * @param ProcessingFlag $flag             Whether the subject objects to processing, and the reason recorded on the marker.
	 *
	 * @return array<string, mixed>|null Updated object envelope, or null on miss.
	 */
	public function setObjection(string $objectIdentifier, ProcessingFlag $flag): ?array;

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
	public function setRestriction(string $objectIdentifier, ProcessingFlag $flag): ?array;
}//end interface
