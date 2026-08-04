<?php

/**
 * PlanApplier
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
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Anonymisation;

/**
 * Applies a plan by BUILDING the output, never by mutating a working copy.
 *
 * This is what makes it structurally impossible for an emitted placeholder to
 * be matched by a later needle. Sequential `str_ireplace` has the opposite
 * property, and longest-first ordering makes it worse: the shortest needles run
 * last, after every placeholder is already in place, so a needle whose text is
 * `1` can match inside `[PERSOON: 1]`. Re-anonymising an already-anonymised
 * document — an explicitly supported operation — is exactly where
 * placeholder-shaped text is present in the input.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/entity-replacement-planner/specs/entity-replacement-planner/spec.md
 */
class PlanApplier
{
    /**
     * Build the redacted text from the original plus its plan.
     *
     * @param string          $text The immutable original text.
     * @param ReplacementPlan $plan The plan computed against $text.
     *
     * @return string
     */
    public function applyToString(string $text, ReplacementPlan $plan): string
    {
        $ranges = $plan->getRanges();
        if (empty($ranges) === true) {
            return $text;
        }

        $chars  = mb_str_split($text);
        $total  = count($chars);
        $output = '';
        $cursor = 0;

        foreach ($ranges as $range) {
            if ($range->start < $cursor) {
                // Defensive: the planner guarantees non-overlapping ascending
                // ranges. Skipping rather than throwing keeps a malformed plan
                // from destroying a document, and the range is already covered.
                continue;
            }

            if ($range->start > $cursor) {
                $output .= implode('', array_slice($chars, $cursor, ($range->start - $cursor)));
            }

            $output .= $range->placeholder;
            $cursor  = min($range->end, $total);
        }

        if ($cursor < $total) {
            $output .= implode('', array_slice($chars, $cursor));
        }

        return $output;

    }//end applyToString()
}//end class
