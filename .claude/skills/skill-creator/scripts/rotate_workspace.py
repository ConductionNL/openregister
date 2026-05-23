#!/usr/bin/env python3
"""Rotate eval workspace: keep N live iterations, archive the rest.

Run at the end of every eval cycle. Enforces the invariant that no more than
``--keep`` ``iteration-N/`` directories exist directly under ``<workspace>/``.
For each iteration past the keep window, this script writes a compact summary
under ``<workspace>/archived-iterations/iteration-N/`` and then deletes the
original. Archive entries are 5-8x smaller than live iterations because:

  - per-run transcripts and outputs are NOT preserved (only "notable" ones)
  - the rendered eval-review HTML is NOT kept; its EMBEDDED_DATA JSON is
    extracted to ``embedded_data.json`` so the viewer can be re-rendered
    on demand

"Notable" detection (Option A + iteration-1 fallback):
  - For iteration 1 (no previous to compare against): keep any eval whose
    with_skill run failed at least one assertion.
  - For iteration N>1: keep any eval where (a) a with_skill assertion that
    passed in the previous iteration now fails, or (b) the with_skill
    pass_rate dropped vs. the previous iteration.

Usage:
    python -m scripts.rotate_workspace <workspace-path> [--keep 3] [--dry-run]
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
import sys
from datetime import date
from pathlib import Path
from typing import Optional


ITERATION_RE = re.compile(r"^iteration-(\d+)$")
HTML_DATA_RE = re.compile(r"const\s+EMBEDDED_DATA\s*=\s*(\{.*?\});\s*$", re.DOTALL | re.MULTILINE)


# ---------------------------------------------------------------------------
# Discovery
# ---------------------------------------------------------------------------


def list_iterations(workspace: Path) -> list[tuple[int, Path]]:
    """Live iterations directly under workspace, sorted ascending by N."""
    out = []
    for child in workspace.iterdir():
        if not child.is_dir():
            continue
        m = ITERATION_RE.match(child.name)
        if m:
            out.append((int(m.group(1)), child))
    out.sort(key=lambda x: x[0])
    return out


def list_archived(workspace: Path) -> list[int]:
    archive_root = workspace / "archived-iterations"
    if not archive_root.is_dir():
        return []
    out = []
    for child in archive_root.iterdir():
        if child.is_dir():
            m = ITERATION_RE.match(child.name)
            if m:
                out.append(int(m.group(1)))
    return sorted(out)


# ---------------------------------------------------------------------------
# Per-iteration data extraction
# ---------------------------------------------------------------------------


def load_grading(run_dir: Path) -> Optional[dict]:
    """Load grading.json for a single run directory (e.g., with_skill/run-1)."""
    g = run_dir / "grading.json"
    if not g.exists():
        return None
    try:
        return json.loads(g.read_text())
    except json.JSONDecodeError:
        return None


def load_timing(run_dir: Path) -> Optional[dict]:
    t = run_dir / "timing.json"
    if not t.exists():
        return None
    try:
        return json.loads(t.read_text())
    except json.JSONDecodeError:
        return None


def collect_eval_runs(eval_dir: Path, config: str) -> list[Path]:
    """Return sorted list of run-N directories under eval_dir/<config>/."""
    cfg_dir = eval_dir / config
    if not cfg_dir.is_dir():
        return []
    runs = [p for p in cfg_dir.iterdir() if p.is_dir() and re.match(r"^run-\d+$", p.name)]
    runs.sort(key=lambda p: int(p.name.split("-")[1]))
    return runs


def summarize_eval(eval_dir: Path) -> dict:
    """Compute a compact summary for one eval directory."""
    meta_file = eval_dir / "eval_metadata.json"
    meta = json.loads(meta_file.read_text()) if meta_file.exists() else {}
    eval_name = meta.get("eval_name", eval_dir.name)

    out: dict = {"eval_name": eval_name, "configs": {}}
    failed_assertion_texts: dict[str, set[str]] = {}

    for config in ("with_skill", "without_skill", "old_skill"):
        runs = collect_eval_runs(eval_dir, config)
        if not runs:
            continue
        passed = 0
        total = 0
        tokens: list[int] = []
        durations: list[int] = []
        failed: set[str] = set()
        for run in runs:
            grading = load_grading(run)
            if grading:
                for exp in grading.get("expectations", []):
                    total += 1
                    if exp.get("passed"):
                        passed += 1
                    else:
                        text = exp.get("text", "(unnamed)")
                        failed.add(text)
            timing = load_timing(run)
            if timing:
                if "total_tokens" in timing:
                    tokens.append(int(timing["total_tokens"]))
                if "duration_ms" in timing:
                    durations.append(int(timing["duration_ms"]))
        out["configs"][config] = {
            "runs": len(runs),
            "pass_rate": (passed / total) if total else None,
            "assertions_total": total,
            "assertions_passed": passed,
            "tokens_mean": (sum(tokens) // len(tokens)) if tokens else None,
            "duration_ms_mean": (sum(durations) // len(durations)) if durations else None,
        }
        failed_assertion_texts[config] = failed

    out["_failed_assertion_texts"] = {k: sorted(v) for k, v in failed_assertion_texts.items()}
    return out


def summarize_iteration(iter_dir: Path) -> dict:
    """Compute a full iteration summary by walking each eval-* subdir."""
    n = int(ITERATION_RE.match(iter_dir.name).group(1))
    evals = []
    for child in sorted(iter_dir.iterdir()):
        if child.is_dir() and child.name.startswith("eval-"):
            evals.append(summarize_eval(child))
    benchmark = None
    bf = iter_dir / "benchmark.json"
    if bf.exists():
        try:
            benchmark = json.loads(bf.read_text())
        except json.JSONDecodeError:
            pass
    return {"iteration": n, "evals": evals, "benchmark_present": benchmark is not None}


# ---------------------------------------------------------------------------
# Notable detection
# ---------------------------------------------------------------------------


def notable_evals(current: dict, previous: Optional[dict]) -> list[str]:
    """Return list of eval_name values whose outputs should be kept in notable/."""
    notables: list[str] = []
    prev_by_name = {e["eval_name"]: e for e in (previous or {}).get("evals", [])}
    for eval_summary in current.get("evals", []):
        name = eval_summary["eval_name"]
        ws = eval_summary.get("configs", {}).get("with_skill")
        if not ws:
            continue
        failed_now = set(eval_summary["_failed_assertion_texts"].get("with_skill", []))
        if previous is None:
            # Iteration 1 fallback: keep if any with_skill assertion failed.
            if failed_now:
                notables.append(name)
            continue
        prev = prev_by_name.get(name)
        if not prev:
            # New eval — treat like iteration-1 case.
            if failed_now:
                notables.append(name)
            continue
        prev_failed = set(prev["_failed_assertion_texts"].get("with_skill", []))
        # Regression: assertion that passed before now fails.
        passed_to_failed = failed_now - prev_failed
        prev_pr = (prev.get("configs", {}).get("with_skill") or {}).get("pass_rate")
        cur_pr = ws.get("pass_rate")
        pass_rate_dropped = (
            prev_pr is not None and cur_pr is not None and cur_pr < prev_pr
        )
        if passed_to_failed or pass_rate_dropped:
            notables.append(name)
    return notables


# ---------------------------------------------------------------------------
# HTML EMBEDDED_DATA extraction
# ---------------------------------------------------------------------------


def extract_embedded_data(html_path: Path) -> Optional[dict]:
    if not html_path.exists():
        return None
    try:
        text = html_path.read_text()
    except UnicodeDecodeError:
        return None
    m = HTML_DATA_RE.search(text)
    if not m:
        return None
    try:
        return json.loads(m.group(1))
    except json.JSONDecodeError:
        return None


# ---------------------------------------------------------------------------
# Dedup against prior archives
# ---------------------------------------------------------------------------


def find_existing_notable(archive_root: Path, eval_name: str, failed_set: set[str], before_n: int) -> Optional[int]:
    """Find the most recent archived iteration < before_n whose summary records
    the same eval_name as notable with an identical with_skill failed-assertion
    signature. Returns the iteration number or None."""
    if not archive_root.is_dir():
        return None
    candidates: list[int] = []
    for child in archive_root.iterdir():
        m = ITERATION_RE.match(child.name) if child.is_dir() else None
        if not m:
            continue
        cn = int(m.group(1))
        if cn >= before_n:
            continue
        sf = child / "summary.json"
        if not sf.exists():
            continue
        try:
            data = json.loads(sf.read_text())
        except json.JSONDecodeError:
            continue
        if eval_name not in data.get("notable", []):
            continue
        for ev in data.get("evals", []):
            if ev.get("eval_name") != eval_name:
                continue
            prev_failed = set((ev.get("failed_assertions") or {}).get("with_skill", []))
            if prev_failed == failed_set:
                candidates.append(cn)
            break
    return max(candidates) if candidates else None


# ---------------------------------------------------------------------------
# Archive writer
# ---------------------------------------------------------------------------


def write_summary_json(target: Path, summary: dict, notables: list[str]) -> None:
    """Strip internal fields and write summary.json."""
    clean = {
        "iteration": summary["iteration"],
        "archived_at": date.today().isoformat(),
        "evals": [],
        "notable": notables,
    }
    for e in summary["evals"]:
        clean_eval = {"eval_name": e["eval_name"], "configs": e["configs"]}
        if e["_failed_assertion_texts"]:
            clean_eval["failed_assertions"] = {
                k: v for k, v in e["_failed_assertion_texts"].items() if v
            }
        clean["evals"].append(clean_eval)
    # Headline + regression lines
    headlines = []
    for e in clean["evals"]:
        ws = e.get("configs", {}).get("with_skill")
        if ws and ws.get("pass_rate") is not None:
            headlines.append(f"{e['eval_name']}: with_skill pass_rate={ws['pass_rate']:.2f}")
    clean["headline"] = "; ".join(headlines) if headlines else None
    target.write_text(json.dumps(clean, indent=2))


def archive_iteration(iter_dir: Path, archive_root: Path, summary: dict, notables: list[str], dry_run: bool) -> Path:
    n = summary["iteration"]
    target = archive_root / f"iteration-{n}"
    if dry_run:
        print(f"  [dry-run] would archive {iter_dir} → {target}")
        return target
    target.mkdir(parents=True, exist_ok=True)

    # Copy benchmark files verbatim.
    for fname in ("benchmark.json", "benchmark.md"):
        src = iter_dir / fname
        if src.exists():
            shutil.copy2(src, target / fname)

    # Extract EMBEDDED_DATA from the iteration's HTML viewer (if present).
    workspace = iter_dir.parent
    html_candidates = [
        workspace / f"eval-review-iteration-{n}.html",
        iter_dir / f"eval-review-iteration-{n}.html",
        iter_dir / "eval-review.html",
    ]
    for html in html_candidates:
        data = extract_embedded_data(html)
        if data is not None:
            (target / "embedded_data.json").write_text(json.dumps(data, indent=2))
            break

    # Copy notable eval outputs (full, no truncation — already a small subset).
    # Dedup against prior archived iterations: if an earlier archive entry has
    # the SAME eval_name with an identical failed-assertion signature, write a
    # tiny pointer file instead of copying the outputs again.
    if notables:
        notable_dir = target / "notable"
        notable_dir.mkdir(exist_ok=True)
        eval_summary_by_name = {e["eval_name"]: e for e in summary["evals"]}
        for eval_name in notables:
            eval_src = iter_dir / eval_name
            if not eval_src.is_dir():
                continue
            eval_summary = eval_summary_by_name.get(eval_name, {})
            failed_set = set(eval_summary.get("_failed_assertion_texts", {}).get("with_skill", []))
            existing = find_existing_notable(archive_root, eval_name, failed_set, before_n=n)
            dest = notable_dir / eval_name
            if existing is not None:
                dest.mkdir(exist_ok=True)
                pointer = {
                    "same_as_iteration": existing,
                    "reason": "identical with_skill failed-assertion signature",
                    "failed_assertions": sorted(failed_set),
                }
                (dest / "same_as.json").write_text(json.dumps(pointer, indent=2))
            else:
                shutil.copytree(eval_src, dest, dirs_exist_ok=True)

    # Write the compact summary last (acts as a "completed" marker).
    write_summary_json(target / "summary.json", summary, notables)
    return target


def remove_iteration(iter_dir: Path, dry_run: bool) -> None:
    if dry_run:
        print(f"  [dry-run] would remove {iter_dir}")
        return
    shutil.rmtree(iter_dir)
    # Also remove the matching HTML viewer at workspace level, if any.
    workspace = iter_dir.parent
    n = int(ITERATION_RE.match(iter_dir.name).group(1))
    html = workspace / f"eval-review-iteration-{n}.html"
    if html.exists():
        html.unlink()


# ---------------------------------------------------------------------------
# Index updater
# ---------------------------------------------------------------------------


def rebuild_index(archive_root: Path) -> None:
    """Refresh INDEX.md and index.json from the summaries currently on disk."""
    if not archive_root.is_dir():
        return
    entries = []
    for child in sorted(archive_root.iterdir(), key=lambda p: int(ITERATION_RE.match(p.name).group(1)) if ITERATION_RE.match(p.name) else -1):
        m = ITERATION_RE.match(child.name)
        if not (child.is_dir() and m):
            continue
        sf = child / "summary.json"
        if not sf.exists():
            continue
        try:
            entries.append(json.loads(sf.read_text()))
        except json.JSONDecodeError:
            continue

    (archive_root / "index.json").write_text(json.dumps(entries, indent=2))

    lines = ["# Archived iterations\n",
             "Compact summaries of iterations rotated out of the live workspace.",
             "Re-render the viewer for any iteration with `eval-viewer/generate_review.py` against `embedded_data.json`.\n",
             "| Iteration | Archived | Evals | with_skill pass rates | Notable |",
             "|---:|---|---:|---|---|"]
    for e in entries:
        n = e.get("iteration")
        when = e.get("archived_at", "")
        ev_count = len(e.get("evals", []))
        prs = []
        for ev in e.get("evals", []):
            ws = (ev.get("configs") or {}).get("with_skill") or {}
            pr = ws.get("pass_rate")
            if pr is not None:
                prs.append(f"{ev['eval_name']}={pr:.2f}")
        notable = ", ".join(e.get("notable", [])) or "—"
        lines.append(f"| {n} | {when} | {ev_count} | {'; '.join(prs) or '—'} | {notable} |")
    (archive_root / "INDEX.md").write_text("\n".join(lines) + "\n")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------


def load_previous_summary(workspace: Path, n: int) -> Optional[dict]:
    """Load the summary for iteration n, looking in live or archived dirs."""
    live_dir = workspace / f"iteration-{n}"
    if live_dir.is_dir():
        return summarize_iteration(live_dir)
    archived = workspace / "archived-iterations" / f"iteration-{n}" / "summary.json"
    if archived.exists():
        try:
            data = json.loads(archived.read_text())
            # Reattach the internal field shape so notable_evals() can consume it.
            for ev in data.get("evals", []):
                ev["_failed_assertion_texts"] = ev.get("failed_assertions", {})
            return data
        except json.JSONDecodeError:
            return None
    return None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("workspace", help="Path to <skill>/evals/workspace/")
    parser.add_argument("--keep", type=int, default=3, help="Number of live iterations to keep (default: 3)")
    parser.add_argument("--dry-run", action="store_true", help="Print what would happen, change nothing")
    args = parser.parse_args()

    workspace = Path(args.workspace).resolve()
    if not workspace.is_dir():
        print(f"ERROR: workspace not found: {workspace}", file=sys.stderr)
        return 1

    iterations = list_iterations(workspace)
    if len(iterations) <= args.keep:
        print(f"==> {len(iterations)} live iteration(s); keep window is {args.keep}. Nothing to rotate.")
        return 0

    to_archive = iterations[: len(iterations) - args.keep]
    print(f"==> Live iterations: {[n for n, _ in iterations]}")
    print(f"==> Will archive: {[n for n, _ in to_archive]}  (keeping last {args.keep})")

    archive_root = workspace / "archived-iterations"
    if not args.dry_run:
        archive_root.mkdir(exist_ok=True)

    for n, iter_dir in to_archive:
        summary = summarize_iteration(iter_dir)
        previous = load_previous_summary(workspace, n - 1)
        notables = notable_evals(summary, previous)
        print(f"\n==> iteration-{n}: {len(summary['evals'])} eval(s); notable={notables or 'none'}")
        archive_iteration(iter_dir, archive_root, summary, notables, args.dry_run)
        remove_iteration(iter_dir, args.dry_run)

    if not args.dry_run:
        rebuild_index(archive_root)
        print(f"\n==> Refreshed {archive_root}/INDEX.md and index.json")

    return 0


if __name__ == "__main__":
    sys.exit(main())
