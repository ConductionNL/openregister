<?php

/**
 * ReplacementPlanner
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
 * Decides WHICH ranges of a text get replaced, without ever mutating the text.
 *
 * Sequential `str_ireplace` over a longest-first map cannot express competition
 * between entities that partially overlap, and it makes every later decision
 * depend on text an earlier one already rewrote. This planner instead:
 *
 * 1. enumerates every occurrence of every needle against the IMMUTABLE original;
 * 2. selects the non-overlapping subset that redacts the most codepoints
 *    (weighted interval scheduling, O(n log n)), deterministically;
 * 3. covers the leftover of rejected overlapping candidates, so
 *    `Jan de Vries-Bakker` cannot leak `Bakker`;
 * 4. reports what it could not account for cleanly.
 *
 * Maximum coverage SUBSUMES longest-first: a container always covers strictly
 * more codepoints than a needle nested inside it, so `robert@rjzondervan.nl`
 * beats `rjzondervan` without any length rule existing. It additionally fixes a
 * case longest-first gets wrong — two short non-overlapping needles that
 * together cover more than one long needle overlapping both.
 *
 * Needles are cast to string on every read: PHP coerces purely-numeric array
 * keys to `int`, so a spaceless BSN arrives as an integer key.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
class ReplacementPlanner
{
    /**
     * Construct with a boundary-policy resolver.
     *
     * @param BoundaryPolicy|null $boundaryPolicy Injectable for tests; defaults to the real policy.
     */
    public function __construct(private ?BoundaryPolicy $boundaryPolicy=null)
    {
        if ($this->boundaryPolicy === null) {
            $this->boundaryPolicy = new BoundaryPolicy();
        }

    }//end __construct()

    /**
     * Produce a plan for one flat text.
     *
     * @param string                $text          The immutable original text.
     * @param array<string, string> $substitutions Map of needle => placeholder, consumed verbatim.
     * @param array<string, string> $entityTypes   Map of needle => entity type, for boundary policy.
     *
     * @return ReplacementPlan
     */
    public function plan(string $text, array $substitutions, array $entityTypes=[]): ReplacementPlan
    {
        if ($text === '' || empty($substitutions) === true) {
            return new ReplacementPlan(ranges: [], unmatched: $this->allNeedles(substitutions: $substitutions), partial: []);
        }

        $chars      = mb_str_split($text);
        $folded     = $this->foldWithMap(chars: $chars);
        $candidates = $this->enumerate(
            chars: $chars,
            foldedText: $folded['text'],
            foldedMap: $folded['map'],
            substitutions: $substitutions,
            entityTypes: $entityTypes
        );

        $accepted = $this->selectMaximumCoverage(candidates: $candidates);
        $result   = $this->coverResidue(chars: $chars, candidates: $candidates, accepted: $accepted);

        return $this->classify(
            chars: $chars,
            candidates: $candidates,
            ranges: $result,
            substitutions: $substitutions
        );

    }//end plan()

    /**
     * Every needle as a string, used when there is nothing to match against.
     *
     * @param array<string, string> $substitutions The substitution map.
     *
     * @return array<int, string>
     */
    private function allNeedles(array $substitutions): array
    {
        $needles = [];
        foreach (array_keys($substitutions) as $needle) {
            $needle = (string) $needle;
            if (trim($needle) !== '') {
                $needles[] = $needle;
            }
        }

        return $needles;

    }//end allNeedles()

    /**
     * Case-fold the text per codepoint, keeping a folded->original offset map.
     *
     * Folding per codepoint rather than folding the whole string is what keeps
     * the map correct when a fold changes length (some codepoints lowercase to
     * more than one). `mb_strtolower` on the whole string would silently shift
     * every offset after such a character.
     *
     * @param array<int, string> $chars The original text as a codepoint array.
     *
     * @return array{text: string, map: array<int, int>}
     */
    private function foldWithMap(array $chars): array
    {
        $foldedText = '';
        $map        = [];

        foreach ($chars as $index => $char) {
            $lower = mb_strtolower($char);
            $count = mb_strlen($lower);
            if ($count === 0) {
                // Defensive: a fold that yields nothing would desynchronise the
                // map, so keep the original codepoint instead.
                $lower = $char;
                $count = 1;
            }

            $foldedText .= $lower;
            for ($i = 0; $i < $count; $i++) {
                $map[] = $index;
            }
        }

        return [
            'text' => $foldedText,
            'map'  => $map,
        ];

    }//end foldWithMap()

    /**
     * Find every boundary-legal occurrence of every needle.
     *
     * @param array<int, string>    $chars         The original text as codepoints.
     * @param string                $foldedText    The case-folded haystack.
     * @param array<int, int>       $foldedMap     Folded offset => original offset.
     * @param array<string, string> $substitutions Map of needle => placeholder.
     * @param array<string, string> $entityTypes   Map of needle => entity type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function enumerate(
        array $chars,
        string $foldedText,
        array $foldedMap,
        array $substitutions,
        array $entityTypes
    ): array {
        $candidates  = [];
        $originalLen = count($chars);
        $foldedLen   = count($foldedMap);

        foreach ($substitutions as $rawNeedle => $placeholder) {
            $needle = (string) $rawNeedle;
            if (trim($needle) === '') {
                continue;
            }

            $type         = (string) ($entityTypes[$rawNeedle] ?? $entityTypes[$needle] ?? 'UNKNOWN');
            $foldedNeedle = mb_strtolower($needle);
            $needleLen    = mb_strlen($foldedNeedle);
            if ($needleLen === 0) {
                continue;
            }

            $offset = 0;
            while ($offset <= ($foldedLen - $needleLen)) {
                $position = mb_strpos($foldedText, $foldedNeedle, $offset);
                if ($position === false) {
                    break;
                }

                $start = $foldedMap[$position];
                $after = ($position + $needleLen);
                if ($after < $foldedLen) {
                    $end = $foldedMap[$after];
                } else {
                    $end = $originalLen;
                }

                $allowed = $this->boundaryPolicy->allows(
                    chars: $chars,
                    start: $start,
                    end: $end,
                    entityType: $type
                );

                if ($allowed === true && $end > $start) {
                    $candidates[] = [
                        'start'       => $start,
                        'end'         => $end,
                        'needle'      => $needle,
                        'placeholder' => (string) $placeholder,
                        'entityType'  => $type,
                    ];
                }

                $offset = ($position + 1);
            }//end while
        }//end foreach

        return $this->assignTotalOrder(candidates: $candidates);

    }//end enumerate()

    /**
     * Impose the deterministic total order and stamp each candidate's index.
     *
     * Order: start ascending, span descending, type rank ascending (structured
     * before free-text), needle bytewise ascending. The stamped index is what
     * breaks ties in the selection DP, so selection cannot depend on the order
     * entities were recognised or inserted into the map.
     *
     * @param array<int, array<string, mixed>> $candidates The raw candidates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignTotalOrder(array $candidates): array
    {
        $rank = function (string $entityType): int {
            $policy = $this->boundaryPolicy->forType(entityType: $entityType);
            if ($policy === BoundaryPolicy::POLICY_LITERAL) {
                return 0;
            }

            if ($policy === BoundaryPolicy::POLICY_DELIMITED_TOKEN) {
                return 1;
            }

            return 2;
        };

        usort(
            $candidates,
            static function (array $left, array $right) use ($rank): int {
                $leftKey  = [
                    $left['start'],
                    -($left['end'] - $left['start']),
                    $rank($left['entityType']),
                    $left['needle'],
                ];
                $rightKey = [
                    $right['start'],
                    -($right['end'] - $right['start']),
                    $rank($right['entityType']),
                    $right['needle'],
                ];

                return ($leftKey <=> $rightKey);
            }
        );

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['order'] = $index;
        }

        return $candidates;

    }//end assignTotalOrder()

    /**
     * Choose the non-overlapping subset covering the most codepoints.
     *
     * Weighted interval scheduling. The DP value is the pair
     * `[coverage, -sumOfOrderIndices]` compared lexicographically: maximise
     * redacted characters first, then prefer earlier-ordered candidates. Both
     * components are additive, so the comparison is well-defined and the result
     * is byte-identical across runs.
     *
     * @param array<int, array<string, mixed>> $candidates Ordered candidates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function selectMaximumCoverage(array $candidates): array
    {
        if (empty($candidates) === true) {
            return [];
        }

        $byEnd = $candidates;
        usort(
            $byEnd,
            static function (array $left, array $right): int {
                return ([$left['end'], $left['order']] <=> [$right['end'], $right['order']]);
            }
        );

        $count = count($byEnd);
        $ends  = array_column($byEnd, 'end');

        // The dp[$i] entry is the best value using the first $i of $byEnd.
        $dp     = [[0, 0]];
        $choice = array_fill(0, ($count + 1), false);

        for ($i = 1; $i <= $count; $i++) {
            $candidate = $byEnd[($i - 1)];
            $span      = ($candidate['end'] - $candidate['start']);
            $previous  = $this->lastEndingAtOrBefore(ends: $ends, limit: $candidate['start'], upTo: ($i - 1));

            $take = [
                ($dp[$previous][0] + $span),
                ($dp[$previous][1] - $candidate['order']),
            ];
            $skip = $dp[($i - 1)];

            if (($take <=> $skip) > 0) {
                $dp[$i]     = $take;
                $choice[$i] = true;
            } else {
                $dp[$i]     = $skip;
                $choice[$i] = false;
            }
        }//end for

        $accepted = [];
        $cursor   = $count;
        while ($cursor > 0) {
            if ($choice[$cursor] === true) {
                $candidate  = $byEnd[($cursor - 1)];
                $accepted[] = $candidate;
                $cursor     = $this->lastEndingAtOrBefore(
                    ends: $ends,
                    limit: $candidate['start'],
                    upTo: ($cursor - 1)
                );
                continue;
            }

            $cursor--;
        }//end while

        return array_reverse($accepted);

    }//end selectMaximumCoverage()

    /**
     * Index of the last candidate (1-based, within $upTo) whose end <= $limit.
     *
     * @param array<int, int> $ends  End offsets, ascending.
     * @param integer         $limit The start offset that must not be crossed.
     * @param integer         $upTo  Consider only the first $upTo candidates.
     *
     * @return integer Zero when none qualifies.
     */
    private function lastEndingAtOrBefore(array $ends, int $limit, int $upTo): int
    {
        $low    = 0;
        $high   = $upTo;
        $result = 0;

        while ($low < $high) {
            $middle = (int) floor((($low + $high + 1) / 2));
            if ($ends[($middle - 1)] <= $limit) {
                $result = $middle;
                $low    = $middle;
            } else {
                $high = ($middle - 1);
            }
        }

        return $result;

    }//end lastEndingAtOrBefore()

    /**
     * Turn accepted candidates into ranges, then cover the leftover of rejected
     * overlapping candidates.
     *
     * A rejected candidate may still have codepoints no accepted range covers —
     * `Vries-Bakker` losing to `Jan de Vries` leaves `-Bakker`. Leaving that in
     * place is a leak, so it is redacted and attributed to the rejected
     * candidate's entity. Residue that is only whitespace and/or a single
     * punctuation codepoint carries no information and is dropped.
     *
     * @param array<int, string>               $chars      The original text as codepoints.
     * @param array<int, array<string, mixed>> $candidates All candidates, in total order.
     * @param array<int, array<string, mixed>> $accepted   The accepted subset.
     *
     * @return array<int, ReplacementRange>
     */
    private function coverResidue(array $chars, array $candidates, array $accepted): array
    {
        $ranges  = [];
        $covered = [];

        foreach ($accepted as $candidate) {
            $ranges[]  = new ReplacementRange(
                start: $candidate['start'],
                end: $candidate['end'],
                needle: $candidate['needle'],
                placeholder: $candidate['placeholder'],
                entityType: $candidate['entityType'],
                isResidue: false
            );
            $covered[] = [$candidate['start'], $candidate['end']];
        }

        $acceptedKeys = [];
        foreach ($accepted as $candidate) {
            $acceptedKeys[$candidate['order']] = true;
        }

        foreach ($candidates as $candidate) {
            if (isset($acceptedKeys[$candidate['order']]) === true) {
                continue;
            }

            foreach ($this->gaps(start: $candidate['start'], end: $candidate['end'], covered: $covered) as $gap) {
                if ($this->carriesInformation(chars: $chars, start: $gap[0], end: $gap[1]) === false) {
                    continue;
                }

                $ranges[]  = new ReplacementRange(
                    start: $gap[0],
                    end: $gap[1],
                    needle: $candidate['needle'],
                    placeholder: $candidate['placeholder'],
                    entityType: $candidate['entityType'],
                    isResidue: true
                );
                $covered[] = $gap;
            }
        }//end foreach

        usort(
            $ranges,
            static function (ReplacementRange $left, ReplacementRange $right): int {
                return ($left->start <=> $right->start);
            }
        );

        return $ranges;

    }//end coverResidue()

    /**
     * Subranges of [$start, $end) not covered by any interval in $covered.
     *
     * @param integer                     $start   Inclusive start.
     * @param integer                     $end     Exclusive end.
     * @param array<int, array<int, int>> $covered Covered intervals, unordered.
     *
     * @return array<int, array<int, int>>
     */
    private function gaps(int $start, int $end, array $covered): array
    {
        $blocking = [];
        foreach ($covered as $interval) {
            if ($interval[0] < $end && $start < $interval[1]) {
                $blocking[] = $interval;
            }
        }

        usort(
            $blocking,
            static function (array $left, array $right): int {
                return ($left[0] <=> $right[0]);
            }
        );

        $gaps   = [];
        $cursor = $start;
        foreach ($blocking as $interval) {
            if ($interval[0] > $cursor) {
                $gaps[] = [$cursor, min($interval[0], $end)];
            }

            $cursor = max($cursor, $interval[1]);
            if ($cursor >= $end) {
                break;
            }
        }

        if ($cursor < $end) {
            $gaps[] = [$cursor, $end];
        }

        return $gaps;

    }//end gaps()

    /**
     * Whether a residue gap contains anything worth redacting.
     *
     * Any letter or decimal digit is always covered. A gap of only whitespace
     * and/or punctuation is dropped, because redacting it produces noise
     * without removing information — `[PERSOON: 1] [PERSOON: 2]` for the space
     * between two names would be worse than leaving the space.
     *
     * @param array<int, string> $chars The original text as codepoints.
     * @param integer            $start Inclusive gap start.
     * @param integer            $end   Exclusive gap end.
     *
     * @return boolean
     */
    private function carriesInformation(array $chars, int $start, int $end): bool
    {
        if (($end - $start) <= 0) {
            return false;
        }

        for ($index = $start; $index < $end; $index++) {
            if (preg_match('/^[\p{L}\p{Nd}]$/u', $chars[$index]) === 1) {
                return true;
            }
        }

        return false;

    }//end carriesInformation()

    /**
     * Classify each needle into clean / subsumed / partial / unmatched.
     *
     * `subsumed` is the ordinary containment outcome — a PERSON whose every
     * occurrence sits inside an accepted EMAIL range. Its text IS gone, so it is
     * deliberately NOT reported; calling it unmatched would tell the operator
     * PII remains when it does not.
     *
     * @param array<int, string>               $chars         The original text as codepoints.
     * @param array<int, array<string, mixed>> $candidates    All candidates.
     * @param array<int, ReplacementRange>     $ranges        The final accepted ranges.
     * @param array<string, string>            $substitutions The substitution map.
     *
     * @return ReplacementPlan
     */
    private function classify(array $chars, array $candidates, array $ranges, array $substitutions): ReplacementPlan
    {
        $occurrences = [];
        foreach ($candidates as $candidate) {
            $occurrences[$candidate['needle']][] = [$candidate['start'], $candidate['end']];
        }

        $direct  = [];
        $residue = [];
        $covered = [];
        foreach ($ranges as $range) {
            $covered[] = [$range->start, $range->end];
            if ($range->isResidue === true) {
                $residue[$range->needle] = true;
                continue;
            }

            $direct[$range->needle] = true;
        }

        $unmatched = [];
        $partial   = [];

        foreach (array_keys($substitutions) as $rawNeedle) {
            $needle = (string) $rawNeedle;
            if (trim($needle) === '') {
                continue;
            }

            if (isset($occurrences[$needle]) === false) {
                $unmatched[] = $needle;
                continue;
            }

            // A gap that was deliberately DROPPED as information-free (pure
            // whitespace/punctuation) must not count as "text remains" — the
            // drop rule and this check have to agree, or a needle whose only
            // leftover is a space is reported as unmatched and the operator is
            // told PII survived when it did not.
            $fullyCovered = true;
            foreach ($occurrences[$needle] as $occurrence) {
                $gaps = $this->gaps(start: $occurrence[0], end: $occurrence[1], covered: $covered);
                foreach ($gaps as $gap) {
                    if ($this->carriesInformation(chars: $chars, start: $gap[0], end: $gap[1]) === true) {
                        $fullyCovered = false;
                        break 2;
                    }
                }
            }

            if ($fullyCovered === false) {
                $unmatched[] = $needle;
                continue;
            }

            if (isset($residue[$needle]) === true) {
                $partial[] = $needle;
            }
        }//end foreach

        return new ReplacementPlan(ranges: $ranges, unmatched: $unmatched, partial: $partial);

    }//end classify()
}//end class
