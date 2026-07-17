<?php

/**
 * OpenRegister Gdpr EvidenceItem
 *
 * Immutable value object a {@see EvidenceSourceProvider} returns for a single
 * harvested piece of evidence. It carries exactly the fields the head's declared
 * `evidence` sub-collection item shape expects — `sourceId`, `contentHash`, and
 * a per-item `status` — plus an optional free-form `payload` for downstream
 * dossier assembly.
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
 * A single harvested evidence item.
 */
final class EvidenceItem
{

    /**
     * Per-item status: the item was collected successfully.
     *
     * @var string
     */
    public const STATUS_COLLECTED = 'collected';

    /**
     * Per-item status: the item is known but not yet collected (async).
     *
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Per-item status: the source failed to return the item.
     *
     * @var string
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Constructor.
     *
     * @param string               $sourceId    Identifier of the source/provider the item came from.
     * @param string               $contentHash Content hash used for deduplication (e.g. `sha256:...`).
     * @param string               $status      Per-item collection status.
     * @param array<string, mixed> $payload     Optional item payload for dossier assembly.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function __construct(
        private readonly string $sourceId,
        private readonly string $contentHash,
        private readonly string $status=self::STATUS_COLLECTED,
        private readonly array $payload=[]
    ) {
    }//end __construct()

    /**
     * The source/provider id this item was harvested from.
     *
     * @return string
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function getSourceId(): string
    {
        return $this->sourceId;
    }//end getSourceId()

    /**
     * The content hash used to deduplicate the item across sources.
     *
     * @return string
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function getContentHash(): string
    {
        return $this->contentHash;
    }//end getContentHash()

    /**
     * The per-item collection status.
     *
     * @return string
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function getStatus(): string
    {
        return $this->status;
    }//end getStatus()

    /**
     * Optional item payload retained for dossier assembly.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function getPayload(): array
    {
        return $this->payload;
    }//end getPayload()

    /**
     * Serialise into the head's declared `evidence` sub-collection item shape.
     *
     * @return array{sourceId: string, contentHash: string, status: string}
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
     */
    public function toEvidenceRecord(): array
    {
        return [
            'sourceId'    => $this->sourceId,
            'contentHash' => $this->contentHash,
            'status'      => $this->status,
        ];
    }//end toEvidenceRecord()
}//end class
