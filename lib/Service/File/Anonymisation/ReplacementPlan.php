<?php

/**
 * ReplacementPlan
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
 * The outcome of planning: which ranges are replaced, and what the planner
 * could not cleanly account for.
 *
 * Two finding kinds, because they demand different operator responses:
 *
 * - `unmatched` — the needle matched nowhere. Its text MAY still be present in
 *   the output. The document is not safe to publish as-is.
 * - `partial` — the needle was split-matched: its text IS fully absent, but
 *   redaction took more than one range because entities overlapped.
 *
 * Both set the result incomplete. `complete === false` therefore does NOT mean
 * PII remains — it means a human should look. Callers MUST consult the kind.
 *
 * A needle that matched nowhere of its own accord but whose every occurrence
 * sits inside another entity's accepted range is **subsumed**, not unmatched:
 * its text is gone. That is the ordinary containment outcome (a PERSON inside
 * an EMAIL) and is deliberately not reported.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
final class ReplacementPlan
{

    /**
     * Finding kind: the needle matched nowhere; its text may remain.
     *
     * @var string
     */
    public const FINDING_UNMATCHED = 'unmatched';

    /**
     * Finding kind: split-matched; text absent but redaction needed >1 range.
     *
     * @var string
     */
    public const FINDING_PARTIAL = 'partial';

    /**
     * Build a plan.
     *
     * @param array<int, ReplacementRange> $ranges    Accepted ranges, ascending by start, non-overlapping.
     * @param array<int, string>           $unmatched Needles that matched nowhere.
     * @param array<int, string>           $partial   Needles that were split-matched.
     */
    public function __construct(
        private readonly array $ranges=[],
        private readonly array $unmatched=[],
        private readonly array $partial=[]
    ) {

    }//end __construct()

    /**
     * Accepted ranges, ascending by start offset and guaranteed non-overlapping.
     *
     * @return array<int, ReplacementRange>
     */
    public function getRanges(): array
    {
        return $this->ranges;

    }//end getRanges()

    /**
     * Needles that matched nowhere. Their text may still be in the output.
     *
     * @return array<int, string>
     */
    public function getUnmatchedNeedles(): array
    {
        return $this->unmatched;

    }//end getUnmatchedNeedles()

    /**
     * Needles that were split-matched. Their text is absent from the output.
     *
     * @return array<int, string>
     */
    public function getPartialNeedles(): array
    {
        return $this->partial;

    }//end getPartialNeedles()

    /**
     * Whether every needle matched cleanly as a single span.
     *
     * False means "a human should review", NOT "PII remains" — a plan whose
     * only findings are `partial` is fully redacted.
     *
     * @return boolean
     */
    public function isComplete(): bool
    {
        return (empty($this->unmatched) === true && empty($this->partial) === true);

    }//end isComplete()

    /**
     * Count of `unmatched` findings only.
     *
     * Deliberately excludes `partial`, so the existing `residual_count` field
     * keeps the meaning current consumers already rely on.
     *
     * @return integer
     */
    public function residualCount(): int
    {
        return count($this->unmatched);

    }//end residualCount()
}//end class
