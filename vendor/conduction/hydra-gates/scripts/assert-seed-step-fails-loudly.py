#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Execute the real "Seed test data" `run:` bodies and prove a failing seed fails.

WHY
---
`playwright-seed-command` was interpolated into the step body unquoted::

    echo "Running seed command: ${{ inputs.playwright-seed-command }}"
    eval ${{ inputs.playwright-seed-command }}
    echo "Seed command completed."

`${{ }}` is a TEXTUAL substitution applied before bash sees the script, so a
seed of ``a && b && c`` became ``eval a && b && c`` — three commands in an
``&&`` list, with ``eval`` receiving only the first stage. Bash's ``set -e``
exemption for ``&&`` lists ("except the command following the final ``&&``")
then meant a failing first stage aborted nothing, the tail never ran, and the
step exited 0 while printing ``Seed command completed.`` Playwright went on to
test a half-seeded instance and produced a full, credible-looking tally
(#192; measured on pipelinq run 30800304506).

WHAT THIS ASSERTS
-----------------
This does not lint the YAML for a pattern. It EXTRACTS each ``Seed test data``
step's ``run:`` body out of the workflow and RUNS it, under the same shell
GitHub uses (``bash -e <file>``; quality.yml declares no ``shell:``, so the
platform default applies), with a battery of seeds:

  * a failing ``&&`` chain            -> must exit non-zero, must not run the
                                         tail, must not claim completion, and
                                         must name the cause on STDERR
  * a legitimate multi-stage seed     -> must still exit 0 with every stage run
  * an assignment prefix + ``|| true``-> must still work (launchpad ships this)
  * a seed containing ``$(…)``        -> must be announced LITERALLY, not
                                         expanded by the announcing shell
  * an explicit ``exit 42``           -> the step's exit status must be 42

Running the shipped text means the assertions cannot drift from the workflow.

POSITIVE CONTROL
----------------
``--positive-control`` runs the identical battery against the PRE-FIX body,
reconstructed verbatim, with ``${{ inputs.playwright-seed-command }}``
substituted textually exactly as GitHub would. That body MUST fail the battery.
If it passes, the battery is measuring nothing and this script exits 1 — a
check that cannot fail is not a check.

Usage::

    assert-seed-step-fails-loudly.py .github/workflows/quality.yml
    assert-seed-step-fails-loudly.py --positive-control
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

STEP_NAME = "Seed test data"

# The step exactly as it stood before #192 was fixed: no `env:` at all, the
# input interpolated straight into the script body. Used only by
# --positive-control, where it must FAIL the battery below.
PRE_FIX_STEP = (
    {},
    "cd server\n"
    'echo "Running seed command: ${{ inputs.playwright-seed-command }}"\n'
    "eval ${{ inputs.playwright-seed-command }}\n"
    'echo "Seed command completed."\n',
)

# Both seed inputs. The Newman job has a third `Seed test data` step reading
# `newman-seed-command`; it had the identical defect and openbuild ships an
# `&&` chain to it, so the battery must cover it too.
_INTERPOLATION = re.compile(
    r"\$\{\{\s*inputs\.(?:playwright|newman)-seed-command\s*\}\}"
)


# ---------------------------------------------------------------------------
# Extraction
# ---------------------------------------------------------------------------


def extract_seed_steps(path: Path) -> list[tuple[int, dict, str]]:
    """Every ``Seed test data`` step, as ``(line, env_mapping, run_body)``.

    THE ``env:`` BLOCK IS PART OF THE SUBJECT, not scaffolding. The fix moves
    the seed out of the script body and into ``SEED_COMMAND:`` under ``env:``;
    a harness that supplied ``SEED_COMMAND`` itself would keep passing if that
    line were deleted, and would be testing a step the workflow does not
    contain. So the environment is read from the file too.
    """
    lines = path.read_text(encoding="utf-8").split("\n")
    found: list[tuple[int, dict, str]] = []

    i = 0
    while i < len(lines):
        m = re.match(r"^(\s*)-\s+name:\s*(.+?)\s*$", lines[i])
        if not m or m.group(2).strip("'\"") != STEP_NAME:
            i += 1
            continue

        step_indent = len(m.group(1))
        i += 1
        env: dict = {}
        body_text: str | None = None
        run_line = 0
        # Walk the step's own keys until the next list item at the same indent
        # or any dedent below it.
        while i < len(lines):
            line = lines[i]
            if line.strip() == "":
                i += 1
                continue
            indent = len(line) - len(line.lstrip())
            if indent <= step_indent:
                break

            env_m = re.match(r"^(\s*)env:\s*$", line)
            if env_m:
                key_indent = len(env_m.group(1))
                i += 1
                while i < len(lines):
                    ln = lines[i]
                    if ln.strip() == "":
                        i += 1
                        continue
                    if len(ln) - len(ln.lstrip()) <= key_indent:
                        break
                    if ln.lstrip().startswith("#"):
                        i += 1
                        continue
                    kv = re.match(r"^\s*([A-Za-z_][A-Za-z0-9_]*):\s*(.*?)\s*$", ln)
                    if kv:
                        env[kv.group(1)] = _unquote(kv.group(2))
                    i += 1
                continue

            run_m = re.match(r"^(\s*)run:\s*[|>]", line)
            if run_m:
                key_indent = len(run_m.group(1))
                run_line = i + 1
                i += 1
                body: list[str] = []
                while i < len(lines) and (
                    lines[i].strip() == ""
                    or len(lines[i]) - len(lines[i].lstrip()) > key_indent
                ):
                    body.append(lines[i])
                    i += 1
                body_text = _dedent(body)
                continue
            i += 1

        if body_text is not None:
            found.append((run_line, env, body_text))

    return found


def _unquote(value: str) -> str:
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "'\"":
        return value[1:-1]
    return value


def _dedent(body: list[str]) -> str:
    """Strip the common leading indentation of a YAML literal block."""
    widths = [
        len(ln) - len(ln.lstrip()) for ln in body if ln.strip() != ""
    ]
    pad = min(widths) if widths else 0
    return "\n".join(ln[pad:] if ln.strip() else "" for ln in body) + "\n"


# ---------------------------------------------------------------------------
# Execution
# ---------------------------------------------------------------------------


class Result:
    def __init__(self, rc: int, out: str, err: str, sentinels: set[str]):
        self.rc = rc
        self.out = out
        self.err = err
        # Files the seed created. "Did the tail run?" MUST be answered by a
        # side effect, not by grepping the log: the fixed step echoes the seed
        # text back in its `::error::` line, so any marker word chosen for the
        # tail also appears in the diagnostic. Grepping for it reported "the
        # tail ran" on a step where it demonstrably had not — a check that can
        # match the wrong element is not a check.
        self.sentinels = sentinels

    @property
    def both(self) -> str:
        return self.out + self.err


def run_step(step: tuple[dict, str], seed: str) -> Result:
    """Run the step the way GitHub would, with *seed* as the caller's input.

    ``${{ inputs.<x>-seed-command }}`` is substituted TEXTUALLY in BOTH the
    ``env:`` values and the ``run:`` body, because that is what the expression
    engine does and the textual substitution into the body is the whole
    mechanism of the defect. Nothing is injected that the workflow does not
    itself declare — if the ``SEED_COMMAND:`` env line were removed, the seed
    would simply not arrive, and the battery must notice.
    """
    step_env, body = step
    script = _INTERPOLATION.sub(lambda _m: seed, body)
    with tempfile.TemporaryDirectory() as tmp:
        # The bodies begin with `cd server`.
        server = Path(tmp) / "server"
        server.mkdir()
        script_path = Path(tmp) / "step.sh"
        script_path.write_text(script, encoding="utf-8")
        env = dict(os.environ)
        env.pop("SEED_COMMAND", None)
        for key, value in step_env.items():
            env[key] = _INTERPOLATION.sub(lambda _m: seed, value)
        # quality.yml declares no `shell:` for these steps, so GitHub's default
        # applies: `bash -e {0}` — a FILE, not `-c`, and without `pipefail`.
        proc = subprocess.run(
            ["bash", "-e", str(script_path)],
            cwd=tmp,
            env=env,
            capture_output=True,
            text=True,
            timeout=60,
        )
        sentinels = {
            p.name
            for p in server.iterdir()
            if p.name.startswith("sentinel-")
        }
    return Result(proc.returncode, proc.stdout, proc.stderr, sentinels)


# ---------------------------------------------------------------------------
# The battery
# ---------------------------------------------------------------------------


def check_step(step: tuple[dict, str], label: str) -> list[str]:
    """Return a list of failure strings; empty means the step is sound."""
    bad: list[str] = []

    def fail(case: str, why: str) -> None:
        bad.append(f"{label}: [{case}] {why}")

    # -- 0. CONTROL FOR CASE 1. The sentinel mechanism must be able to fire,
    #    otherwise "the tail did not run" is indistinguishable from "the
    #    sentinel check is broken" and case 1 would pass on a dead instrument.
    #    This also proves the seed REACHES the step at all: if the
    #    `SEED_COMMAND:` env line were dropped, nothing would run and every
    #    "did not happen" assertion below would pass vacuously.
    r = run_step(step, "true && touch sentinel-tail")
    if "sentinel-tail" not in r.sentinels:
        fail(
            "sentinel-control",
            "a seed that DOES reach its tail left no sentinel — either the "
            "seed never reached the step, or the tail-ran detector below is "
            "dead. Either way its verdict means nothing.",
        )

    # -- 1. A failing && chain must fail the job, loudly. ------------------
    r = run_step(step, "false && touch sentinel-tail && echo second")
    if r.rc == 0:
        fail(
            "failing-&&-chain",
            "exited 0. A seed whose first stage fails MUST fail the step; this "
            "is the #192 defect — bash's set -e exemption for && lists.",
        )
    if "sentinel-tail" in r.sentinels:
        fail("failing-&&-chain", "the tail ran after a failed first stage.")
    if "Seed command completed" in r.both:
        fail(
            "failing-&&-chain",
            "printed a completion message for a seed that did not complete.",
        )
    if "::error::" not in r.err:
        fail(
            "failing-&&-chain",
            "no `::error::` on STDERR. A caller capturing this step with $(…) "
            "would see a bare non-zero exit and no stated cause.",
        )
    if "::error::" in r.out:
        fail(
            "failing-&&-chain",
            "wrote `::error::` to STDOUT; diagnostics must go to stderr.",
        )

    # -- 2. A legitimate multi-stage seed must still succeed, SILENTLY. ----
    #    The "no ::error:: on success" half is not decoration. An always-true
    #    failure predicate (`if true; then … exit "$SEED_RC"`) still exits 0
    #    on a good seed and non-zero on a bad one, so every other assertion
    #    here passes — while the log cries `::error::Seed command failed with
    #    exit 0` on every green run. A gate that alarms unconditionally is on
    #    its way to being ignored unconditionally.
    r = run_step(step, "echo STAGE_ONE && echo STAGE_TWO && echo STAGE_THREE")
    if r.rc != 0:
        fail("legit-&&-chain", f"a valid multi-stage seed failed with {r.rc}.")
    for stage in ("STAGE_ONE", "STAGE_TWO", "STAGE_THREE"):
        if stage not in r.both:
            fail("legit-&&-chain", f"{stage} never ran.")
    if "::error::" in r.both:
        fail(
            "legit-&&-chain",
            "a SUCCESSFUL seed emitted `::error::`. The failure branch is "
            "firing unconditionally.",
        )

    # -- 3. Shell syntax the fleet actually ships must keep working. -------
    #    launchpad: `OC_PASS=… php occ user:add … || true`
    #    openconnector (newman): `SEED_SCOPE=register bash …/ci-seed.sh`
    r = run_step(step, "OC_SEED_VAR=works sh -c 'echo prefix-$OC_SEED_VAR' || true")
    if r.rc != 0:
        fail("assignment-prefix-and-||", f"exited {r.rc}; eval is still required.")
    if "prefix-works" not in r.both:
        fail("assignment-prefix-and-||", "the assignment prefix was lost.")

    # -- 4. A seed containing quotes must not reshape the script. ----------
    r = run_step(step, "printf '%s\\n' \"quoted seed ok\"")
    if r.rc != 0:
        fail("quoted-seed", f"exited {r.rc}.")
    if "quoted seed ok" not in r.both:
        fail("quoted-seed", "did not run.")

    # -- 4b. The seed must reach eval BYTE-FOR-BYTE. -----------------------
    #    This is what the quoting on `eval "$SEED_COMMAND"` buys. Unquoted,
    #    the expansion is word-split and glob-expanded first, and eval then
    #    rejoins the words with single spaces — so runs of whitespace inside
    #    the seed's own quotes are silently collapsed and a `*` in the seed
    #    is replaced by whatever happens to be in the working directory.
    r = run_step(step, "printf '[%s]\\n' 'a   b'")
    if "[a   b]" not in r.both:
        fail(
            "seed-arrives-verbatim",
            "the seed was word-split before eval saw it — internal whitespace "
            "did not survive. Quote the expansion.",
        )

    # -- 5. The announcement must not EXPAND the seed. ---------------------
    #    pipelinq's control printed identical before/after byte counts because
    #    `echo "… ${{ … }}"` expanded the seed's own $(…) in the ANNOUNCING
    #    shell, before the seed ran.
    r = run_step(step, "echo ran-for-real-$(echo 1)")
    if "$(echo 1)" not in r.both:
        fail(
            "announcement-must-not-expand",
            "the seed was announced with its $(…) already expanded, so a "
            "readback written into the seed cannot observe its own effect.",
        )

    # -- 6. The step's exit status must be the seed's. ---------------------
    r = run_step(step, "exit 42")
    if r.rc != 42:
        fail("exit-status-propagates", f"seed exited 42, step exited {r.rc}.")

    return bad


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("workflows", nargs="*")
    ap.add_argument(
        "--positive-control",
        action="store_true",
        help="run the battery against the KNOWN-BROKEN pre-#192 body; it must fail.",
    )
    args = ap.parse_args()

    if args.positive_control:
        bad = check_step(PRE_FIX_STEP, "pre-fix step")
        if not bad:
            print(
                "::error::the battery reported the PRE-FIX seed step as sound. "
                "That step is the #192 defect verbatim — if it passes, these "
                "assertions are not measuring anything and their verdict on the "
                "real workflow is worthless."
            )
            return 1
        print("OK — the battery fails on the known-bad step. It can fail:")
        for line in bad:
            print(f"  would-flag: {line}")
        return 0

    if not args.workflows:
        print("::error::no workflow given.")
        return 1

    total = 0
    failures: list[str] = []
    for path_s in args.workflows:
        path = Path(path_s)
        steps = extract_seed_steps(path)
        # ASSERT THE INPUT IS NON-EMPTY. A parser that silently stopped
        # matching would find zero steps and report a clean pass forever —
        # the difference between "all sound" and "measured nothing" is not
        # visible in the exit code unless it is asserted here.
        if not steps:
            print(
                f"::error file={path_s}::found NO step named '{STEP_NAME}'. Either "
                f"the step was renamed or this extractor stopped matching; either "
                f"way the seed contract is now unchecked."
            )
            return 1
        for line, env, body in steps:
            total += 1
            label = f"{path_s}:{line}"
            print(f"exercising {label}")
            failures.extend(check_step((env, body), label))

    if failures:
        for line in failures:
            print(f"::error::{line}")
        return 1

    print(f"OK — {total} 'Seed test data' step(s) fail loudly on a failing seed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
