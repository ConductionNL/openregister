<?php

/**
 * OpenRegister Gdpr EvidenceSourceProvider
 *
 * Public contract a leaf app implements to contribute evidence-harvest sources
 * for a data-subject-request case (e.g. an OpenConnector-backed source reaching
 * an external system). Providers are registered into
 * {@see EvidenceSourceRegistry} at app bootstrap (ADR-019), so OpenRegister core
 * never hard-codes the list of sources: a source that is not registered cannot
 * contribute evidence.
 *
 * Reaching outside OpenRegister to harvest, hash, and stage evidence is the
 * ADR-003 / ADR-031 "external API integration" imperative exception — the
 * schema engine cannot reach external systems or compute content hashes. The
 * head declared only the `evidence` sub-collection SHAPE; harvesting is code.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Evidence
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

namespace OCA\OpenRegister\Service\Gdpr\Evidence;

/**
 * A registerable source of evidence items for a case.
 */
interface EvidenceSourceProvider
{
    /**
     * Stable source id recorded on every evidence item this provider harvests.
     *
     * @return string The provider id (e.g. `openconnector-crm`).
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function getSourceId(): string;

    /**
     * Whether this provider can harvest on this instance right now (e.g. the
     * backing app is installed and reachable). A disabled provider is skipped
     * by the harvest service.
     *
     * @return bool True when the provider is usable.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function isEnabled(): bool;

    /**
     * Harvest evidence items for a data-subject-request case.
     *
     * The provider is handed the case's serialised payload (subject identifier,
     * request type, handler, existing evidence, …) so it can decide what to
     * collect. Each returned item MUST carry a stable `contentHash` so the
     * harvest service can deduplicate idempotently across re-runs, and a
     * per-item `status` so a slow/failed source is visible on the case.
     *
     * @param string               $caseUuid The case object uuid.
     * @param array<string, mixed> $case     The case's serialised payload.
     *
     * @return EvidenceItem[] The harvested items (possibly empty).
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function harvest(string $caseUuid, array $case): array;
}//end interface
