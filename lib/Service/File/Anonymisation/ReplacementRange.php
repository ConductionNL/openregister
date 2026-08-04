<?php

/**
 * ReplacementRange
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Anonymisation;

/**
 * One accepted replacement: a half-open codepoint range on the ORIGINAL text
 * plus the placeholder that replaces it.
 *
 * Offsets are codepoint offsets, not byte offsets, and they always address the
 * immutable original text the plan was computed against — never a partially
 * rewritten copy. That is what makes single-pass application possible and what
 * makes it impossible for an emitted placeholder to be rescanned.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class ReplacementRange
{
    /**
     * Build an accepted range.
     *
     * @param integer $start       Inclusive codepoint offset in the original text.
     * @param integer $end         Exclusive codepoint offset in the original text.
     * @param string  $needle      The entity text this range was matched from.
     * @param string  $placeholder The placeholder to emit, verbatim from the substitution map.
     * @param string  $entityType  The entity type, used only for boundary policy and reporting.
     * @param boolean $isResidue   True when this range covers the leftover of a REJECTED
     *                             overlapping candidate rather than a direct match.
     *                             Residue coverage is what stops `Jan de Vries-Bakker`
     *                             leaking `Bakker`.
     */
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly string $needle,
        public readonly string $placeholder,
        public readonly string $entityType='UNKNOWN',
        public readonly bool $isResidue=false
    ) {

    }//end __construct()

    /**
     * Number of codepoints this range covers.
     *
     * @return integer
     */
    public function span(): int
    {
        return ($this->end - $this->start);

    }//end span()

    /**
     * Whether this range shares at least one codepoint with another.
     *
     * Half-open comparison: ranges that merely touch (one ends where the next
     * begins) do NOT overlap, so adjacent entities are both replaceable.
     *
     * @param ReplacementRange $other The range to test against.
     *
     * @return boolean
     */
    public function overlaps(ReplacementRange $other): bool
    {
        return ($this->start < $other->end && $other->start < $this->end);

    }//end overlaps()
}//end class
