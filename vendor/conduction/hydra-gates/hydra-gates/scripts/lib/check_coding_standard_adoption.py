#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction
# SPDX-License-Identifier: EUPL-1.2
"""
gate-65: coding-standard-adoption.

Conduction code must pass ``nextcloud/coding-standard`` unchanged. We may be
stricter than Nextcloud; we may not be different from it. This checker is what
makes that a rule rather than an intention.

WHY IT EXISTS
-------------
Measured 2026-08-12 across the 18 core apps, from canonical
``<app>@development``. ``psalm.xml``, ``phpstan.neon``, ``playwright.config.ts``
and ``code-quality.yml`` each existed in EIGHTEEN mutually different versions.
``NamedParametersSniff.php`` — a custom *rule*, not a setting — existed in six,
one of which called ``addWarning()`` where the others called ``addError()``.

None of that was anyone's decision. Every one of those files started as a copy
of something shared, and copies drift. Centralising them does not by itself fix
that; the 18 variants were copies of something shared too. What stops it
recurring is a check that fails when an app walks away from the centre.

Each rule below corresponds to a defect that was live in the fleet, not to a
preference:

  1. NO PHP-CS-FIXER CONFIG — all 1,427 openregister files failed Nextcloud's
     standard because nothing ever ran it.
  2. MISSING AUTOLOADER in .php-cs-fixer.dist.php — php-cs-fixer includes that
     file before the app's autoloader, so the config fatals with "Class not
     found"; in --format=json that fatal is reported as ZERO FILES NEEDING
     CHANGES. It reads exactly like a clean tree.
  3. cs:check / cs:fix WIRED TO PHPCS — those are nextcloud/coding-standard's
     script names. In this fleet they were aliases for phpcs/phpcbf, so the
     documented Nextcloud command reformatted code AWAY from Nextcloud.
  4. nextcloud/coding-standard AS A DIRECT DEPENDENCY — 17 of 18 apps declared
     it with no config file and no invocation anywhere. It must arrive
     transitively, at a version conduction/coding-standard has tested against.
  5. LOCAL FORMATTING SNIFFS — two formatters with overlapping jurisdiction make
     an app UNFIXABLE: `cs:fix` and `phpcs` demand opposite things and neither
     can be satisfied. Running the old PEAR ruleset over php-cs-fixer-formatted
     code produced 111,932 findings, 111,747 of them formatting.
  6. A LOCAL phpcs-custom-sniffs/ COPY — the six-versions-of-one-rule defect.
  7. NO .editorconfig — no fleet app had one, so an editor configured by
     someone's previous Nextcloud work defaulted to tabs, which the old ruleset
     then rejected. Nextcloud core ships one.
  8. UNQUOTED STYLELINT GLOB — 13 apps ran `stylelint src/**/*.vue …` unquoted,
     so the SHELL expanded it and without globstar `src/**/` matched exactly one
     directory level. Nested components were silently unlinted.
  9. nextcloud/ocp BELOW info.xml's min-version — 15 apps declared NC 32-34 and
     analysed against ^31. Nothing ADDED in 32/33/34 was visible, and — the part
     that bites — nothing REMOVED was reportable either. That is why the NC 34
     removal of \\OC::$server needed a hand-written PHPCS sniff.
 10. A PINNED SHARED STANDARD — the gates, the coding standard and the shared
     workflow are consumed at the tip, never at a version. A pin is a silent
     expiry date, and this fleet has paid for it twice in the same year:

       .github#159  22 repos sat on gate package v1.0.1 while 16 gates were
                    DEAD fleet-wide — and every one of them reported PASS.
       .github#173  a default flipped at @main then reached those same old
                    runners and turned them red on gates whose subject matter
                    they had no code for.

     Both directions of that failure come from the same cause: two halves of one
     system moving independently. `^1.0` is not a pin — it floats within a major
     and is correct. An exact version, a `dev-<branch>` constraint, or a
     `hydra-gates-ref` that is not `main`, is.

USAGE
    check_coding_standard_adoption.py <app-root>

Prints one `FAIL <rule>: <detail>` line per violation, then a terminal summary
`checked N rule(s)`. The runner treats a missing summary as a WIRING failure
rather than a pass, so a crash cannot be mistaken for a clean repo.

Exit code is the violation count (0 = clean, 90 = could not run).
"""

from __future__ import annotations

import json
import os
import re
import sys

CENTRAL_RULESET = "quality-config/phpcs.xml"

# Sniff families php-cs-fixer owns. A phpcs.xml that names one locally is
# re-opening the jurisdiction conflict rule 5 describes. Matched against the
# `ref=` of a <rule>, so `Generic.WhiteSpace.ScopeIndent` and
# `Squiz.WhiteSpace.OperatorSpacing` both hit on `WhiteSpace`.
FORMATTING_TOKENS = (
    "WhiteSpace",
    "Whitespace",
    "ScopeIndent",
    "ArrayIndent",
    "Indent",
    "SpaceAfterCast",
    "ConcatenationSpacing",
    "MultipleStatementAlignment",
    "ClassDeclaration",
    "FunctionDeclaration",
    "ElseIfDeclaration",
    "DocCommentAlignment",
    "OpeningBrace",
    "ClosingBrace",
    "LineEndings",
    "DisallowTabIndent",
    "DisallowSpaceIndent",
)


def read(path: str) -> str | None:
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except OSError:
        return None


def strip_xml_comments(xml: str) -> str:
    """A commented-out rule is not a rule. Matching one would be the
    grep-a-string-and-hit-every-comment defect gate-64 was written about."""
    return re.sub(r"<!--.*?-->", "", xml, flags=re.S)


def check(root: str) -> tuple[list[str], int]:
    fails: list[str] = []
    checked = 0

    def fail(rule: str, detail: str) -> None:
        fails.append(f"FAIL {rule}: {detail}")

    def j(name: str):
        raw = read(os.path.join(root, name))
        if raw is None:
            return None
        try:
            return json.loads(raw)
        except json.JSONDecodeError as exc:
            fail("unparseable-json", f"{name} is not valid JSON ({exc}).")
            return None

    composer = j("composer.json")
    package = j("package.json")

    # ── 1 + 2. php-cs-fixer config ───────────────────────────────────────
    checked += 1
    fixer = read(os.path.join(root, ".php-cs-fixer.dist.php")) or read(
        os.path.join(root, ".php-cs-fixer.php")
    )
    if fixer is None:
        fail(
            "no-php-cs-fixer-config",
            "no .php-cs-fixer.dist.php. Nothing in this repo runs Nextcloud's "
            "coding standard, so nothing can be said about whether it passes it.",
        )
    else:
        if "Conduction\\CodingStandard\\Config" not in fixer and "CodingStandard\\Config" not in fixer:
            fail(
                "wrong-fixer-config",
                ".php-cs-fixer.dist.php does not instantiate "
                "Conduction\\CodingStandard\\Config.",
            )
        checked += 1
        if not re.search(r"require(_once)?\s.*autoload\.php", fixer):
            fail(
                "fixer-config-missing-autoloader",
                ".php-cs-fixer.dist.php never requires vendor/autoload.php. "
                "php-cs-fixer includes this file before the app's autoloader, so "
                "it fatals with \"Class not found\" — and in --format=json that "
                "fatal is reported as ZERO FILES NEEDING CHANGES, which reads "
                "exactly like a clean tree.",
            )

    # ── 3 + 4. composer wiring ───────────────────────────────────────────
    if composer is not None:
        req = {}
        req.update(composer.get("require") or {})
        req.update(composer.get("require-dev") or {})
        scripts = composer.get("scripts") or {}

        checked += 1
        if "conduction/coding-standard" not in req:
            fail(
                "coding-standard-not-required",
                "composer.json does not require conduction/coding-standard.",
            )

        checked += 1
        if "nextcloud/coding-standard" in req:
            fail(
                "nextcloud-coding-standard-declared-directly",
                "nextcloud/coding-standard is a DIRECT dependency. It must arrive "
                "transitively through conduction/coding-standard, at a version that "
                "package has tested against. Declared directly it was, in 17 of 18 "
                "apps, a dead dependency with no config and no invocation — loaded, "
                "and ready to reformat everything for whoever found it.",
            )

        checked += 1
        for name in ("cs:check", "cs:fix"):
            body = scripts.get(name)
            if body is None:
                fail(
                    "cs-script-missing",
                    f"composer.json declares no '{name}' script.",
                )
                continue
            body_s = body if isinstance(body, str) else " ".join(body)
            if "phpcbf" in body_s or re.search(r"\bphpcs\b", body_s):
                fail(
                    "cs-script-wired-to-phpcs",
                    f"'{name}' runs PHPCS, not php-cs-fixer. That is "
                    "nextcloud/coding-standard's script name, so a contributor "
                    "running the documented Nextcloud command gets this fleet's "
                    f"reformatting instead. Body: {body_s!r}",
                )
            elif "php-cs-fixer" not in body_s:
                fail(
                    "cs-script-runs-neither",
                    f"'{name}' invokes neither php-cs-fixer nor phpcs: {body_s!r}",
                )

    # ── 5 + 6. PHPCS keeps to semantics ──────────────────────────────────
    phpcs_raw = read(os.path.join(root, "phpcs.xml")) or read(
        os.path.join(root, "phpcs.xml.dist")
    )
    if phpcs_raw is not None:
        body = strip_xml_comments(phpcs_raw)

        checked += 1
        if CENTRAL_RULESET not in body:
            fail(
                "phpcs-not-centralised",
                f"phpcs.xml does not reference {CENTRAL_RULESET}. Every app that "
                "kept its own full ruleset is how this fleet reached six variants "
                "of one file.",
            )

        checked += 1
        local_formatting = []
        for ref in re.findall(r'<rule\s+ref="([^"]+)"', body):
            if ref.startswith("vendor/") or ref.startswith("./") or ref.startswith("../"):
                continue
            if any(tok in ref for tok in FORMATTING_TOKENS):
                local_formatting.append(ref)
        if local_formatting:
            fail(
                "phpcs-declares-formatting-sniffs",
                "phpcs.xml names formatting sniffs that php-cs-fixer owns: "
                + ", ".join(sorted(set(local_formatting)))
                + ". Two formatters with overlapping jurisdiction make an app "
                "unfixable — cs:fix and phpcs then demand opposite things and "
                "neither can be satisfied.",
            )

    checked += 1
    if os.path.isdir(os.path.join(root, "phpcs-custom-sniffs")):
        fail(
            "local-custom-sniffs",
            "phpcs-custom-sniffs/ exists locally. The sniffs come from "
            "vendor/conduction/hydra-gates/. A local copy is how "
            "NamedParametersSniff.php reached six versions across the fleet, one "
            "of them calling addWarning() where the rest called addError().",
        )

    # ── 7. .editorconfig ─────────────────────────────────────────────────
    checked += 1
    editorconfig = read(os.path.join(root, ".editorconfig"))
    if editorconfig is None:
        fail(
            "no-editorconfig",
            "no .editorconfig. Nextcloud core ships one; without it an editor "
            "falls back to whatever the developer last configured.",
        )
    else:
        # The [*] section's indent_style. A file that sets it to space for PHP
        # contradicts the formatter it is supposed to agree with.
        star = re.search(r"^\[\*\]\s*$(.*?)(?=^\[|\Z)", editorconfig, re.M | re.S)
        style = None
        if star:
            m = re.search(r"^\s*indent_style\s*=\s*(\w+)", star.group(1), re.M)
            style = m.group(1) if m else None
        if style != "tab":
            fail(
                "editorconfig-not-tab",
                f"[*] indent_style is {style!r}, not 'tab'. Nextcloud's own "
                ".editorconfig sets tab, and php-cs-fixer enforces it — an "
                ".editorconfig that disagrees fights the formatter on every save.",
            )

    # ── 8. stylelint glob ────────────────────────────────────────────────
    if package is not None:
        sl = (package.get("scripts") or {}).get("stylelint")
        if sl:
            checked += 1
            # Everything after the binary name. An unquoted glob containing `**`
            # is expanded by the SHELL, and without globstar `src/**/` matches
            # exactly one directory level.
            args = sl.split("stylelint", 1)[1] if "stylelint" in sl else sl
            for token in args.split():
                if "**" in token and not (
                    token.startswith(("'", '"')) or token.endswith(("'", '"'))
                ):
                    fail(
                        "stylelint-glob-unquoted",
                        f"the stylelint script passes an unquoted glob {token!r}. "
                        "The shell expands it, and without globstar `src/**/` "
                        "matches exactly one directory level — nested components "
                        "are silently not linted. Quote it so stylelint expands it.",
                    )
                    break

    # ── 9. ocp major vs info.xml min-version ─────────────────────────────
    info = read(os.path.join(root, "appinfo", "info.xml"))
    if info is not None and composer is not None:
        m = re.search(r'<nextcloud[^>]*min-version="(\d+)"', info)
        req = {}
        req.update(composer.get("require") or {})
        req.update(composer.get("require-dev") or {})
        ocp = req.get("nextcloud/ocp")
        if m and ocp:
            checked += 1
            declared_min = int(m.group(1))
            om = re.search(r"(\d+)", ocp)
            # `dev-master` and friends track the tip; they cannot be below the
            # declared minimum, so they are not a finding.
            if om and not ocp.strip().startswith("dev-"):
                ocp_major = int(om.group(1))
                if ocp_major < declared_min:
                    fail(
                        "ocp-below-declared-minimum",
                        f"appinfo/info.xml declares min-version=\"{declared_min}\" "
                        f"but composer pins nextcloud/ocp:{ocp}. Static analysis is "
                        f"reading the NC {ocp_major} API surface, a major below what "
                        "this app claims to support — so nothing added in "
                        f"{declared_min}+ is visible to it, and nothing REMOVED in "
                        f"{declared_min}+ can be reported. That is why the NC 34 "
                        "removal of \\OC::$server needed a hand-written sniff.",
                    )

    # ── 10. nothing shared may be pinned ─────────────────────────────────
    # An exact version, or a dev-branch constraint, freezes this app against a
    # standard the rest of the fleet has moved past. `^1.0` floats and is fine.
    if composer is not None:
        req = {}
        req.update(composer.get("require") or {})
        req.update(composer.get("require-dev") or {})
        for pkg in ("conduction/coding-standard", "conduction/hydra-gates"):
            spec = req.get(pkg)
            if not spec:
                continue
            checked += 1
            s = spec.strip()
            if s.startswith("dev-"):
                fail(
                    "shared-package-pinned",
                    f"{pkg} is constrained to {s!r} — a branch, not a released "
                    "range. This app is frozen against whatever that branch "
                    "happens to contain and will not follow the fleet. Use a "
                    "floating range such as ^1.0.",
                )
            elif re.fullmatch(r"v?\d+(\.\d+){1,2}", s) or s.startswith("=="):
                fail(
                    "shared-package-pinned",
                    f"{pkg} is pinned to the exact version {s!r}. A pin is a "
                    "silent expiry date: 22 repos once sat on gate package v1.0.1 "
                    "while 16 gates were dead fleet-wide and every one reported "
                    "PASS (.github#159). Use ^1.0.",
                )

    # The shared workflow and the gate package are consumed at the tip. A caller
    # that names a tag stops receiving fixes AND keeps receiving new defaults
    # from the half of the system it did not pin — the #173 direction.
    wf = read(os.path.join(root, ".github", "workflows", "code-quality.yml"))
    if wf is not None:
        body = re.sub(r"^\s*#.*$", "", wf, flags=re.M)

        checked += 1
        m = re.search(r"^\s*hydra-gates-ref:\s*[\"']?([^\s\"'#]+)", body, re.M)
        if m and m.group(1) != "main":
            fail(
                "gates-ref-pinned",
                f"hydra-gates-ref is set to {m.group(1)!r}. The gate package is "
                "consumed at main, together with the shared workflow that drives "
                "it, so a gate fix reaches this repo without a commit in this "
                "repo. Pinned, the two halves move independently: an old runner "
                "receives a new default and goes red on gates it has no subject "
                "matter for (.github#173), or silently stops running gates it "
                "never learned about (#159). Remove the input.",
            )

        checked += 1
        for ref in re.findall(
            r"uses:\s*ConductionNL/\.github/\.github/workflows/[^@\s]+@([^\s]+)", body
        ):
            if ref != "main":
                fail(
                    "shared-workflow-pinned",
                    f"the shared quality workflow is consumed at @{ref}, not @main. "
                    "A pinned pipeline stops receiving fixes for defects found in "
                    "other apps — which is the entire reason it is shared.",
                )
                break

    return fails, checked


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: check_coding_standard_adoption.py <app-root>", file=sys.stderr)
        return 90

    root = sys.argv[1]
    if not os.path.isdir(root):
        print(f"not a directory: {root}", file=sys.stderr)
        return 90

    try:
        fails, checked = check(root)
    except Exception as exc:  # noqa: BLE001 - a crash must not read as clean
        print(f"checker crashed: {exc!r}", file=sys.stderr)
        return 90

    for line in fails:
        print(line)

    # Terminal summary. The runner treats its absence as a WIRING failure, so a
    # crash can never be mistaken for a clean repo.
    print(f"checked {checked} rule(s)")
    return len(fails)


if __name__ == "__main__":
    sys.exit(main())
