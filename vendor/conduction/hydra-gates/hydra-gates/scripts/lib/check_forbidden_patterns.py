#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""gate-2 forbidden-patterns — debug helpers that must not ship.

WHY THIS EXISTS
---------------
gate-2 was six `grep -rnE "\\b<name>\\("` passes over the raw bytes of lib/. Raw
bytes are the #184 mistake, and this gate failed in BOTH directions at once.
Every claim below was measured on larpingapp at gate package cdfbd7a.

FALSE NEGATIVES — real defects the gate could not see:

    var_dump ($value);   PASS   PHP permits whitespace between a function name
                                and its `(`. `\\bvar_dump\\(` requires them
                                adjacent, so the call is invisible.
    die;                 PASS   `die` is a LANGUAGE CONSTRUCT, not a function.
                                `die;` and `die "msg";` are legal and are the
                                commonest way to kill a request. The gate only
                                ever matched `die(`.
    exit;   exit(1);     PASS   `exit` is `die`'s exact synonym — the same
                                construct under a second spelling — and it was
                                not in the pattern list at all. So the gate
                                banned one name for a behaviour and left the
                                other wide open.

FALSE POSITIVES — correct code reported:

    // TODO: never use var_dump( here      FAIL   a comment WARNING against the
                                                  pattern counted as a use of
                                                  it (the gate-58 shape: the
                                                  better the documentation, the
                                                  redder the repo).
    $sql = "select dd(x)";                 FAIL   a STRING LITERAL counted.

Both directions have one cause and one fix: judge CODE, not text. The file is
read through `source_scope.php_mask(blank_strings=True)`, which blanks comment
and string CONTENTS while preserving offsets, so a line number computed on the
mask still names the right line of the original.

WHAT IS DELIBERATELY *NOT* CHANGED
----------------------------------
The five call-shaped names keep their original `\\bNAME\\s*\\(` matching,
including on `->dump(` / `::dump(`. Narrowing those to free functions would be a
FALSE-NEGATIVE change to a gate whose whole job is catching debug output, and
this change is already widening in the other direction. One direction at a time.

`die`/`exit` are matched as constructs — followed by `(`, `;` or a string — and
never when preceded by `$`, `->`, `::` or a word character, so `$exit`,
`$this->exit(` and `exit_code` are not findings.

ANTI-WIDENING: `: never` EXEMPTS, AND IT IS A TYPE, NOT A COMMENT
------------------------------------------------------------------
Adding `exit` produced exactly ONE new finding across fourteen fleet repos, and
it was correct code — openregister's SSE framing helper:

    protected function emitAndExit(string $eventType, array $payload): never
    {
        $this->emitSseEvent(...);
        $this->safeShutdown(...);
        exit;
    }

A function DECLARED `: never` is the language's own statement that it does not
return. `exit` there is the implementation of the declared contract, not stray
debug output. So `die`/`exit` inside a function whose declared return type is
`never` is exempt.

That discriminator is deliberately a TYPE and not a marker in prose. The gate
could have been taught to honour the `@SuppressWarnings(PHPMD.ExitExpression)`
docblock sitting above that same method, and it would have worked — but a
docblock is exactly the load-bearing prose that #196 and #184 were about, and
anyone could switch the gate off for a method by writing a sentence. `: never`
is checked by PHP itself and by every static analyser in `composer
check:strict`; it cannot be asserted falsely without the code failing to
typecheck.

Usage:  check_forbidden_patterns.py <php-file> [<php-file>...]
Prints `path:line: NAME — <source line>`; exit status is 0 regardless.
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    from source_scope import php_mask, read_text
except Exception:  # pragma: no cover
    php_mask = None
    read_text = None

# Debug helpers that are ordinary function calls. `\s*` because PHP allows
# whitespace before the argument list — the gap that made `var_dump (` invisible.
CALL_NAMES = ("var_dump", "print_r", "error_log", "dd", "dump")
_CALL_RX = re.compile(r"\b(" + "|".join(CALL_NAMES) + r")\s*\(")

# `die` / `exit` are language constructs: `die;`, `die('x')`, `exit;`, `exit(1)`.
# The negative lookbehind keeps `$exit`, `->exit(`, `::exit(` and `exit_code`
# out. `(?<![\w$])` covers the variable and identifier cases; the explicit
# `->`/`::` guard covers member access.
_CONSTRUCT_RX = re.compile(r"(?<![\w$])(?<!->)(?<!::)\b(die|exit)\b\s*(?=[(;'\"]|$)", re.M)


_FUNC_RX = re.compile(r"\bfunction\b\s*&?\s*(\w*)\s*\(")


def _never_spans(masked: str) -> list[tuple[int, int]]:
    """Offset ranges of functions DECLARED `: never`.

    Balanced-paren scan over the parameter list (defaults and nested calls make
    `\\([^)]*\\)` wrong), then read the return type between `)` and the body's
    `{`. The span runs to the matching `}` so a nested closure inside a `never`
    function is covered too.
    """
    spans: list[tuple[int, int]] = []
    n = len(masked)
    for m in _FUNC_RX.finditer(masked):
        i = m.end() - 1  # at '('
        depth = 0
        while i < n:
            if masked[i] == "(":
                depth += 1
            elif masked[i] == ")":
                depth -= 1
                if depth == 0:
                    break
            i += 1
        if i >= n:
            continue
        j = i + 1
        while j < n and masked[j] not in "{;":
            j += 1
        if j >= n or masked[j] == ";":
            continue  # abstract / interface declaration: no body
        if not re.search(r":\s*never\s*$", masked[i + 1 : j].strip() and masked[i + 1 : j]):
            continue
        depth = 0
        k = j
        while k < n:
            if masked[k] == "{":
                depth += 1
            elif masked[k] == "}":
                depth -= 1
                if depth == 0:
                    break
            k += 1
        spans.append((m.start(), k))
    return spans


def scan_file(path: str) -> list[str]:
    try:
        src = read_text(path)
    except OSError:
        return []
    masked = php_mask(src, blank_strings=True)
    lines = src.splitlines()
    never = _never_spans(masked)

    def in_never(off: int) -> bool:
        return any(a <= off <= b for a, b in never)

    hits: list[tuple[int, str]] = []
    for m in _CALL_RX.finditer(masked):
        line = masked.count("\n", 0, m.start()) + 1
        hits.append((line, m.group(1)))
    for m in _CONSTRUCT_RX.finditer(masked):
        if in_never(m.start()):
            continue
        line = masked.count("\n", 0, m.start()) + 1
        hits.append((line, m.group(1)))

    out = []
    for line, name in sorted(set(hits)):
        text = lines[line - 1].strip() if line - 1 < len(lines) else ""
        out.append(f"{path}:{line}: {name} — {text[:160]}")
    return out


def main(argv: list[str]) -> int:
    if php_mask is None or read_text is None:
        print(
            "check_forbidden_patterns: source_scope.py could not be imported; "
            "NOTHING was inspected",
            file=sys.stderr,
        )
        return 2
    if len(argv) < 2:
        print(__doc__, file=sys.stderr)
        return 2
    for path in argv[1:]:
        for finding in scan_file(path):
            print(finding)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
