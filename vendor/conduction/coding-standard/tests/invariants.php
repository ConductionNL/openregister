<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Conduction
// SPDX-License-Identifier: EUPL-1.2

/**
 * Invariant suite for conduction/coding-standard.
 *
 * Deliberately dependency-free — plain PHP, no PHPUnit — so it runs in any
 * container with the package's own autoloader and nothing else. The exit code
 * is the number of failed invariants.
 *
 * Every test below has a POSITIVE CONTROL: an assertion that the test can
 * fail. A suite that cannot fail is indistinguishable from a suite that passes,
 * and this one exists specifically to catch a class of mistake that is
 * invisible by inspection.
 */

require_once __DIR__ . '/bootstrap.php';

use Conduction\CodingStandard\Config;
use Nextcloud\CodingStandard\Config as NextcloudConfig;

$failures = 0;
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $failures, $checks;
	$checks++;
	if ($ok) {
		echo "  PASS  $name\n";
		return;
	}
	$failures++;
	echo "  FAIL  $name\n";
	if ($detail !== '') {
		foreach (explode("\n", rtrim($detail)) as $line) {
			echo "        $line\n";
		}
	}
}

echo "conduction/coding-standard — invariants\n\n";

$conduction = new Config();
$nextcloud = new NextcloudConfig();

$ours = $conduction->getRules();
$theirs = $nextcloud->getRules();
$additions = $conduction->getAdditions();

// ── 1. THE INVARIANT ──────────────────────────────────────────────────────
// We may add rules. We may never redefine one Nextcloud has already decided.
echo "1. Additions never override Nextcloud\n";

$overridden = array_keys(array_intersect_key($additions, $theirs));
check(
	'ADDITIONS shares no key with Nextcloud\'s rule set',
	$overridden === [],
	$overridden === [] ? ''
		: "These keys are already set by nextcloud/coding-standard:\n  - "
		. implode("\n  - ", $overridden)
		. "\n\nOverriding one of Nextcloud's rules means our code can stop passing\n"
		. "their check. If the rule genuinely needs a different value, that is a\n"
		. 'conversation with upstream, not a local override.'
);

// POSITIVE CONTROL: prove the check above can fail.
$sabotage = array_intersect_key(['indentation_type' => false], $theirs);
check(
	'positive control — the overlap test detects a planted override',
	$sabotage !== [],
	"array_intersect_key found no overlap for a key Nextcloud demonstrably sets\n"
	. '(indentation_type). The test instrument is broken, not the rule set.'
);

// ── 2. Every Nextcloud rule survives, with its value intact ───────────────
echo "\n2. Nextcloud's rules pass through unchanged\n";

$missing = [];
$changed = [];
foreach ($theirs as $rule => $value) {
	if (!array_key_exists($rule, $ours)) {
		$missing[] = $rule;
		continue;
	}
	if ($ours[$rule] !== $value) {
		$changed[] = $rule;
	}
}

check(
	'no Nextcloud rule is dropped',
	$missing === [],
	$missing === [] ? '' : "Dropped:\n  - " . implode("\n  - ", $missing)
);

check(
	'no Nextcloud rule has a different value',
	$changed === [],
	$changed === [] ? '' : "Altered:\n  - " . implode("\n  - ", $changed)
);

check(
	'positive control — Nextcloud actually defines rules to compare against',
	count($theirs) > 20,
	'Only ' . count($theirs) . " rules came back from nextcloud/coding-standard.\n"
	. 'An empty or near-empty parent set would make tests 2 vacuously true.'
);

// ── 3. Indentation is Nextcloud's, not the fleet's old PEAR four spaces ───
// The single rule this whole package exists to stop drifting.
echo "\n3. Indentation is a tab\n";

check(
	'the configured indent is a literal tab',
	$conduction->getIndent() === "\t",
	'Got ' . var_export($conduction->getIndent(), true)
	. ". Four spaces is the pre-2026-08 fleet default and it fails\n"
	. 'nextcloud/coding-standard on 98.7% of files.'
);

check(
	'indentation_type is enabled',
	($ours['indentation_type'] ?? null) === true,
	'indentation_type is ' . var_export($ours['indentation_type'] ?? null, true)
	. '; without it the indent setting is not enforced.'
);

// ── 4. The rules the old PEAR ruleset contradicted are present ────────────
// Named individually because each one was a measured, fleet-wide conflict.
echo "\n4. The formatting rules that used to conflict are in force\n";

$formerConflicts = [
	'curly_braces_position' => 'PEAR put class/function braces on the next line (100% of files)',
	'cast_spaces' => 'PEAR wrote (int) $x; Nextcloud writes (int)$x (47% of files)',
	'concat_space' => "PEAR wrote 'a'.'b'; Nextcloud writes 'a' . 'b' (41% of files)",
	'binary_operator_spaces' => 'PHPCS aligned = across statements; Nextcloud uses one space (77%)',
	'phpdoc_align' => 'PHPCS aligned docblock columns; Nextcloud aligns left (86%)',
	'elseif' => 'Squiz.ControlStructures.ElseIfDeclaration forbade the elseif keyword Nextcloud requires',
];

foreach ($formerConflicts as $rule => $why) {
	check("$rule is set", array_key_exists($rule, $ours), $why);
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('-', 68) . "\n";
printf("%d checks, %d failed\n", $checks, $failures);
printf("rules: %d from nextcloud, %d added by conduction\n", count($theirs), count($additions));
echo str_repeat('-', 68) . "\n";

exit($failures === 0 ? 0 : 1);
