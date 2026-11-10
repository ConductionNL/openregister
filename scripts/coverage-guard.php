#!/usr/bin/env php
<?php
/**
 * Coverage guard — prevents test coverage from dropping.
 *
 * Usage:
 *   php scripts/coverage-guard.php <clover.xml> [--against=<clover.xml>]
 *   php scripts/coverage-guard.php <clover.xml> --against=<clover.xml> --changed-files=<list>
 *   php scripts/coverage-guard.php <clover.xml> --against=<clover.xml> --changed-files=<list> --deletion-neutral
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
const CG_CAPABILITIES = ['against', 'update-baseline', 'capabilities', 'changed-files', 'deletion-neutral'];

/**
 * Bucket key used for statements that could not be attributed to a method.
 *
 * It is a real key, present on both sides, so a file that falls back is compared
 * exactly as it is today — conservatively, deletions included. It is never a way
 * for a file to disappear from the comparison.
 */
const CG_WHOLE_FILE = '<whole file>';

/**
 * Sum clover metrics for a named subset of files.
 *
 * WHY THIS EXISTS. Comparing the project-wide aggregate made the ratchet fire on
 * measurement noise. Measured on doriath#240 — a pull request whose entire diff
 * was `webpack.config.js`, containing no PHP at all:
 *
 *     Coverage current:       60.50%  (8303/13723 statements)
 *     Coverage merge base:    60.55%  (8309/13723 statements)
 *     FAIL: coverage dropped by 0.05% against the merge base.
 *
 * The denominator is identical, so both runs compiled the same source, and both
 * reported exactly `Tests: 948, Assertions: 3051, Skipped: 1`. Only which six
 * statements xdebug recorded as covered moved. The header above argues that a
 * measured `--against` floor cancels driver variance, and it does — but it does
 * not cancel RUN-TO-RUN variance, and the ratchet has no tolerance.
 *
 * A tolerance band was the obvious alternative and is the wrong one: it blinds
 * the gate to small real regressions permanently, at a threshold nobody can
 * justify. Scoping to the files the change actually touched keeps full strength
 * where it matters and makes the noise unreachable — a diff with no PHP cannot
 * fail, by construction, rather than by being forgiven.
 *
 * Matching is by path SUFFIX. Clover records absolute paths from the machine
 * that produced it, and the two reports here are produced from two different
 * checkouts, so absolute equality would match nothing and silently measure zero.
 *
 * @param string             $file  Clover report to read.
 * @param string             $label Human label for error messages.
 * @param array<int,string>  $only  Repo-relative paths to include.
 *
 * @return array{0:int,1:int,2:float} statements, covered, percentage
 */
function cgMeasureFiles(string $file, string $label, array $only): array
{
    if (file_exists($file) === false) {
        fwrite(STDERR, "Error: {$label} clover file not found: {$file}\n");
        exit(CG_INPUT);
    }

    $xml = @simplexml_load_file($file);
    if ($xml === false) {
        fwrite(STDERR, "Error: could not parse {$label} report {$file}\n");
        exit(CG_INPUT);
    }

    $statements = 0;
    $covered    = 0;
    $matched    = 0;

    foreach ($xml->xpath('//file') as $entry) {
        $name = (string) $entry['name'];
        if ($name === '') {
            continue;
        }

        foreach ($only as $wanted) {
            if (str_ends_with($name, $wanted) === false) {
                continue;
            }

            $matched++;
            $statements += (int) $entry->metrics['statements'];
            $covered    += (int) $entry->metrics['coveredstatements'];
            break;
        }
    }

    // Zero matches is NOT zero coverage. It means the changed files are absent
    // from this report — typically because they are new on the head side and do
    // not exist at the merge base, which is normal and must not read as a drop.
    if ($matched === 0) {
        return [0, 0, 0.0];
    }

    $percentage = 0.0;
    if ($statements > 0) {
        $percentage = round((($covered / $statements) * 100), 2);
    }

    return [$statements, $covered, $percentage];
}

/**
 * Attribute every statement in the named files to the METHOD it sits in.
 *
 * WHY BY METHOD NAME, AND WHY NOT BY LINE NUMBER.
 *
 * Clover identifies a statement only by its `num` — the line it sits on — and a
 * deletion shifts every line after the cut. On launchpad#128's file, 117 of the
 * 254 surviving statements (46%) sit at or after the deletion site, so matching
 * base statement `num=310` to head statement `num=310` compares two unrelated
 * lines across half the file and returns a confident, meaningless verdict. The
 * test suite measures that directly on its fixture: a line-number intersection
 * there reports 182/234 head against 189/234 base, i.e. a fabricated regression,
 * where method-name attribution reconciles exactly at 183/254 on both sides.
 * Method names survive the shift; line numbers do not.
 *
 * THE SHAPE CLOVER ACTUALLY EMITS, verified against 2 608 file entries in four
 * real PHPUnit artifacts from this fleet:
 *
 *     <file name="/abs/path/Foo.php">
 *       <class name="Foo"><metrics .../></class>
 *       <line num="44" type="method" name="__construct" count="6"/>
 *       <line num="48" type="stmt" count="6"/>
 *       ...
 *       <metrics statements="9" coveredstatements="9" methods="7" .../>
 *     </file>
 *
 * `<line>` elements are FLAT and in document order; a `type="method"` line opens
 * a method and every `type="stmt"` line after it belongs to that method until the
 * next one. `metrics/@statements` counts the `stmt` lines only — methods are
 * counted separately and `elements` is their sum — so summing attributed
 * statements reproduces the file metric wherever the line data is complete.
 *
 * The bucket key is the REPO-RELATIVE path that matched, never the clover
 * `name`. The two reports are produced from two different checkouts and their
 * absolute paths differ, so keying on `name` would put every head bucket and
 * every base bucket in disjoint namespaces and the intersection would be empty —
 * which reads as "nothing to compare", i.e. a pass.
 *
 * TWO DEGENERATE SHAPES, BOTH HANDLED FAIL-CLOSED:
 *
 *  - A file whose metrics declare statements but which carries NO usable line
 *    data (the `processUncoveredFiles` shape: PHPUnit counts a file it never
 *    loaded). Attribution would measure it as 0/0 — an invisible pass on exactly
 *    the file most likely to be untested. Such a file falls back to a single
 *    CG_WHOLE_FILE bucket carrying its file-level metrics, the fallback is
 *    printed by name, and cgCollapseFiles() then puts BOTH sides on the same
 *    footing so the fallback cannot be mistaken for a deletion.
 *  - Two methods of the same name in one file (two classes in one file, in
 *    principle). Their statements are SUMMED into one bucket rather than
 *    disambiguated by ordinal, because ordinals shift when one of them is
 *    deleted. Summing keeps the bucket in the intersection, so deleting one of a
 *    same-named pair is still charged against the change. Not seen in any of the
 *    2 608 entries surveyed; handled so it cannot become a silent hole.
 *
 * @param string            $file  Clover report to read.
 * @param string            $label Human label for error messages.
 * @param array<int,string> $only  Repo-relative paths to include.
 *
 * @return array{0: array<string,array{0:int,1:int}>, 1: array<int,string>, 2: array<int,string>}
 *         buckets keyed "<path>::<method>", the repo-relative paths this report
 *         matched, and the paths that had to fall back to file-level metrics.
 */
function cgAttributeMethods(string $file, string $label, array $only): array
{
    if (file_exists($file) === false) {
        fwrite(STDERR, "Error: {$label} clover file not found: {$file}\n");
        exit(CG_INPUT);
    }

    $xml = @simplexml_load_file($file);
    if ($xml === false) {
        fwrite(STDERR, "Error: could not parse {$label} report {$file}\n");
        exit(CG_INPUT);
    }

    $buckets  = [];
    $matched  = [];
    $fellBack = [];

    foreach ($xml->xpath('//file') as $entry) {
        $name = (string) $entry['name'];
        if ($name === '') {
            continue;
        }

        $path = null;
        foreach ($only as $wanted) {
            if (str_ends_with($name, $wanted) === true) {
                $path = $wanted;
                break;
            }
        }

        if ($path === null) {
            continue;
        }

        $matched[] = $path;

        $method     = CG_WHOLE_FILE;
        $statements = 0;
        $covered    = 0;
        $local      = [];

        foreach ($entry->line as $line) {
            $type = (string) $line['type'];

            if ($type === 'method') {
                $named  = (string) $line['name'];
                $method = ($named === '' ? CG_WHOLE_FILE : $named);
                continue;
            }

            if ($type !== 'stmt') {
                continue;
            }

            $key = ($path . '::' . $method);
            if (isset($local[$key]) === false) {
                $local[$key] = [0, 0];
            }

            $local[$key][0]++;
            $statements++;

            if (((int) $line['count']) > 0) {
                $local[$key][1]++;
                $covered++;
            }
        }

        $declared = (int) $entry->metrics['statements'];

        if ($statements === 0 && $declared > 0) {
            // No usable line data. Measuring 0/0 here would drop the file out of
            // the comparison entirely, so fall back to the file metric and say so.
            $fellBack[] = $path;
            $local      = [
                ($path . '::' . CG_WHOLE_FILE) => [$declared, (int) $entry->metrics['coveredstatements']],
            ];
            echo "    note: {$label} report carries no line data for {$path}; "
                . "falling back to its file-level metric ({$entry->metrics['coveredstatements']}/{$declared}).\n";
        } else if ($statements !== $declared) {
            echo "    note: {$label} report declares {$declared} statement(s) for {$path} but emits "
                . "{$statements} attributable line(s); the attributable ones are what is compared.\n";
        }

        foreach ($local as $key => $pair) {
            if (isset($buckets[$key]) === false) {
                $buckets[$key] = [0, 0];
            }

            $buckets[$key][0] += $pair[0];
            $buckets[$key][1] += $pair[1];
        }
    }//end foreach

    return [$buckets, array_values(array_unique($matched)), array_values(array_unique($fellBack))];
}//end cgAttributeMethods()

/**
 * Collapse every bucket belonging to the named files into one per file.
 *
 * THIS IS THE HOLE THAT WRITING THE TESTS FOUND, and it is worth naming because
 * it is the invisible-pass shape in its purest form. If one side of the
 * comparison cannot be attributed to methods it falls back to a single
 * CG_WHOLE_FILE bucket — but that bucket's key then exists on ONE side only, so
 * the asymmetric rule classifies the entire base-side file as "deleted", drops
 * it, finds nothing left to compare, and reports:
 *
 *     OK: none of the changed PHP existed at the merge base
 *
 * A file the guard could not read would have passed every possible drop. So when
 * EITHER side falls back for a file, BOTH sides are collapsed to that file's
 * single bucket: the key then exists on both sides, the file is compared exactly
 * as the file-scoped mode compares it today, and the deletion penalty applies to
 * it. Conservative, and visible in the output.
 *
 * @param array<string,array{0:int,1:int}> $buckets
 * @param array<int,string>                $paths
 *
 * @return array<string,array{0:int,1:int}>
 */
function cgCollapseFiles(array $buckets, array $paths): array
{
    if (empty($paths) === true) {
        return $buckets;
    }

    $collapse = array_fill_keys($paths, true);
    $out      = [];

    foreach ($buckets as $key => $pair) {
        $split = strrpos($key, '::');
        $path  = ($split === false ? $key : substr($key, 0, $split));

        if (isset($collapse[$path]) === true) {
            $key = ($path . '::' . CG_WHOLE_FILE);
        }

        if (isset($out[$key]) === false) {
            $out[$key] = [0, 0];
        }

        $out[$key][0] += $pair[0];
        $out[$key][1] += $pair[1];
    }

    return $out;
}//end cgCollapseFiles()

/**
 * Sum a bucket map, optionally restricted to a set of keys.
 *
 * @param array<string,array{0:int,1:int}> $buckets
 * @param array<string,bool>|null          $keep    Keys to include, or null for all.
 *
 * @return array{0:int,1:int} statements, covered
 */
function cgSumBuckets(array $buckets, ?array $keep = null): array
{
    $statements = 0;
    $covered    = 0;

    foreach ($buckets as $key => $pair) {
        if ($keep !== null && isset($keep[$key]) === false) {
            continue;
        }

        $statements += $pair[0];
        $covered    += $pair[1];
    }

    return [$statements, $covered];
}//end cgSumBuckets()

/**
 * Read the changed-file list, keeping only PHP files the guard can measure.
 *
 * @param string $path File containing one repo-relative path per line.
 *
 * @return array<int,string>
 */
function cgReadChangedFiles(string $path): array
{
    if (file_exists($path) === false) {
        fwrite(STDERR, "Error: changed-files list not found: {$path}\n");
        exit(CG_INPUT);
    }

    $lines = file($path, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    if ($lines === false) {
        fwrite(STDERR, "Error: could not read changed-files list: {$path}\n");
        exit(CG_INPUT);
    }

    $php = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && str_ends_with($line, '.php') === true) {
            $php[] = $line;
        }
    }

    return array_values(array_unique($php));
}

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
$changedList  = ($options['changed-files'] ?? null);
$deletionFree = isset($options['deletion-neutral']);

// A flag that is accepted and ignored is the silent-downgrade shape this script's
// `--capabilities` probe exists to prevent, so refuse rather than fall through to
// a comparison the caller did not ask for.
if ($deletionFree === true && (is_string($changedList) === false || $changedList === '')) {
    fwrite(STDERR, "Error: --deletion-neutral requires --changed-files (and therefore --against). It refines the file-scoped comparison; it has no meaning against the whole project.\n");
    exit(CG_INPUT);
}

// ── scoped mode: compare ONLY the PHP the change touched ────────────────────
//
// Requires --against. Without a merge-base report there is nothing to compare a
// file subset to, and falling back to the committed whole-project constant here
// would compare a subset against a whole and fail everything.
if (is_string($changedList) === true && $changedList !== '') {
    if (is_string($against) === false || $against === '') {
        fwrite(STDERR, "Error: --changed-files requires --against; a file subset has no meaning against the committed whole-project baseline.\n");
        exit(CG_INPUT);
    }

    $changed = cgReadChangedFiles($changedList);

    if (empty($changed) === true) {
        echo "OK: this change touches no PHP files, so there is no coverage to compare.\n";
        echo "    (Whole-project drift between two runs of identical code is measurement noise, not a regression.)\n";
        exit(CG_OK);
    }

    echo 'Scoped to ' . count($changed) . " changed PHP file(s).\n";

    // ── deletion-neutral mode ───────────────────────────────────────────────
    //
    // WHAT THIS FIXES. `cgRatioDropped()` is a single integer cross-product with
    // no tolerance, so the file-scoped ratchet demands, exactly:
    //
    //     the code you touched must be at least as well covered as the code you
    //     did not.
    //
    // For ADDITIONS that is right, and it earns its keep: openconnector#1265
    // covered 27 of 54 new statements against a 61.86% floor, needed 34, and
    // writing the missing seven is what exposed a cascade that deleted nothing
    // and reported success.
    //
    // For DELETIONS it INVERTS. Removing `d` statements of which `c` were covered
    // lowers the ratio whenever c/d exceeds the ratio of what remains — i.e.
    // whenever the deleted code was better tested than average. And dead code is
    // dead because nothing CALLS it, not because nothing TESTED it: gate-57
    // (orphaned-write-capability) exists to find precisely that code, so gate-57
    // and this ratchet are in arithmetic opposition, not tension. Such a pull
    // request cannot satisfy the ratchet from inside its own subject at all —
    // the only moves are delete less, add filler, or delete additional
    // *uncovered* statements until it balances. The gate can be satisfied by
    // deleting more code and cannot be satisfied by testing anything.
    //
    // Measured, both blocked by the file-scoped rule:
    //   opencatalogi#895  head 112/115 (97.39%)  base 148/151 (98.01%)  -0.62%
    //   launchpad#128     head 183/254 (72.05%)  base 220/304 (72.37%)  -0.32%
    //
    // THE RULE, AND IT IS ASYMMETRIC ON PURPOSE:
    //
    //     drop BASE-ONLY methods (deletions) from the base side;
    //     KEEP HEAD-ONLY methods (additions) on the head side.
    //
    // The symmetric version — "compare over statements present in both reports" —
    // is the obvious one and it is broken. It also drops head-only statements, so
    // a change adding 40 new statements with 0 of them covered compares an empty
    // set to an empty set and PASSES. That is the openconnector#1265 shape, and
    // the symmetric rule would have retired the half of this ratchet that works.
    // A mutant reintroducing it is in the test suite and must stay there.
    //
    // Consequences, all four measured in quality-config/tests/test-coverage-guard.py:
    //   pure deletion (opencatalogi) PASS  112/115 vs 112/115 — exactly neutral
    //   pure deletion (launchpad)    PASS  183/254 vs 183/254
    //   regression in survivors      FAIL  180/254 vs 183/254
    //   new untested code            FAIL  183/294 (62.24%) vs 183/254 (72.05%)
    //
    // KNOWN RESIDUAL, stated rather than discovered: a RENAME reads as a delete
    // plus an add. The old name leaves the base side, the new name arrives on the
    // head side and must be covered. That is the right incentive — a renamed
    // method is new code as far as the test suite is concerned — but it means a
    // pure rename of a well-covered method is not free, and an author who did not
    // expect it will read the failure as noise. It is documented here and in the
    // failure message so it is legible when it happens.
    if ($deletionFree === true) {
        [$headBuckets, $headFiles, $headFellBack] = cgAttributeMethods($cloverFile, 'current', $changed);
        [$baseBuckets, $baseFiles, $baseFellBack] = cgAttributeMethods($against, 'merge-base', $changed);

        // Either side falling back forces BOTH sides to file level for that file —
        // see cgCollapseFiles() for why anything else is an invisible pass.
        $collapse = array_values(array_unique(array_merge($headFellBack, $baseFellBack)));
        if (empty($collapse) === false) {
            echo 'Falling back to file-level metrics on both sides for: ' . implode(', ', $collapse) . "\n";
            $headBuckets = cgCollapseFiles($headBuckets, $collapse);
            $baseBuckets = cgCollapseFiles($baseBuckets, $collapse);
        }

        echo 'Deletion-neutral: attributed by method name (head ' . count($headFiles) . ' file(s), '
            . 'base ' . count($baseFiles) . ' file(s), ' . count($headBuckets) . ' head method(s), '
            . count($baseBuckets) . " base method(s)).\n";

        // A changed file the base measured and the head did not is normally a
        // DELETED file, and treating it as deleted is the point of this mode. But
        // it is also what an accidental coverage exclusion looks like, and the two
        // are indistinguishable from here — so it is said out loud rather than
        // absorbed silently.
        $goneFiles = array_values(array_diff($baseFiles, $headFiles));
        if (empty($goneFiles) === false) {
            echo 'Measured at the merge base and absent from the head report: '
                . implode(', ', $goneFiles) . "\n";
            echo "    Treated as deleted. If one of those files still exists, it has been dropped from\n";
            echo "    coverage measurement and that is the thing to fix, not this guard.\n";
        }

        if (empty($headBuckets) === true && empty($baseBuckets) === true) {
            echo "OK: none of the changed PHP files appear in either coverage report.\n";
            echo "    Nothing was measured, so nothing is claimed about them.\n";
            exit(CG_OK);
        }

        $keep = [];
        foreach ($headBuckets as $key => $unused) {
            $keep[$key] = true;
        }

        $dropped = [];
        foreach ($baseBuckets as $key => $pair) {
            if (isset($keep[$key]) === false) {
                $dropped[$key] = $pair;
            }
        }

        [$statements, $covered]         = cgSumBuckets($headBuckets);
        [$baseStatements, $baseCovered] = cgSumBuckets($baseBuckets, $keep);

        $current = ($statements > 0 ? round((($covered / $statements) * 100), 2) : 0.0);
        $base    = ($baseStatements > 0 ? round((($baseCovered / $baseStatements) * 100), 2) : 0.0);

        if (empty($dropped) === false) {
            [$droppedStatements, $droppedCovered] = cgSumBuckets($dropped);
            echo 'Removed from the base side: ' . count($dropped)
                . " method(s) absent from head, {$droppedCovered}/{$droppedStatements} statements.\n";
            echo "    A method that no longer exists is not a coverage regression; it is deleted code.\n";
        }

        cgReport('Surviving code, head:', $statements, $covered, $current);
        cgReport('Surviving code, base:', $baseStatements, $baseCovered, $base);

        if ($baseStatements === 0) {
            echo "OK: none of the changed PHP existed at the merge base, so there is no prior figure to drop below.\n";
            exit(CG_OK);
        }

        if (cgRatioDropped($covered, $statements, $baseCovered, $baseStatements) === true) {
            $delta = round(($base - $current), 2);
            echo($delta > 0
                ? "FAIL: coverage of the code this change KEEPS or ADDS dropped by {$delta}%.\n"
                : "FAIL: coverage of the code this change KEEPS or ADDS dropped by less than 0.01% — too "
                . "little to show in the percentage, but a real loss in the counts below.\n");
            echo "      base {$baseCovered}/{$baseStatements} -> head {$covered}/{$statements} statements, "
                . "comparing only methods that exist on BOTH sides plus everything new on this branch.\n";

            if ($statements > $baseStatements) {
                $added = ($statements - $baseStatements);
                echo "      This change adds {$added} statements to those files. Adding code without tests drops coverage.\n";
            }

            echo "      Deleted methods were already excluded, so this is not a deletion penalty. If you\n";
            echo "      RENAMED a method, note that a rename reads as a delete plus an add: the new name is\n";
            echo "      new code here and has to be covered.\n";

            exit(CG_DROPPED);
        }

        echo "OK: coverage of the surviving and added code did not drop.\n";
        exit(CG_OK);
    }//end if

    [$statements, $covered, $current]             = cgMeasureFiles($cloverFile, 'current', $changed);
    [$baseStatements, $baseCovered, $base]        = cgMeasureFiles($against, 'merge-base', $changed);

    if ($statements === 0 && $baseStatements === 0) {
        echo "OK: none of the changed PHP files appear in either coverage report.\n";
        echo "    Nothing was measured, so nothing is claimed about them.\n";
        exit(CG_OK);
    }

    cgReport('Changed files, head:', $statements, $covered, $current);
    cgReport('Changed files, base:', $baseStatements, $baseCovered, $base);

    if ($baseStatements === 0) {
        echo "OK: the changed PHP is new at the merge base, so there is no prior figure to drop below.\n";
        exit(CG_OK);
    }

    if (cgRatioDropped($covered, $statements, $baseCovered, $baseStatements) === true) {
        $delta = round(($base - $current), 2);
        echo "FAIL: coverage of the files this change touches dropped by {$delta}%.\n";
        echo "      base {$baseCovered}/{$baseStatements} -> head {$covered}/{$statements} statements.\n";
        if ($statements > $baseStatements) {
            $added = ($statements - $baseStatements);
            echo "      This change adds {$added} statements to those files. Adding code without tests drops coverage.\n";
        }

        exit(CG_DROPPED);
    }

    echo "OK: coverage of the changed files did not drop.\n";
    exit(CG_OK);
}//end if

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
