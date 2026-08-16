#!/usr/bin/env php
<?php
/**
 * Coverage guard — prevents test coverage from dropping.
 *
 * Usage:
 *   php scripts/coverage-guard.php <clover.xml> [--against=<clover.xml>]
 *   php scripts/coverage-guard.php <clover.xml> [--update-baseline]
 *   php scripts/coverage-guard.php --capabilities
 *
 * TWO FLOORS, AND ONLY ONE OF THEM IS AUTHORITATIVE
 *
 * `--against` names a clover report MEASURED at the merge base. When it is
 * given it is the ONLY floor: the committed `.coverage-baseline` is reported
 * for information and deliberately not enforced.
 *
 * That precedence is the whole fix. `.coverage-baseline` is a number a human
 * types, and against a base branch that moves there is no value a pull-request
 * author can commit that is guaranteed correct when it lands. Measured on
 * openregister: an author committed 58.93, `development` advanced from 16030
 * to 16038 tests while the PR sat, the merge result measured 58.88, and the
 * guard reported "dropped by 0.05%". Taking max(committed, measured) would
 * preserve exactly that failure, so the measured value does not merely win
 * ties — it replaces the committed one outright.
 *
 * A measured floor also disposes of a second trap: CI measures with xdebug and
 * local runs typically use pcov, and the two do not count statements
 * identically. With `--against`, both numbers come from the same driver in the
 * same job, so the difference cancels instead of being baked into a constant.
 *
 * Without `--against` the committed `.coverage-baseline` is used as a
 * conservative fail-safe floor. It is a floor and never an exact target: a
 * measurement ABOVE it is good news and exits 0. Demanding equality is what
 * made this gate unsatisfiable, because closing "the file is stale" required
 * committing the value the tree would measure after landing.
 *
 * Exit codes:
 *   0 — coverage is equal to or higher than the floor
 *   1 — coverage dropped (the change should be blocked)
 *   2 — missing, unparseable or empty input
 */

const CG_OK      = 0;
const CG_DROPPED = 1;
const CG_INPUT   = 2;

/**
 * Capabilities this script understands.
 *
 * The workflow probes this before relying on `--against`. An older copy of this
 * script accepts the flag silently and ignores it, which would downgrade the
 * ratchet to the committed-constant check while still reporting success — a
 * check that did not run looking exactly like one that passed. The probe turns
 * that into a loud failure.
 */
const CG_CAPABILITIES = ['against', 'update-baseline', 'capabilities'];

/**
 * Parse `--key=value` / `--flag` into a map, and everything else in order.
 *
 * @param array<int,string> $argv
 *
 * @return array{0: array<string,string|bool>, 1: array<int,string>}
 */
function cgParseArgs(array $argv): array
{
    $options    = [];
    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (strncmp($arg, '--', 2) !== 0) {
            $positional[] = $arg;
            continue;
        }

        $body = substr($arg, 2);
        $eq   = strpos($body, '=');

        if ($eq === false) {
            $options[$body] = true;
            continue;
        }

        $options[substr($body, 0, $eq)] = substr($body, ($eq + 1));
    }

    return [$options, $positional];
}

/**
 * Read a clover report and return its statement counts and percentage.
 *
 * An empty or zero-statement report is a HARD ERROR, not 0% and not 100%.
 * Reading "no statements" as a number would let a run that produced nothing
 * satisfy the guard — and when the empty report is the merge-base side, it
 * would set the floor to 0 and pass every possible drop.
 *
 * @return array{0: int, 1: int, 2: float}
 */
function cgMeasure(string $file, string $label): array
{
    if (file_exists($file) === false) {
        fwrite(STDERR, "Error: {$label} clover file not found: {$file}\n");
        exit(CG_INPUT);
    }

    $xml = @simplexml_load_file($file);
    if ($xml === false || isset($xml->project->metrics) === false) {
        fwrite(STDERR, "Error: could not parse coverage metrics from {$label} report {$file}\n");
        exit(CG_INPUT);
    }

    $metrics    = $xml->project->metrics;
    $statements = (int) $metrics['statements'];
    $covered    = (int) $metrics['coveredstatements'];

    if ($statements <= 0) {
        fwrite(
            STDERR,
            "Error: {$label} report {$file} counts zero statements. Refusing to turn an empty "
            . "report into a percentage — this is a measurement failure, not a coverage result.\n"
        );
        exit(CG_INPUT);
    }

    return [$statements, $covered, round((($covered / $statements) * 100), 2)];
}

/**
 * Compare percentages as integer hundredths.
 *
 * Used only against the committed floor, which IS a two-decimal constant, so
 * two decimals is all the precision that comparison can carry.
 */
function cgHundredths(float $percentage): int
{
    return (int) round(($percentage * 100));
}

/**
 * Is the head ratio strictly below the merge-base ratio?
 *
 * Compared as exact integer cross-products, NOT as rounded percentages, and
 * that distinction is load-bearing. Rounding to two decimals hides roughly one
 * statement: measured on openbuild, dropping 8229/13987 to 8228/13987 leaves
 * both sides reading 58.83%, so the guard printed two different statement
 * counts next to the word "unchanged" and exited 0. One statement is a small
 * hole, but it is a hole that does not close by itself — a regression that
 * gives back a statement at a time is invisible for as many pull requests as it
 * cares to take.
 *
 * The cost is that genuine measurement jitter now reds a build instead of being
 * swallowed. That is the intended direction: the counts are printed on failure,
 * so a flake is immediately legible as a flake, whereas a swallowed drop is
 * legible as nothing at all.
 */
function cgRatioDropped(int $covered, int $statements, int $baseCovered, int $baseStatements): bool
{
    return (($covered * $baseStatements) < ($baseCovered * $statements));
}

/**
 * Print a percentage together with the counts it came from.
 *
 * The raw statement counts are printed on purpose. A bare percentage cannot
 * distinguish "tests were deleted" from "untested code was added", and the
 * second is the common way a ratchet is tripped by a change that added no
 * tests at all.
 */
function cgReport(string $label, int $statements, int $covered, float $percentage): void
{
    printf("%-22s %6.2f%%  (%d/%d statements)\n", $label, $percentage, $covered, $statements);
}

// ── main ────────────────────────────────────────────────────────────────────

[$options, $positional] = cgParseArgs($argv);

if (isset($options['capabilities']) === true) {
    echo implode("\n", CG_CAPABILITIES), "\n";
    exit(CG_OK);
}

$cloverFile   = ($positional[0] ?? 'coverage/clover.xml');
$baselineFile = (__DIR__ . '/../.coverage-baseline');
$against      = ($options['against'] ?? null);

[$statements, $covered, $current] = cgMeasure($cloverFile, 'current');
cgReport('Coverage current:', $statements, $covered, $current);

// ── the ratchet: a MEASURED floor ───────────────────────────────────────────
if (is_string($against) === true && $against !== '') {
    [$baseStatements, $baseCovered, $base] = cgMeasure($against, 'merge-base');
    cgReport('Coverage merge base:', $baseStatements, $baseCovered, $base);

    if (file_exists($baselineFile) === true) {
        $committed = trim(file_get_contents($baselineFile));
        echo "Committed .coverage-baseline: {$committed}% — recorded only, NOT enforced on this run.\n";
        echo "The merge base is measured, so it is the floor; see the header of this script.\n";
    }

    if (cgRatioDropped($covered, $statements, $baseCovered, $baseStatements) === true) {
        $delta = round(($base - $current), 2);
        echo($delta > 0 ? "FAIL: coverage dropped by {$delta}% against the merge base.\n"
            : "FAIL: coverage dropped against the merge base by less than 0.01% — too little to show in "
            . "the percentage, but a real loss in the counts below.\n");
        echo "      merge base {$baseCovered}/{$baseStatements} -> head {$covered}/{$statements} statements.\n";
        if ($statements > $baseStatements) {
            $added = ($statements - $baseStatements);
            echo "      This change adds {$added} statements. Adding code without tests drops coverage.\n";
        }

        exit(CG_DROPPED);
    }

    if (cgRatioDropped($baseCovered, $baseStatements, $covered, $statements) === true) {
        $gain = round(($current - $base), 2);
        echo "OK: coverage improved by {$gain}% against the merge base "
            . "({$baseCovered}/{$baseStatements} -> {$covered}/{$statements}).\n";
        exit(CG_OK);
    }

    echo "OK: coverage unchanged against the merge base.\n";
    exit(CG_OK);
}

// ── fail-safe: the committed floor ──────────────────────────────────────────
if (file_exists($baselineFile) === false) {
    fwrite(STDERR, "Error: baseline file not found: {$baselineFile}\n");
    exit(CG_INPUT);
}

$raw = trim(file_get_contents($baselineFile));
if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $raw) !== 1) {
    fwrite(STDERR, "Error: unparseable coverage baseline value '{$raw}' — refusing to guess.\n");
    exit(CG_INPUT);
}

$baseline = (float) $raw;
printf("%-22s %6.2f%%  (committed fail-safe floor)\n", 'Coverage baseline:', $baseline);

if (cgHundredths($current) < cgHundredths($baseline)) {
    $delta = round(($baseline - $current), 2);
    echo "FAIL: coverage dropped by {$delta}% below the committed floor.\n";
    exit(CG_DROPPED);
}

if (cgHundredths($current) > cgHundredths($baseline)) {
    $gain = round(($current - $baseline), 2);
    echo "OK: coverage is {$gain}% above the committed floor.\n";

    // Kept for callers that want the recomputed value written out. Being ABOVE
    // the committed floor is explicitly not a failure: the floor is conservative
    // by design and drifts low as coverage rises. The live ratchet is the
    // merge-base comparison above, which cannot go stale at all.
    if (isset($options['update-baseline']) === true) {
        file_put_contents($baselineFile, (number_format($current, 2) . "\n"));
        echo "Recomputed floor written: {$current}%\n";
    }

    exit(CG_OK);
}

echo "OK: coverage unchanged.\n";
exit(CG_OK);
