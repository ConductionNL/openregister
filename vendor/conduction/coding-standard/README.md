<!--
SPDX-FileCopyrightText: 2026 Conduction
SPDX-License-Identifier: EUPL-1.2
-->

# conduction/coding-standard

PHP CS Fixer configuration for Conduction's Nextcloud apps. It extends
[`nextcloud/coding-standard`](https://github.com/nextcloud/coding-standard) and
adds rules to it — **never** overrides them.

## The rule this package enforces

> Conduction code must pass Nextcloud's own coding standard unchanged. We may be
> **stricter** than Nextcloud. We may not be **different** from it.

That is enforced by construction, not by review. `Conduction\CodingStandard\Config`
extends Nextcloud's and merges a private `ADDITIONS` array on top of
`parent::getRules()`. [`tests/invariants.php`](tests/invariants.php) fails the
build if `ADDITIONS` shares a single key with the parent set, so a Nextcloud rule
cannot be redefined even by accident.

The suite also asserts that no parent rule is dropped, that no parent rule's
*value* changed, and that the indent is a literal tab — and each of those has a
positive control proving the assertion can fail. A suite that cannot fail is
indistinguishable from one that passes.

## Why it exists

Measured 2026-08-12 against openregister's `lib/`: **all 1,427 files failed
`nextcloud/coding-standard`.** `curly_braces_position` 100%, `indentation_type`
98.7%, `phpdoc_align` 85.6%, `binary_operator_spaces` 77.4%, `cast_spaces` 47.4%,
`concat_space` 40.9%.

The fleet had been formatting PHP with a PEAR-derived PHP_CodeSniffer ruleset —
four spaces, next-line braces, `(int) $x`, `'a'.'b'` — under script names
(`cs:check` / `cs:fix`) that belong to `nextcloud/coding-standard`, which 17 of 18
apps also carried in `require-dev` without ever invoking it. That was not a
stricter standard; it was a different dialect wearing the right label.

## Usage

```jsonc
// composer.json
"require-dev": {
    "conduction/coding-standard": "^1.0"
},
"scripts": {
    "cs:check": "php-cs-fixer fix --dry-run --diff",
    "cs:fix": "php-cs-fixer fix"
}
```

```php
<?php
// .php-cs-fixer.dist.php
require_once __DIR__ . '/vendor/autoload.php';

$config = new Conduction\CodingStandard\Config();
$config->getFinder()->in(__DIR__ . '/lib');

return $config;
```

The `require_once` is not optional. `.php-cs-fixer.dist.php` is included by
php-cs-fixer before your autoloader runs, so without it the file dies with
`Class "Conduction\CodingStandard\Config" not found` — and in `--format=json`
mode that fatal is reported as **zero files needing changes**, which reads
exactly like a clean tree.

## What `ADDITIONS` contains, and why it is empty

Empty is a result, not an omission.

Every rule this fleet wants beyond Nextcloud's is *semantic*, not typographic —
named parameters on internal calls, an `@spec` tag on public API, no
`\OC::$server`, no `var_dump` / `die` / `error_log`. php-cs-fixer cannot express
any of them. They live in PHP_CodeSniffer, which the fleet now runs for semantics
only, with every whitespace, brace and alignment sniff removed precisely so the
two tools cannot contradict each other.

On formatting, we want exactly what Nextcloud wants.

Adding a key here is therefore a real decision. It must be a rule Nextcloud has no
opinion on; the test refuses it otherwise.

## Division of labour

| Concern | Tool | Config |
| --- | --- | --- |
| Formatting — whitespace, braces, imports, quotes, casts | php-cs-fixer | **this package** |
| Semantics — named parameters, `@spec`, banned functions, removed NC APIs, line length | PHP_CodeSniffer | [`quality-config/`](https://github.com/ConductionNL/.github/tree/main/quality-config) in `ConductionNL/.github` |
| Types | Psalm + PHPStan | `quality-config/` |

The PHPCS ruleset carries a test asserting it reports **zero** findings on
php-cs-fixer-formatted fixtures. That is the automated form of the measurement
that produced this split, and it is what stops the two tools drifting back into
conflict.

## Installing before Packagist registration

Until this package is registered on Packagist, consume it from source:

```jsonc
"repositories": [
    { "type": "vcs", "url": "https://github.com/ConductionNL/coding-standard.git" }
]
```

Registering it removes that block from every app, which is one less per-app file
to drift. It is a one-time manual step at
[packagist.org/packages/submit](https://packagist.org/packages/submit).

## Further reading

- [Way of Work → CI/CD and Code Standards](https://docs.conduction.nl/WayOfWork/ci-cd/)
- [nextcloud/coding-standard](https://github.com/nextcloud/coding-standard)
