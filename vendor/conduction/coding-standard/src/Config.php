<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Conduction
// SPDX-License-Identifier: EUPL-1.2

namespace Conduction\CodingStandard;

use Nextcloud\CodingStandard\Config as NextcloudConfig;

/**
 * The Conduction PHP CS Fixer configuration.
 *
 * ── THE INVARIANT ─────────────────────────────────────────────────────────
 *
 * Conduction code MUST pass `nextcloud/coding-standard` unchanged. We may be
 * STRICTER than Nextcloud; we may never be DIFFERENT from it. A file that
 * satisfies this config must also satisfy Nextcloud's, or the rule that broke
 * it does not belong here.
 *
 * That is not a review convention, it is enforced by construction: this class
 * extends Nextcloud's and only ever ADDS keys to its rule set. `tests/
 * invariants.php` fails the build if ADDITIONS shares a single key with
 * `parent::getRules()`, so a rule cannot be redefined even by accident.
 *
 * ── WHY IT EXISTS ─────────────────────────────────────────────────────────
 *
 * Before this package the fleet formatted PHP with a PEAR-derived PHPCS
 * ruleset: four spaces, next-line braces, `(int) $x`, `'a'.'b'`. Measured
 * 2026-08-12 against openregister's lib/, ALL 1,427 files failed
 * `nextcloud/coding-standard` — 100% on `curly_braces_position`, 98.7% on
 * `indentation_type`. That was never "stricter", it was a different dialect.
 *
 * ── WHAT ADDITIONS IS FOR, AND WHY IT IS EMPTY ────────────────────────────
 *
 * Empty is a RESULT, not an omission. Every rule the fleet actually wants
 * beyond Nextcloud's is semantic rather than typographic — named parameters on
 * internal calls, an `@spec` tag on public API, no `\OC::$server`, no
 * `var_dump`/`die`/`error_log` — and php-cs-fixer cannot express any of them.
 * They live in PHP_CodeSniffer, which this fleet now runs for SEMANTICS ONLY,
 * with every whitespace, brace and alignment sniff removed precisely so it
 * cannot contradict the fixer.
 *
 * So the honest answer today is: on formatting, we want exactly what Nextcloud
 * wants. Adding a key here is a real decision — it must be a rule Nextcloud has
 * no opinion on, and the test will refuse it otherwise.
 *
 * @see https://docs.conduction.nl/WayOfWork/ci-cd/
 */
class Config extends NextcloudConfig {
	/**
	 * Fixers Conduction applies ON TOP OF Nextcloud's.
	 *
	 * Every key here MUST be absent from `parent::getRules()`. Adding a key
	 * that Nextcloud already sets is a build failure, not a merge conflict.
	 *
	 * @var array<string, mixed>
	 */
	private const ADDITIONS = [];

	public function __construct(string $name = 'conduction') {
		parent::__construct($name);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRules(): array {
		return array_merge(parent::getRules(), self::ADDITIONS);
	}

	/**
	 * The additive rule set, exposed so the invariant test can assert on it
	 * without reflection.
	 *
	 * @return array<string, mixed>
	 */
	public function getAdditions(): array {
		return self::ADDITIONS;
	}
}
