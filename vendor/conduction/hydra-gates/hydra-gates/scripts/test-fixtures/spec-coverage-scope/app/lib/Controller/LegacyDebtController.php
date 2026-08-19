<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction
 * SPDX-License-Identifier: EUPL-1.2
 *
 * INHERITED @spec DEBT — the whole point of this file.
 *
 * Both methods below are public, live in lib/Controller, are not accessors and
 * carry NO `@spec` tag. They exist at the fixture's BASE commit, so a diff-scoped
 * run must never report them (ADR-020: inherited debt does not block a PR), while
 * a full-tree @spec sweep WOULD report them.
 *
 * That is exactly the distinction gate-16's `--full` verdict has to get right:
 * sweeping this file is the WRONG contract, and passing over it in silence is a
 * false green. NOT APPLICABLE with a stated reason is the only honest third answer.
 */

namespace OCA\SpecCoverageFixture\Controller;

class LegacyDebtController
{
    /**
     * Render the legacy report. No @spec — inherited debt.
     */
    public function renderLegacyReport(string $id): string
    {
        return 'legacy:' . $id;
    }

    /**
     * Recompute the legacy totals. No @spec — inherited debt.
     */
    public function recomputeLegacyTotals(array $rows): int
    {
        return count($rows);
    }
}
