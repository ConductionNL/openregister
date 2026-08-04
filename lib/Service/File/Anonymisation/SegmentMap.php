<?php

/**
 * SegmentMap
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
 * Flatten a segmented document into one string, plan against it, then scatter
 * the result back into the segments.
 *
 * PDF, docx and ODF do not hold text as one string — it is split across content
 * streams, `<w:r>` runs and ODF segments — and an entity routinely straddles a
 * split. Word produces such splits constantly at formatting, spell-check and
 * `rsid` boundaries, which is why per-segment `str_ireplace` cannot reach
 * `Jan|ssen` and leaves it in the output verbatim.
 *
 * Scatter rules:
 * - the placeholder goes ENTIRELY into the segment holding the range's start,
 *   so the formatting of the first run is what survives;
 * - every subsequent segment the range overlaps has its covered portion
 *   removed;
 * - a segment left empty is retained as `''`, never removed — structural
 *   rewriting belongs to `office-document-sanitization`, not here.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
class SegmentMap
{

    /**
     * Segment values in DOCUMENT order.
     *
     * Document order matters: both the selection tie-breaking and the residue
     * attribution are defined on offsets in the concatenation.
     *
     * @var array<int, string>
     */
    private array $segments;

    /**
     * Codepoint offset of each segment's first character in the concatenation.
     *
     * @var array<int, int>
     */
    private array $offsets = [];

    /**
     * Codepoint length of each segment.
     *
     * @var array<int, int>
     */
    private array $lengths = [];

    /**
     * Build a map over segment values in document order.
     *
     * @param array<int, string> $segments Segment text values, in document order.
     */
    public function __construct(array $segments)
    {
        $this->segments = array_values($segments);

        $cursor = 0;
        foreach ($this->segments as $index => $value) {
            $length = mb_strlen($value);
            $this->offsets[$index] = $cursor;
            $this->lengths[$index] = $length;
            $cursor += $length;
        }

    }//end __construct()

    /**
     * The concatenation the planner runs against.
     *
     * @return string
     */
    public function flatten(): string
    {
        return implode('', $this->segments);

    }//end flatten()

    /**
     * Apply a plan and return the new segment values.
     *
     * @param ReplacementPlan $plan The plan computed against flatten().
     *
     * @return array<int, string>
     */
    public function scatter(ReplacementPlan $plan): array
    {
        $ranges = $plan->getRanges();
        if (empty($ranges) === true) {
            return $this->segments;
        }

        // Collect per-segment edits first, then rewrite each segment once, so
        // offsets never shift underneath a later range.
        $edits = [];
        foreach ($ranges as $range) {
            $placed = false;
            foreach ($this->segments as $index => $value) {
                $segmentStart = $this->offsets[$index];
                $segmentEnd   = ($segmentStart + $this->lengths[$index]);

                if ($this->lengths[$index] === 0) {
                    continue;
                }

                if ($range->start >= $segmentEnd || $range->end <= $segmentStart) {
                    continue;
                }

                $localStart = (max($range->start, $segmentStart) - $segmentStart);
                $localEnd   = (min($range->end, $segmentEnd) - $segmentStart);

                $edits[$index][] = [
                    'start'       => $localStart,
                    'end'         => $localEnd,
                    'placeholder' => ($placed === false) ? $range->placeholder : '',
                ];

                $placed = true;
            }//end foreach
        }//end foreach

        $result = $this->segments;
        foreach ($edits as $index => $segmentEdits) {
            $result[$index] = $this->rewriteSegment(
                value: $this->segments[$index],
                edits: $segmentEdits
            );
        }

        return $result;

    }//end scatter()

    /**
     * Rewrite one segment by building it from its untouched slices plus the
     * placeholders that land in it.
     *
     * @param string                           $value The original segment text.
     * @param array<int, array<string, mixed>> $edits Local edits for this segment.
     *
     * @return string
     */
    private function rewriteSegment(string $value, array $edits): string
    {
        usort(
            $edits,
            static function (array $left, array $right): int {
                return ($left['start'] <=> $right['start']);
            }
        );

        $chars  = mb_str_split($value);
        $total  = count($chars);
        $output = '';
        $cursor = 0;

        foreach ($edits as $edit) {
            if ($edit['start'] < $cursor) {
                continue;
            }

            if ($edit['start'] > $cursor) {
                $output .= implode('', array_slice($chars, $cursor, ($edit['start'] - $cursor)));
            }

            $output .= $edit['placeholder'];
            $cursor  = min($edit['end'], $total);
        }

        if ($cursor < $total) {
            $output .= implode('', array_slice($chars, $cursor));
        }

        return $output;

    }//end rewriteSegment()
}//end class
