#!/usr/bin/env python3
"""body.py <app> [verify-notes-file]: print the bundle PR body from <app>.applied.tsv."""
import os, sys

app = sys.argv[1]
D = os.environ.get("DEPBUNDLE_DIR", "/tmp/lanes/depbundle")
rows = [l.rstrip("\n").split("\t") for l in open(f"{D}/{app}.applied.tsv") if l.strip()]
done = [r for r in rows if r[7].startswith("applied")]
already = [r for r in rows if r[7].startswith("already")]
skipped = [r for r in rows if not (r[7].startswith("applied") or r[7].startswith("already"))]
replaced = sorted({r[0] for r in done + already} - {r[0] for r in skipped}, key=int)
out = ["Bundles the open dependabot updates on `development` into one PR, with `sbom.cdx.json` regenerated "
       "by `regen-sbom.sh` (the recipe of the `sbom` job), so the lockfile and SBOM land together.", "",
       "## Updates", "", "| Package | From | To | Dependabot PR |", "|---|---|---|---|"]
for r in done:
    to = r[5]
    if "(" in r[7]:
        to += f" (resolved {r[7].split('(')[1].rstrip(')')})"
    out.append(f"| `{r[3]}` | {r[4]} | {to} | #{r[0]} |")
if already:
    out += ["", "Already on `development` at or above the target (PR is superseded):", ""]
    out += [f"- `{r[3]}` {r[5]}: {r[7].split(': ', 1)[-1]} (#{r[0]})" for r in already]
out += ["", "## Replaces", "", " ".join(f"#{n}" for n in replaced) or "none", ""]
if skipped:
    out += ["## Skipped (dependabot PR stays open)", ""]
    out += [f"- #{r[0]} `{r[3]}` {r[4]} -> {r[5]}: {r[7].split(': ', 1)[-1]}" for r in skipped]
    out.append("")
if len(sys.argv) > 2:
    out += ["## Verified locally", "", open(sys.argv[2]).read().strip(), ""]
out += ["Assisted by Claude Code (claude-opus-5-5); reviewed and verified (unit suites, merge gate) before merge."]
print("\n".join(out))
