#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-3 stub-scan — is a BackgroundJob's `run()` actually a stub?

WHY THIS EXISTS (#226)
----------------------
The gate counted surviving lines::

    _body=$(awk "/function run\\(/,/^    }/" "${job}" \\
      | grep -vE "^\\s*(//|\\*|\\s*\\{|\\s*\\}|\\s*$)" \\
      | grep -vE "function run|logger->(info|warning|debug|error|notice)|try\\s*\\{|\\}\\s*catch|return;?$")
    _lc=$(echo "${_body}" | grep -cE "\\S")
    [ "${_lc}" -lt 2 ] && echo "... run() body has no non-logger statements (stub)"

Measured on portaliq, `lib/BackgroundJob/NotificationDispatchJob.php` — 530
lines, 11 private methods, a complete notification pipeline — reported as a
stub. The filters strip `try {`, `} catch` and every logger call, so the
canonical fail-safe wrapper::

    protected function run($argument): void
    {
        try {
            $this->doRun(argument: $argument);
        } catch (Throwable $e) {
            $this->logger->error("...", ["reason" => $e->getMessage()]);
        }
    }

leaves EXACTLY ONE line, and the threshold is `< 2`.

THE ARITHMETIC, AND WHY BOTH DIRECTIONS ARE BROKEN
--------------------------------------------------
    A  genuine stub (logger->info() only)     0 lines   flagged   correct
    B  real fail-safe delegation to doRun()   1 line    FLAGGED   FALSE POSITIVE
    C  B plus one inert `$unused = 1;`        2 lines   passes    FALSE NEGATIVE

C is the real damage. The gate is CLOSED by adding a dead line and cannot be
closed by writing correct code. The two remedies available to a builder were
to pad the method with a no-op, or to inline the whole pipeline back into
`run()` — deleting the single try/catch that stops an exception escaping to
the NC cron runner. Both make the code worse, which is why portaliq reported
the finding instead of "fixing" it.

THE RULE HERE
-------------
Count CALLS, not lines. A `run()` body is implemented when it contains at
least one call that is not a logger call — `$this->doRun(...)`,
`$service->handle(...)`, a static call, a plain function call. It is a stub
when it contains none.

That flips B to clean (one delegating call IS the implementation, one level
down) and, just as importantly, keeps C flagged when the padding is all it
has: `$unused = 1;` is not a call, so a body of logger calls plus dead
assignments is still a stub. The gate stops being closable by a dead line.

Comments are removed before any of this, via source_scope.php_mask — a
`// $this->doRun();` left behind by someone mid-refactor is not an
implementation, and the old line filters counted `*`-prefixed lines out but
not a commented-out statement written at column 0.

Usage::

    check_stub_run_body.py <job-file> [<job-file>...]

Prints `path: run() body has no non-logger statements (stub)` per finding —
the same string the bash gate emitted. Exits 0 always (#209).
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from source_scope import php_mask, read_text  # noqa: E402

RUN_DECL = re.compile(
    r'(?:^|\n)[ \t]*(?:(?:public|protected|private|final|static)[ \t]+)*'
    r'function[ \t]+run[ \t]*\(',
)

# A logger call is a method from the PSR-3 vocabulary invoked on a receiver
# whose name ends in `logger` / `log`. Both halves are required: `$this->
# logger->error(...)` is discounted, `$mailer->error(...)` is not, and
# `$this->logger->rotate()` is a call to a logger that is not a log line.
LOGGER_METHODS = frozenset({
    'info', 'warning', 'warn', 'debug', 'error', 'notice',
    'critical', 'alert', 'emergency', 'log',
})
LOGGER_RECEIVER = re.compile(r'(?:logger|log)\s*$', re.IGNORECASE)

# Any call at all: `->m(`, `::m(`, `name(`. `function` / control keywords are
# excluded so `if (`, `foreach (`, `catch (` are not mistaken for work.
ANY_CALL = re.compile(
    r'(?:->|::)\s*[A-Za-z_]\w*\s*\('
    r'|(?<![>:\w$])[A-Za-z_]\w*\s*\('
)
NOT_A_CALL = frozenset({
    'if', 'elseif', 'else', 'while', 'for', 'foreach', 'switch', 'match',
    'catch', 'try', 'return', 'fn', 'function', 'echo', 'print', 'and', 'or',
    'xor', 'new', 'clone', 'throw', 'yield', 'array', 'list', 'isset',
    'unset', 'empty', 'exit', 'die',
})


def run_body(src: str) -> str | None:
    """The masked body of `run()`, or None when the file has no `run()`.

    Braces are matched rather than trusting `/^    }/`, which is an indentation
    guess: a job whose `run()` closes at a different indent had its body run to
    the end of the file.
    """
    # String CONTENTS are blanked too, so a `'}'` in a message cannot close
    # the body. Nothing below reads a string's value.
    masked = php_mask(src, blank_strings=True)
    m = RUN_DECL.search(masked)
    if not m:
        return None
    # Find the `{` that opens the body, skipping the parameter list and any
    # return type.
    i = masked.find('(', m.end() - 1)
    depth = 0
    while i < len(masked):
        if masked[i] == '(':
            depth += 1
        elif masked[i] == ')':
            depth -= 1
            if depth == 0:
                break
        i += 1
    brace = masked.find('{', i)
    semi = masked.find(';', i)
    if brace < 0 or (0 <= semi < brace):
        # Abstract or interface declaration — no body to judge.
        return None
    depth = 0
    j = brace
    while j < len(masked):
        if masked[j] == '{':
            depth += 1
        elif masked[j] == '}':
            depth -= 1
            if depth == 0:
                return masked[brace + 1:j]
        j += 1
    return masked[brace + 1:]


def _calls(body: str) -> list[str]:
    """Non-logger call sites in *body*."""
    out = []
    for m in ANY_CALL.finditer(body):
        text = m.group(0)
        name = re.sub(r'[\s(]+$', '', text.lstrip('->:').strip()).lower()
        if name in NOT_A_CALL:
            continue
        if name in LOGGER_METHODS and LOGGER_RECEIVER.search(body[:m.start()]):
            continue
        out.append(text)
    return out


def is_stub(src: str) -> bool | None:
    """True when `run()` does no non-logger work. None when there is no run()."""
    body = run_body(src)
    if body is None:
        return None
    return not _calls(body)


def scan_files(files: list[str]) -> list[str]:
    out = []
    for path in files:
        try:
            src = read_text(path)
        except OSError:
            continue
        verdict = is_stub(src)
        if verdict:
            out.append(f'{path}: run() body has no non-logger statements (stub)')
    return out


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("usage: check_stub_run_body.py <job-file>...", file=sys.stderr)
        return 2
    for line in scan_files(argv[1:]):
        print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
