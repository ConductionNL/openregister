<?php

/**
 * OpenRegister Gdpr CaseObjectAccessor
 *
 * Thin shared helper the case-engine services use to load and persist a
 * data-subject-request case through {@see ObjectService} under RBAC +
 * multitenancy (ADR-001 — no custom Entity/Mapper), pinning every write to the
 * DSAR processing activity so the immutable, hash-chained audit trail records it
 * (ADR-022). Centralised here so the harvest, redaction, bundle and sweep
 * services do not each re-implement the load/pin/save dance (ADR-011).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Case
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

namespace OCA\OpenRegister\Service\Gdpr\Case;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Load + audited-save helper for data-subject-request case objects.
 */
class CaseObjectAccessor
{

    /**
     * Register slug the case objects live under (declared by the head).
     *
     * @var string
     */
    public const REGISTER_SLUG = 'data-subject-requests';

    /**
     * Schema slug of the case entity (declared by the head).
     *
     * @var string
     */
    public const SCHEMA_SLUG = 'dataSubjectRequest';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService RBAC + tenant scoped object store.
     * @param DsarService   $dsarService   Resolves the DSAR processing activity for audit pinning.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly DsarService $dsarService
    ) {
    }//end __construct()

    /**
     * Load a case object by uuid under the caller's RBAC + tenant scope.
     *
     * @param string $caseUuid The case object uuid/id.
     *
     * @return ObjectEntity|null The case, or null when absent or unauthorised.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-case-api/spec.md
     */
    public function load(string $caseUuid): ?ObjectEntity
    {
        return $this->objectService->find(
            id: $caseUuid,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            _rbac: true,
            _multitenancy: true
        );
    }//end load()

    /**
     * Load every case object in the register/schema as ObjectEntity instances.
     *
     * Used by the retention sweep, which needs the entities (not rendered
     * arrays) so it can consult RetentionService legal-hold / immutability on
     * each. Queries via ObjectService (RBAC + tenant scoped), then re-loads each
     * result by uuid so the sweep operates on live entities.
     *
     * @return ObjectEntity[] The case entities (possibly empty).
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-retention-sweep/spec.md
     */
    public function findAllCaseEntities(): array
    {
        $rendered = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => self::REGISTER_SLUG,
                    'schema'   => self::SCHEMA_SLUG,
                ],
            ],
            _rbac: true,
            _multitenancy: true
        );

        $entities = [];
        foreach ($rendered as $row) {
            $uuid = '';
            if (is_array($row) === true) {
                $uuid = (string) ($row['@self']['uuid'] ?? ($row['id'] ?? ''));
            }

            if ($uuid === '') {
                continue;
            }

            $entity = $this->load(caseUuid: $uuid);
            if ($entity !== null) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }//end findAllCaseEntities()

    /**
     * Delete a case dossier via ObjectService, flagged as a retention sweep.
     *
     * The retention-sweep flag lets the delete pass the archival-annotation
     * guard that rejects user-driven deletes; the delete is still RBAC + tenant
     * scoped and audited.
     *
     * @param string $caseUuid The case object uuid.
     *
     * @return bool True when the dossier was deleted.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-retention-sweep/spec.md
     */
    public function deleteForSweep(string $caseUuid): bool
    {
        return $this->objectService->deleteObject(
            uuid: $caseUuid,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            _rbac: true,
            _multitenancy: true,
            _retentionSweep: true
        );
    }//end deleteForSweep()

    /**
     * Persist a mutated case, pinned to the DSAR processing activity so the
     * immutable audit trail records the write under that activity.
     *
     * @param ObjectEntity         $case The loaded case entity.
     * @param array<string, mixed> $data The full replacement payload for the case.
     *
     * @return ObjectEntity The saved case.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-case-api/spec.md
     */
    public function save(ObjectEntity $case, array $data): ObjectEntity
    {
        $case->setObject($data);

        $activity = $this->dsarService->getDsarProcessingActivityUuid();
        if ($activity !== null) {
            $case->setProcessingActivityId($activity);
        }

        return $this->objectService->saveObject(
            object: $case,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            uuid: $case->getUuid(),
            _rbac: true,
            _multitenancy: true
        );
    }//end save()
}//end class
