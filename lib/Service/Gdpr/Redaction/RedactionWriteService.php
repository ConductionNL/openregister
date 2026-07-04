<?php

/**
 * OpenRegister Gdpr RedactionWriteService
 *
 * Applies a field-level redaction to a data-subject-request case: it records a
 * `redactions` entry (`field` / `before` / `after` / `ground`) on the case
 * through {@see CaseObjectAccessor} (ObjectService, RBAC + multitenancy) and
 * audits it via the case's immutable hash-chained trail (the accessor pins the
 * write to the DSAR processing activity).
 *
 * This is a PRE-BUNDLE, field-level action recording its own before/after — it
 * is deliberately DISTINCT from the statutory erase-time pseudonymise performed
 * by {@see DataSubjectRequestService::erase(mode=pseudonymise)} and MUST NOT
 * invoke that erase path. A redaction entry is distinguishable from an erase
 * pseudonymise record: it lives in the case's `redactions` sub-collection and
 * carries a `redaction` recordType marker, whereas erase mutates matched PII
 * field values in place across the subject's objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Redaction
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

namespace OCA\OpenRegister\Service\Gdpr\Redaction;

use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use RuntimeException;

/**
 * Field-level redaction write path (distinct from erase pseudonymise).
 */
class RedactionWriteService
{

    /**
     * Marker distinguishing a redaction record from an erase pseudonymise.
     *
     * @var string
     */
    public const RECORD_TYPE = 'redaction';

    /**
     * Constructor.
     *
     * @param CaseObjectAccessor $accessor RBAC-scoped, audited case load/save.
     */
    public function __construct(
        private readonly CaseObjectAccessor $accessor
    ) {
    }//end __construct()

    /**
     * Apply a field-level redaction to a case field.
     *
     * Captures the field's current value as `before`, sets the redacted `after`
     * value on the case field, and appends a `redactions` entry recording
     * `field` / `before` / `after` / `ground`. Persists once (audited). It does
     * NOT call the erase pseudonymise path.
     *
     * @param string $caseUuid The case object uuid.
     * @param string $field    The case field being redacted.
     * @param string $after    The redacted replacement value.
     * @param string $ground   The generic ground key the redaction was applied under.
     *
     * @return array{caseUuid: string, field: string, before: string, after: string, ground: string, recordType: string}
     *
     * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-redaction-write/spec.md
     */
    public function applyRedaction(string $caseUuid, string $field, string $after, string $ground): array
    {
        $case = $this->accessor->load(caseUuid: $caseUuid);
        if ($case === null) {
            throw new RuntimeException(
                message: sprintf('Case "%s" not found or not authorised.', $caseUuid)
            );
        }

        $data = $case->getObject();

        // Snapshot the original value before we overwrite it. This is a
        // field-level capture on the case object — NOT the statutory erase
        // pseudonymise across the subject's objects.
        $before = '';
        if (isset($data[$field]) === true && is_scalar($data[$field]) === true) {
            $before = (string) $data[$field];
        }

        // Apply the redacted value to the case field.
        $data[$field] = $after;

        $entry = [
            'field'      => $field,
            'before'     => $before,
            'after'      => $after,
            'ground'     => $ground,
            'recordType' => self::RECORD_TYPE,
        ];

        $redactions = [];
        if (isset($data['redactions']) === true && is_array($data['redactions']) === true) {
            $redactions = array_values($data['redactions']);
        }

        $redactions[]       = $entry;
        $data['redactions'] = $redactions;

        $this->accessor->save(case: $case, data: $data);

        return [
            'caseUuid'   => $caseUuid,
            'field'      => $field,
            'before'     => $before,
            'after'      => $after,
            'ground'     => $ground,
            'recordType' => self::RECORD_TYPE,
        ];
    }//end applyRedaction()
}//end class
