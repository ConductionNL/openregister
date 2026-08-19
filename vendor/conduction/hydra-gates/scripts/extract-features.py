#!/usr/bin/env python3
"""
Extract an app's commercial capability list into a single docs/features.json,
consumed at runtime by the Features & Roadmap surface and at build time by the
Docusaurus `/features` page.

Two sources, in priority order:

1. **Commercial overlay** — `openspec/features.overlay.json` (optional). When
   present it is the AUTHORITATIVE, curated feature list for the public page:
   honest maturity, brand-voiced NL/EN benefit copy, roadmap rows, and
   capabilities provided by another app (e.g. OpenRegister's AI assistants and
   content leaves surfaced through a consuming app). This is the recommended
   source for any app that is actually sold — the spec frontmatter is an
   engineering lifecycle signal, not customer copy.

2. **Spec derivation** (fallback) — `openspec/specs/<slug>/spec.md`, one per
   capability. Hardened: internal/process specs (refactor, migration, test,
   docs, plumbing, ...) are filtered out, engineering artifacts (`@e2e`,
   `#<pr>`, `Spec:`, em-dashes, `TBD`) are stripped from titles and summaries,
   and optional frontmatter fields (`commercial`, `maturity`, `title`,
   `summary`, `provided-by`, `*_nl`) let a spec author curate without an
   overlay. Status maps to one of three buyer-facing maturities.

Maturity model (exactly three), rendered as the hex bullet colour:
  stable (mint)  — shipped, has a UI/API a buyer can use today.
  beta   (blue)  — works but partial, admin-only, or depends on an external
                   provider being configured.
  soon   (orange)— honest roadmap; not usable yet.

Each emitted entry: { slug, title, summary, status, docsUrl } plus optional
{ providedBy, title_nl, summary_nl } when curated. Entries are deterministic
(overlay order preserved; spec-derived sorted by slug) with stable JSON
formatting so the workflow only commits on real content changes.

This mirrors the docusaurus-preset `extractFeatures.js` so the docs build and
CI agree. Exits 0 on success, 1 on missing dependencies, 2 on usage/IO errors.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path


# Fallback map: a spec's frontmatter lifecycle status -> buyer-facing maturity.
# Used only when the spec has no explicit `maturity:` and the app has no
# overlay. Statuses that map to None are skipped (retired/deprecated/etc.).
STATUS_KIND = {
    "done": "stable", "implemented": "stable", "reviewed": "stable",
    "active": "stable", "stable": "stable",
    "in-progress": "beta", "implementing": "beta", "partial": "beta", "beta": "beta",
    "draft": "soon", "specified": "soon", "proposed": "soon", "planned": "soon",
    "coming-soon": "soon", "soon": "soon",
}

VALID_MATURITY = frozenset({"stable", "beta", "soon"})

# Tokens to upper-case when title-casing a raw slug (e.g. launchpad-ai -> AI).
ACRONYMS = frozenset({
    "ai", "api", "ui", "ux", "or", "bi", "mcp", "tmlo", "mdto", "dcat", "woo",
    "vth", "kcc", "crm", "pdf", "csv", "sepa", "zgw", "ztc", "dso", "rbac",
    "gdpr", "avg", "kvk", "brp", "sso", "jwt", "cli", "ocr", "ner", "kpi",
    "saas", "oas", "json", "xml", "html", "css", "url", "http", "https", "id",
    "pwa", "sip", "eml", "sla", "llm", "rag", "e2e", "qr", "vng",
})

# A slug whose hyphen segments include any of these is an internal/process
# spec, not a commercial feature, and is filtered from the public page unless
# the spec sets `commercial: true` (or an overlay re-includes it).
INTERNAL_SEGMENTS = frozenset({
    "refactor", "refactoring", "migrate", "migration", "migrations", "migrated",
    "test", "tests", "testing", "e2e", "ci", "cd", "docs", "documentation",
    "manifest", "plumbing", "scaffold", "scaffolding", "bootstrap", "chore",
    "cleanup", "rename", "renaming", "bump", "deps", "dependency",
    "dependencies", "lint", "linting", "coverage", "gate", "gates", "seed",
    "seeding", "fixture", "fixtures", "spike", "poc", "tooling", "devx",
    "internal",
})

FRONTMATTER_RE = re.compile(r"^---\s*\n(.*?\n)---\s*\n(.*)\Z", re.DOTALL)
H1_RE = re.compile(r"^#\s+(.+?)\s*$", re.MULTILINE)
PURPOSE_RE = re.compile(r"^##\s+Purpose\s*\n(.+?)(?=\n##\s|\Z)", re.DOTALL | re.MULTILINE)
SLUGGY_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)+$")


def _field(front: str, key: str) -> str | None:
    """Read a single frontmatter scalar off its line, tolerant of invalid YAML
    elsewhere in the block (one bad unquoted colon must not drop the spec)."""
    m = re.search(rf"^{re.escape(key)}:\s*(.+?)\s*$", front, re.MULTILINE)
    if m is None:
        return None
    return m.group(1).strip().strip("\"'")


def _bool_field(front: str, key: str) -> bool | None:
    raw = _field(front, key)
    if raw is None:
        return None
    return raw.strip().lower() in ("true", "yes", "1")


def titlecase_slug(slug: str) -> str:
    """Turn a kebab slug into a human title, upper-casing known acronyms."""
    return " ".join(
        word.upper() if word in ACRONYMS else word.capitalize()
        for word in slug.split("-")
    )


def clean_title(raw_title: str, slug: str) -> str:
    """Strip 'Spec:'/'Delta Spec:' prefixes and 'Specification' suffixes; fall
    back to a title-cased slug when the H1 is missing or is itself a raw slug."""
    title = re.sub(r"^\s*(?:delta\s+)?spec:\s*", "", raw_title, flags=re.IGNORECASE)
    title = re.sub(r"\s+specification\s*$", "", title, flags=re.IGNORECASE).strip()
    title = strip_artifacts(title)
    # A title should be a single phrase: cut at the first sentence break an
    # em-dash leaves behind.
    title = title.split(". ")[0].strip().rstrip(".")
    if not title or title == slug or SLUGGY_RE.match(title):
        title = titlecase_slug(slug)
    return title


def strip_artifacts(text: str) -> str:
    """Remove engineering/CI artifacts that must never reach customer copy."""
    if not text:
        return ""
    t = text
    t = re.sub(r"@e2e\b[^\n.]*", "", t, flags=re.IGNORECASE)      # @e2e exclude ...
    t = re.sub(r"\(?#\d+\)?", "", t)                               # #202 / (#202)
    t = re.sub(r"(?i)\b(?:delta\s+)?spec:\s*", "", t)              # Spec: / Delta Spec:
    t = t.replace("`", "")                                         # code spans
    t = re.sub(r"\s*--\s*", ", ", t)                               # double-dash aside
    t = re.sub(r"\s*[—]\s*", ". ", t)                         # em-dash -> sentence
    t = re.sub(r"(?i)\b(?:TODO|TBD)\b", "", t)
    t = re.sub(r"\s+", " ", t)
    t = re.sub(r"\s+([.,;:])", r"\1", t)
    t = re.sub(r"\.\s*\.", ".", t)
    return t.strip(" .,;:-")


def first_sentence(summary: str) -> str:
    """A single benefit sentence: strip a user-story lead-in, cut to the first
    sentence, capitalise, end with a period. Empty/placeholder -> ''."""
    s = strip_artifacts(summary)
    if not s:
        return ""
    m = re.match(r"(?i)^as an? .*? so that (.+)$", s)
    if m:
        s = m.group(1).strip()
    s = s.split(". ")[0].strip()
    if not s or s.lower() in ("tbd", "todo", "purpose"):
        return ""
    s = s[0].upper() + s[1:]
    if not s.endswith("."):
        s += "."
    return s


def is_internal_slug(slug: str) -> bool:
    return any(seg in INTERNAL_SEGMENTS for seg in slug.split("-"))


def parse_spec(spec_path: Path) -> dict | None:
    """Parse a single spec.md into a feature entry, or None to skip it."""
    text = spec_path.read_text(encoding="utf-8")
    match = FRONTMATTER_RE.match(text)
    if not match:
        return None
    front, body = match.group(1), match.group(2)
    slug = spec_path.parent.name

    commercial = _bool_field(front, "commercial")
    if commercial is False:
        return None

    status = (_field(front, "status") or "").lower()
    maturity = (_field(front, "maturity") or "").lower()
    kind = (
        maturity if maturity in VALID_MATURITY
        else STATUS_KIND.get(status)
    )
    if kind is None:
        kind = "beta" if commercial is True else None
    if kind is None:
        return None

    if is_internal_slug(slug) and commercial is not True:
        return None

    title_override = _field(front, "title")
    if title_override:
        title = strip_artifacts(title_override) or titlecase_slug(slug)
    else:
        title_match = H1_RE.search(body)
        raw_title = title_match.group(1).strip() if title_match else slug
        title = clean_title(raw_title, slug)

    summary_override = _field(front, "summary") or _field(front, "tagline")
    if summary_override:
        summary = first_sentence(summary_override)
    else:
        summary = ""
        purpose_match = PURPOSE_RE.search(body)
        if purpose_match:
            first_para = purpose_match.group(1).strip().split("\n\n", 1)[0]
            summary = first_sentence(first_para)

    entry = {
        "slug": slug,
        "title": title,
        "summary": summary,
        "status": kind,
        "docsUrl": f"openspec/specs/{slug}/spec.md",
    }
    _attach_optional(
        entry,
        provided_by=_field(front, "provided-by") or _field(front, "providedBy"),
        title_nl=_field(front, "title_nl"),
        summary_nl=first_sentence(_field(front, "summary_nl") or _field(front, "tagline_nl") or ""),
    )
    return entry


def _attach_optional(entry: dict, provided_by=None, title_nl=None, summary_nl=None) -> None:
    """Append optional curated fields in a fixed order (so JSON stays stable)."""
    if provided_by:
        entry["providedBy"] = provided_by
    if title_nl:
        entry["title_nl"] = title_nl
    if summary_nl:
        entry["summary_nl"] = summary_nl


def normalize_overlay_entry(raw: dict) -> dict | None:
    """Normalise one curated overlay entry into the emitted shape."""
    if not isinstance(raw, dict):
        return None
    slug = str(raw.get("slug") or "").strip()
    status = str(raw.get("status") or "").strip().lower()
    if status not in VALID_MATURITY:
        status = STATUS_KIND.get(status) or "stable"
    title = str(raw.get("title") or "").strip() or (titlecase_slug(slug) if slug else "")
    if not title:
        return None
    if not slug:
        slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")
    entry = {
        "slug": slug,
        "title": title,
        "summary": str(raw.get("summary") or "").strip(),
        "status": status,
        "docsUrl": str(raw.get("docsUrl") or f"openspec/specs/{slug}/spec.md"),
    }
    _attach_optional(
        entry,
        provided_by=raw.get("providedBy"),
        title_nl=(str(raw.get("title_nl")).strip() if raw.get("title_nl") else None),
        summary_nl=(str(raw.get("summary_nl")).strip() if raw.get("summary_nl") else None),
    )
    return entry


def load_overlay(app_root: Path) -> list[dict] | None:
    """Load openspec/features.overlay.json (array, or {features:[...]})."""
    overlay_path = app_root / "openspec" / "features.overlay.json"
    if not overlay_path.is_file():
        return None
    try:
        data = json.loads(overlay_path.read_text(encoding="utf-8"))
    except (ValueError, OSError):
        return None
    raw_list = data.get("features") if isinstance(data, dict) else data
    if not isinstance(raw_list, list):
        return None
    entries = [e for e in (normalize_overlay_entry(r) for r in raw_list) if e is not None]
    return entries or None


def collect(app_root: Path) -> list[dict]:
    overlay = load_overlay(app_root)
    if overlay is not None:
        return overlay
    specs_dir = app_root / "openspec" / "specs"
    if not specs_dir.is_dir():
        return []
    entries: list[dict] = []
    for spec_md in sorted(specs_dir.glob("*/spec.md")):
        entry = parse_spec(spec_md)
        if entry is not None:
            entries.append(entry)
    return entries


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--app-root", type=Path, default=Path.cwd(), help="App repo root (default: cwd)")
    parser.add_argument("--out", type=Path, default=None, help="Output path (default: <app-root>/docs/features.json)")
    parser.add_argument("--check", action="store_true", help="Exit 1 if the output would change (CI lint mode)")
    args = parser.parse_args(argv)

    out_path = args.out or (args.app_root / "docs" / "features.json")
    entries = collect(args.app_root)
    payload = json.dumps(entries, indent=2, ensure_ascii=False) + "\n"

    if args.check:
        existing = out_path.read_text(encoding="utf-8") if out_path.is_file() else ""
        if existing != payload:
            print(f"::error::docs/features.json is out of date — run scripts/extract-features.py to regenerate")
            return 1
        return 0

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(payload, encoding="utf-8")
    print(f"wrote {len(entries)} feature(s) to {out_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
