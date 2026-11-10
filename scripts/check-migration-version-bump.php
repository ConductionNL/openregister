#!/usr/bin/env php
<?php
/**
 * A migration that ships without a version bump reaches nobody.
 *
 * Nextcloud decides whether to run an app's migrations by comparing the
 * `<version>` in appinfo/info.xml against the `installed_version` it recorded
 * for that app. Equal versions mean "already up to date", and `occ upgrade`
 * then answers "No upgrade required." and exits 0. The migration files on disk
 * are never read. Nothing is logged, nothing fails, and the feature that needed
 * the table is simply absent.
 *
 * MEASURED, 2026-09-05, on a throwaway NC 34.0.3 rig with openregister
 * 2.0.15-unstable.20260905134511 installed from its release tarball:
 *
 *   added lib/Migration/Version1Date20260906090000.php (creates a table),
 *   left <version> alone, ran `occ upgrade`
 *     -> "No upgrade required."            exit 0
 *     -> table not created                 (0 rows in information_schema)
 *     -> no row in oc_migrations           (the step never ran)
 *     -> `occ migrations:status openregister` said
 *          Executed Migrations: 204
 *          Available Migrations: 205
 *          Pending Migrations: None        <- see NOTE below
 *
 *   then changed NOTHING but `<version>`, and re-ran `occ upgrade`
 *     -> "Updated <openregister> to 2.0.16"
 *     -> table created, migration recorded
 *
 * So the version string is the whole gate, and the same code either ships or
 * does not depending on one line of XML.
 *
 * NOTE on `occ migrations:status`: it cannot be used to catch this. Its
 * "Pending Migrations" field is built by MigrationService::describeMigrationStep(),
 * which drops every step whose `name()` is empty — and SimpleMigrationStep::name()
 * returns '' by default, which all 204 of ours inherit. Its "New Migrations" and
 * "Executed Unavailable Migrations" fields are worse: core's StatusCommand calls
 * `array_keys()` on getAvailableVersions(), which returns a LIST, so both fields
 * compare version strings against the integers 0..n and report the full count
 * every time. The only honest pair in that output is Executed vs Available.
 * That is Nextcloud's code, not ours; see lib/Migration/NAMING.md.
 *
 * WHAT THIS SCRIPT DOES. Fails when the branch adds a file under lib/Migration/
 * without moving `<version>` in appinfo/info.xml past the value at the merge base.
 *
 *   php scripts/check-migration-version-bump.php [--base=<ref>] [--repo=<path>]
 *
 * Base ref resolution: --base, else $MIGRATION_VERSION_BASE_REF, else
 * $HYDRA_GATE_BASE_REF, else origin/development.
 *
 * Exit codes: 0 clean, 1 a migration was added without a bump, 2 the check
 * could not be run (no repo, unresolvable base, unparseable info.xml). It exits
 * 2 rather than 0 in that case deliberately: a check that cannot see the base
 * has no verdict to give, and a silent pass is the failure mode this whole
 * script exists to remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

const EXIT_OK       = 0;
const EXIT_VIOLATION = 1;
const EXIT_UNKNOWN  = 2;

/**
 * Run a git command and return its trimmed stdout, or null when it fails.
 *
 * @param string        $repo Repository working directory.
 * @param array<string> $args Arguments after `git`.
 *
 * @return string|null Trimmed stdout, or null on a non-zero exit.
 */
function git(string $repo, array $args): ?string
{
    $cmd = 'git -C ' . escapeshellarg($repo);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $out  = [];
    $code = 0;
    exec($cmd . ' 2>/dev/null', $out, $code);
    if ($code !== 0) {
        return null;
    }

    return trim(implode("\n", $out));
}

/**
 * Read the <version> element out of an info.xml document.
 *
 * Deliberately a regex on the raw text rather than a DOM parse: this must read
 * the file as it stands at an arbitrary git revision, where the surrounding
 * document may not be well formed, and a parse failure would be reported as
 * "no version" instead of "cannot tell".
 *
 * @param string|null $xml The document text, or null when it could not be read.
 *
 * @return string|null The version, or null when absent.
 */
function versionFromInfoXml(?string $xml): ?string
{
    if ($xml === null) {
        return null;
    }

    if (preg_match('#<version>\s*([^<\s][^<]*?)\s*</version>#', $xml, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * Print a message to stderr.
 *
 * @param string $message The message.
 *
 * @return void
 */
function err(string $message): void
{
    fwrite(STDERR, $message . "\n");
}

$repo = getcwd();
$base = getenv('MIGRATION_VERSION_BASE_REF');
if ($base === false || $base === '') {
    $base = getenv('HYDRA_GATE_BASE_REF');
}

if ($base === false || $base === '') {
    $base = 'origin/development';
}

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base=') === true) {
        $base = substr($arg, 7);
        continue;
    }

    if (str_starts_with($arg, '--repo=') === true) {
        $repo = substr($arg, 7);
        continue;
    }

    if ($arg === '--help' || $arg === '-h') {
        echo "usage: check-migration-version-bump.php [--base=<ref>] [--repo=<path>]\n";
        exit(EXIT_OK);
    }

    err('check-migration-version-bump: unknown argument ' . $arg);
    exit(EXIT_UNKNOWN);
}

$root = git($repo, ['rev-parse', '--show-toplevel']);
if ($root === null || $root === '') {
    err('check-migration-version-bump: ' . $repo . ' is not a git repository — no verdict.');
    exit(EXIT_UNKNOWN);
}

$baseSha = git($root, ['rev-parse', '--verify', '--quiet', $base . '^{commit}']);
if ($baseSha === null || $baseSha === '') {
    $baseSha = git($root, ['rev-parse', '--verify', '--quiet', 'origin/' . $base . '^{commit}']);
}

if ($baseSha === null || $baseSha === '') {
    err('check-migration-version-bump: cannot resolve base ref "' . $base . '" — no verdict.');
    err('  Fetch it first, or pass --base=<ref>. A base this check cannot see is');
    err('  not the same as a clean branch, so this refuses to report one.');
    exit(EXIT_UNKNOWN);
}

$mergeBase = git($root, ['merge-base', $baseSha, 'HEAD']);
if ($mergeBase === null || $mergeBase === '') {
    err('check-migration-version-bump: no merge base between HEAD and ' . $base . ' — no verdict.');
    err('  A shallow clone is the usual cause; fetch with enough depth to reach it.');
    exit(EXIT_UNKNOWN);
}

// Three ways a migration can be present and new, and all three count. The
// committed case is what CI sees; the other two are what a developer has in
// front of them before they push, which is where this is cheapest to fix.
// --no-renames is deliberate: renaming an already-applied migration makes
// Nextcloud re-run it (see lib/Migration/NAMING.md), so a rename must be
// treated as an addition and must move the version too.
$added = [];
foreach (
    [
        ['diff', '--diff-filter=A', '--no-renames', '--name-only', $mergeBase . '..HEAD', '--', 'lib/Migration'],
        ['diff', '--diff-filter=A', '--no-renames', '--name-only', $mergeBase, '--', 'lib/Migration'],
        ['ls-files', '--others', '--exclude-standard', '--', 'lib/Migration'],
    ] as $args
) {
    $out = git($root, $args);
    if ($out === null || $out === '') {
        continue;
    }

    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line !== '' && str_ends_with($line, '.php') === true) {
            $added[$line] = true;
        }
    }
}

$added = array_keys($added);
sort($added);

if ($added === []) {
    echo "check-migration-version-bump: no migrations added against " . substr($mergeBase, 0, 8) . ", nothing to check.\n";
    exit(EXIT_OK);
}

$baseVersion = versionFromInfoXml(git($root, ['show', $mergeBase . ':appinfo/info.xml']));
$headXml     = @file_get_contents($root . '/appinfo/info.xml');
$headVersion = versionFromInfoXml($headXml === false ? null : $headXml);

if ($baseVersion === null) {
    err('check-migration-version-bump: no <version> in appinfo/info.xml at ' . substr($mergeBase, 0, 8) . ' — no verdict.');
    exit(EXIT_UNKNOWN);
}

if ($headVersion === null) {
    err('check-migration-version-bump: no <version> in appinfo/info.xml on this branch — no verdict.');
    exit(EXIT_UNKNOWN);
}

if (version_compare($headVersion, $baseVersion, '>') === true) {
    printf(
        "check-migration-version-bump: %d migration(s) added, <version> moved %s -> %s. OK.\n",
        count($added),
        $baseVersion,
        $headVersion
    );
    exit(EXIT_OK);
}

err('');
err('  This branch adds ' . count($added) . ' migration(s) but does not move the app version.');
err('');
foreach ($added as $file) {
    err('    ' . $file);
}

err('');
err('  appinfo/info.xml <version>: ' . $baseVersion . ' at the merge base, ' . $headVersion . ' here.');
err('');
err('  Nextcloud runs an app\'s migrations only when <version> is greater than the');
err('  installed_version it recorded. Left as it is, `occ upgrade` will answer');
err('  "No upgrade required.", exit 0, and run none of these on any instance that');
err('  already has this app. Nothing will be logged and nothing will fail.');
err('');
err('  Bump <version> in appinfo/info.xml in this same change. Do not wait for the');
err('  release bump PR: between releases, `development` is what people deploy.');
err('');
exit(EXIT_VIOLATION);
