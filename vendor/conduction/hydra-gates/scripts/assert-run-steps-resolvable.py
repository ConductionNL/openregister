#!/usr/bin/env python3
"""Fail when a workflow carries a `run:` block big enough to make it unresolvable.

WHY
---
On 2026-08-04 a single `run:` step of ~22 KB in .github/workflows/quality.yml
made the whole workflow unresolvable (#161). Nothing went red. The fleet's
callers simply stopped producing a gates job, because an unresolvable reusable
workflow yields a run with NO JOBS rather than a failure. It was reverted in
#166 and re-landed in #168 with the script moved into a file.

The dynamic resolve probe catches this class after the fact, by counting jobs.
This lint catches the same class BEFORE merge, deterministically, and names the
offending step — which the job count cannot do.

THRESHOLD
---------
Measured on this repository:

  known-bad   ~22000 bytes   quality.yml at d68fb72, unresolvable (#161)
  current max  10533 bytes   quality.yml `run:` at line ~1127, resolves fine

16384 sits above every step the fleet actually ships and below the size that
is known to have broken it. It is a tripwire, not a style rule: a step
approaching it should move into a file under scripts/, exactly as #168 did.

Usage:  assert-run-steps-resolvable.py [--limit N] <workflow.yml> [...]
Exit:   0 all clear, 1 at least one step is over the limit.
"""

import argparse
import re
import sys

RUN_BLOCK = re.compile(r"^(\s*)run:\s*[|>]")


def oversized_steps(path, limit):
    """Yield (line_number, size) for every literal `run:` block over `limit`."""
    with open(path, encoding="utf-8") as handle:
        lines = handle.read().split("\n")

    findings = []
    index = 0
    while index < len(lines):
        match = RUN_BLOCK.match(lines[index])
        if not match:
            index += 1
            continue

        indent = len(match.group(1))
        start = index
        index += 1
        body = []
        # The block runs until a line that is neither blank nor more-indented
        # than the `run:` key itself. This is the same rule the YAML parser
        # uses for a literal scalar, and it is why blank lines cannot end it.
        while index < len(lines) and (
            lines[index].strip() == ""
            or len(lines[index]) - len(lines[index].lstrip()) > indent
        ):
            body.append(lines[index])
            index += 1

        size = len("\n".join(body))
        if size > limit:
            findings.append((start + 1, size))

    return findings


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--limit", type=int, default=16384)
    parser.add_argument("workflows", nargs="+")
    args = parser.parse_args()

    failed = False
    for path in args.workflows:
        for line, size in oversized_steps(path, args.limit):
            failed = True
            print(
                f"::error file={path},line={line}::`run:` block is {size} bytes, "
                f"over the {args.limit}-byte limit. A step this large is what made "
                f"quality.yml unresolvable in #161 — every caller produced ZERO jobs "
                f"and nothing went red. Move the script into a file under scripts/ "
                f"and call it, as #168 did."
            )
        print(f"checked {path}")

    if failed:
        return 1
    print(f"OK — every `run:` block is under {args.limit} bytes.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
