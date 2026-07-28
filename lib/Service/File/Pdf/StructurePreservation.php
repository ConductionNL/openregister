<?php

/**
 * StructurePreservation
 *
 * Immutable result block reporting whether a PDF redaction preserved the
 * input's logical structure tree (tags, reading order, alt text) — the
 * truthful contract the DocuDesk accessible-redaction leaf consumes verbatim.
 *
 * Per ADR-005 it carries ONLY structural counts and machine-readable reason
 * codes — never document content or entity text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Pdf;

use JsonSerializable;

/**
 * Immutable value object capturing the `structurePreservation` result contract.
 *
 * Field names are contractual (design.md D2) — the DocuDesk accessible-
 * redaction leaf consumes them verbatim; they MUST NOT drift.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-003
 */
final class StructurePreservation implements JsonSerializable
{

    /**
     * The pure-PHP stack cannot re-author or repair a structure tree at all
     * (design.md D1) — a general-purpose fallback reason for structural loss
     * not covered by a more specific code below.
     */
    public const LOSS_REASON_ENGINE_CANNOT_REAUTHOR = 'engine-cannot-reauthor-structtree';

    /**
     * The redaction mutated marked content on a tagged page, so the
     * tag→content (`/MCID`) correspondence can no longer be guaranteed.
     */
    public const LOSS_REASON_MARKED_CONTENT_BROKEN = 'marked-content-correspondence-broken';

    /**
     * The `/StructTreeRoot` (or its element count) did not survive the SAPP
     * rebuild.
     */
    public const LOSS_REASON_STRUCTTREEROOT_DROPPED = 'structtreeroot-dropped-on-rebuild';

    /**
     * Preservation was requested (auto or explicit) but the input was not a
     * tagged PDF — not applicable, not a failure.
     */
    public const LOSS_REASON_INPUT_NOT_TAGGED = 'input-not-tagged';

    /**
     * A specific page's structure could not be carried through (reserved for
     * future per-page granularity — design.md Open Questions).
     */
    public const LOSS_REASON_PAGE_NOT_PRESERVABLE = 'page-structure-not-preservable';

    /**
     * The documented, extensible enumerated set of machine-readable loss reasons.
     *
     * @var string[]
     */
    public const LOSS_REASONS = [
        self::LOSS_REASON_ENGINE_CANNOT_REAUTHOR,
        self::LOSS_REASON_MARKED_CONTENT_BROKEN,
        self::LOSS_REASON_STRUCTTREEROOT_DROPPED,
        self::LOSS_REASON_INPUT_NOT_TAGGED,
        self::LOSS_REASON_PAGE_NOT_PRESERVABLE,
    ];

    /**
     * Constructor.
     *
     * @param bool     $requested      Whether preservation was in effect for this run.
     * @param bool     $preserved      Whether the engine attests the tag tree survived faithfully.
     * @param int      $tagCountBefore `/StructElem` count of the input (0 for untagged).
     * @param int      $tagCountAfter  `/StructElem` count of the produced output.
     * @param string[] $lossReasons    Machine-readable reasons, empty when $preserved is true.
     *
     * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-003
     */
    public function __construct(
        public readonly bool $requested,
        public readonly bool $preserved,
        public readonly int $tagCountBefore,
        public readonly int $tagCountAfter,
        public readonly array $lossReasons=[]
    ) {
    }//end __construct()

    /**
     * Serialise to the exact five contracted keys (design.md D2).
     *
     * @return array{requested: bool, preserved: bool, tagCountBefore: int, tagCountAfter: int, lossReasons: string[]}
     *
     * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-003
     */
    public function jsonSerialize(): array
    {
        return [
            'requested'      => $this->requested,
            'preserved'      => $this->preserved,
            'tagCountBefore' => $this->tagCountBefore,
            'tagCountAfter'  => $this->tagCountAfter,
            'lossReasons'    => $this->lossReasons,
        ];
    }//end jsonSerialize()
}//end class
