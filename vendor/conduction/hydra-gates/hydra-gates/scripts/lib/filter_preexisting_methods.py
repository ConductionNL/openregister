#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""
filter_preexisting_methods.py — gate provenance check for hydra-gates.

Many gates iterate methods inside the PR-diff scope and emit per-method
findings. ADR-020 says "gate scope is the PR diff, not the whole repo",
but a file being in the diff is not the same as a method in that file
being introduced or modified by the PR. The classic false-positive
class: an i18n PR that touches one method in `lib/Controller/TasksController.php`
causes gate-7 to also flag the four pre-existing methods in the same
file — all with identical bodies on `origin/development` and HEAD.

This filter reads a gate log file in the canonical shape

    path/to/file.php:LINE method=METHOD_NAME rule=RULE_NAME

For each entry it compares the method's body at HEAD against the
method's body on the base ref. If byte-identical (ignoring trailing
whitespace) the entry is moved to `<log>.preexisting`; otherwise it
stays in the log. A net-new method (not on base) is treated as touched
and stays. So is anything where the filter can't determine the answer
(missing base ref, parse failure) — safe default: keep the finding.

Usage:
    filter_preexisting_methods.py BASE_REF LOG_FILE [LOG_FILE ...]

Exit code:
    0 always — this is a post-processing step; failure to filter never
    blocks the gate. Errors are logged to stderr.
"""
from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

_FN_PATTERN_CACHE: dict[str, re.Pattern[str]] = {}


def _fn_pattern(method: str) -> re.Pattern[str]:
    """Cached regex for `function METHOD_NAME(` at any visibility."""
    if method not in _FN_PATTERN_CACHE:
        _FN_PATTERN_CACHE[method] = re.compile(
            rf"function\s+{re.escape(method)}\s*\("
        )
    return _FN_PATTERN_CACHE[method]


def _file_at_base(base_ref: str, file_path: str) -> str | None:
    """Return file content on base ref, or None if missing / git failure."""
    try:
        result = subprocess.run(
            [
                "git",
                "-c",
                "safe.directory=*",
                "show",
                f"{base_ref}:{file_path}",
            ],
            capture_output=True,
            text=True,
            check=False,
            timeout=15,
        )
    except (subprocess.SubprocessError, OSError):
        return None
    if result.returncode != 0:
        return None
    return result.stdout


def _extract_method_body(content: str, method: str) -> str | None:
    """Find the method by name and return its declaration + body.

    Brace-counts with awareness of single/double-quoted strings, single-line
    `//` and `#` comments, and `/* ... */` block comments. Returns None if
    the method isn't found or braces never balance.

    Known limitation (TODO): heredoc / nowdoc (`<<<EOT ... EOT;`) is NOT
    tracked. A `{` inside a heredoc body skews depth; a `}` inside one
    terminates extraction early. Safe-default behaviour: the resulting body
    won't byte-match the base ref, so the entry stays in the touched log
    (treated as PR-introduced) rather than being mis-classified pre-existing.
    Worst-case impact is inflated PR-introduced finding count on files that
    contain heredocs with brace characters — none of the gates that consume
    this filter currently fail-close on that condition, but it does defeat
    the gate's purpose of suppressing inherited debt. When a Conduction app
    starts using heredocs in services touched by gates 6/7/8/17, extend the
    walker with a heredoc state machine: on `<<<\\s*[\\'"]?(\\w+)[\\'"]?\\s*$`,
    skip until `^\\s*\\1;?\\s*$`.
    """
    fn_pat = _fn_pattern(method)
    lines = content.splitlines()
    start = None
    for i, line in enumerate(lines):
        if fn_pat.search(line):
            start = i
            break
    if start is None:
        return None
    depth = 0
    started = False
    body_lines: list[str] = []
    in_block_comment = False
    for i in range(start, len(lines)):
        line = lines[i]
        body_lines.append(line)
        # Walk the line and count braces outside of string literals + comments.
        in_str: str | None = None  # active quote char (' or ") or None
        escape = False
        j = 0
        while j < len(line):
            ch = line[j]
            # Inside a `/* ... */` block: only look for closing `*/`.
            if in_block_comment:
                if ch == "*" and j + 1 < len(line) and line[j + 1] == "/":
                    in_block_comment = False
                    j += 2
                    continue
                j += 1
                continue
            if escape:
                escape = False
                j += 1
                continue
            if in_str:
                if ch == "\\":
                    escape = True
                elif ch == in_str:
                    in_str = None
                j += 1
                continue
            # Outside strings — check for comment openers before counting braces.
            if ch == "/" and j + 1 < len(line):
                nxt = line[j + 1]
                if nxt == "/":
                    break  # rest of line is a // comment — skip
                if nxt == "*":
                    in_block_comment = True
                    j += 2
                    continue
            if ch == "#":
                break  # rest of line is a # comment (PHP 8+) — skip
            if ch in ("'", '"'):
                in_str = ch
                j += 1
                continue
            if ch == "{":
                depth += 1
                started = True
            elif ch == "}":
                depth -= 1
            j += 1
        if started and depth == 0:
            return "\n".join(_annotation_prefix(lines, start) + body_lines)
    return None


_ANNOTATION_LINE = re.compile(r"^\s*(#\[|/\*|\*|//|\]|'|\"|[A-Za-z_\\][\w\\]*\s*[:=]|\))")


def _annotation_prefix(lines: list[str], start: int) -> list[str]:
    """The attribute + docblock region immediately above the declaration.

    WHY THIS EXISTS — A DEFECT THIS FILTER USED TO ERASE
    ----------------------------------------------------
    Extraction began at the ``function NAME(`` line, so a method's ATTRIBUTES
    and DOCBLOCK were outside the comparison. Delete ``#[PublicPage]`` from a
    controller method and the body is byte-identical to the base ref, so the
    entry was classified pre-existing and MOVED OUT of the gate log.

    That is not a corner case: it is the ordinary way the defect enters a
    repository. Measured 2026-08-08 on openregister — ``#[PublicPage]``
    deleted from the engine's own ``GenericHealthController::index`` in the
    diff under test:

        hydra-gate-public-monitoring.log              (empty)  -> gate-30 PASS
        hydra-gate-public-monitoring.log.preexisting  lib/AppHost/Controller/
            GenericHealthController.php:78 method=index
            rule=monitoring-endpoint-missing-public-page

    The gate found it and this filter deleted it. Gates 5, 9 and 30 judge the
    annotation region and nothing else, so for them a method whose annotations
    changed IS a changed method.

    Direction of the change: including more lines can only move entries from
    ``preexisting`` back into the findings log. It cannot hide anything.
    """
    out: list[str] = []
    i = start - 1
    while i >= 0:
        stripped = lines[i].strip()
        if not stripped:
            # A blank line ends the region: a docblock/attribute stack sits
            # flush against its declaration.
            break
        if stripped.startswith(("}", "{")) or stripped.endswith((";", "{")):
            # Previous statement / end of the preceding member.
            break
        if not _ANNOTATION_LINE.match(lines[i]):
            break
        out.append(lines[i])
        i -= 1
    out.reverse()
    return out


def _normalise(body: str) -> str:
    """Whitespace-tolerant comparison key: strip trailing space + collapse blanks."""
    return "\n".join(ln.rstrip() for ln in body.splitlines()).strip()


_LOG_LINE = re.compile(r"^(?P<file>[^:]+):(?P<line>\d+)\s+method=(?P<method>\w+)\s+rule=(?P<rule>.+)$")


def _partition(base_ref: str, log_path: Path) -> tuple[list[str], list[str]]:
    """Return (touched, preexisting) lines for a single gate log file."""
    touched: list[str] = []
    preexisting: list[str] = []
    for raw in log_path.read_text().splitlines():
        if not raw.strip():
            continue
        m = _LOG_LINE.match(raw)
        if not m:
            # Unrecognised shape — keep as a touched finding (safe default).
            touched.append(raw)
            continue
        file_path = m.group("file")
        method = m.group("method")
        try:
            head_content = Path(file_path).read_text()
        except OSError:
            touched.append(raw)
            continue
        head_body = _extract_method_body(head_content, method)
        if head_body is None:
            touched.append(raw)
            continue
        base_content = _file_at_base(base_ref, file_path)
        if base_content is None:
            # File doesn't exist on base — net-new file means net-new method.
            touched.append(raw)
            continue
        base_body = _extract_method_body(base_content, method)
        if base_body is None:
            # Method is net-new even though file existed on base.
            touched.append(raw)
            continue
        if _normalise(head_body) == _normalise(base_body):
            preexisting.append(raw)
        else:
            touched.append(raw)
    return touched, preexisting


def main() -> int:
    if len(sys.argv) < 3:
        print("Usage: filter_preexisting_methods.py BASE_REF LOG_FILE [LOG_FILE ...]", file=sys.stderr)
        return 2
    base_ref = sys.argv[1]
    # Sanity check: does the base ref exist?
    rev = subprocess.run(
        ["git", "-c", "safe.directory=*", "rev-parse", base_ref],
        capture_output=True,
        text=True,
        check=False,
        timeout=5,
    )
    if rev.returncode != 0:
        print(f"[filter-preexisting] base ref '{base_ref}' not available — skipping", file=sys.stderr)
        return 0
    for arg in sys.argv[2:]:
        log_path = Path(arg)
        if not log_path.exists() or log_path.stat().st_size == 0:
            continue
        try:
            touched, preexisting = _partition(base_ref, log_path)
        except Exception as exc:  # never block a gate on a filter failure
            print(f"[filter-preexisting] {log_path}: error {exc}", file=sys.stderr)
            continue
        log_path.write_text(("\n".join(touched) + "\n") if touched else "")
        if preexisting:
            preex_path = log_path.with_name(log_path.name + ".preexisting")
            preex_path.write_text("\n".join(preexisting) + "\n")
            print(
                f"[filter-preexisting] {log_path.name}: kept {len(touched)} PR-introduced, "
                f"moved {len(preexisting)} pre-existing → {preex_path.name}",
                file=sys.stderr,
            )
    return 0


if __name__ == "__main__":
    sys.exit(main())
