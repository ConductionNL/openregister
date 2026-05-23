---
name: audit-categories
description: Audit categories beyond anti-patterns — run these as distinct finding classes during sync-docs gap analysis
type: reference
user-invocable: false
---

# Additional audit categories (beyond anti-patterns)

These are explicit checks to run during gap analysis. Surface each as its own finding class — do not lump them into "stale references".

- **Table of Contents staleness** — when a doc has a ToC ([writing-docs.md → Table of Contents](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#table-of-contents)), verify every ToC entry matches an existing section heading (exact GitHub-anchor match). Flag missing sections, renamed sections, and new sections that aren't indexed.
- **Mermaid diagram currency** — when a doc contains a `mermaid` fenced block, check that the states / parties / flow it describes still match reality (walk the current code or spec for the same concept). A drifted diagram misleads more than no diagram.
- **Screenshot presence and naming** — for `![alt](path/to/img.png)` references, verify the file exists at the referenced path and that the filename follows the `{feature}-{view}.png` convention. Flag missing files AND `screenshot-1.png` / `image.png`-style generic names.
- **Broken internal anchor links** — every `[text](file.md#anchor)` must resolve to a real heading in the target file. This is a subset of "broken links" but deserves its own callout because drift is silent (GitHub renders the link without error; only navigation fails).
- **Hardcoded versions in prose** — grep for patterns like `v\d+\.\d+\.\d+`, `Sonnet 4\.\d`, `PHP 8\.\d` that appear outside examples. Flag each as a candidate for replacement with a link to the version source or a softer "current stable" formulation — subject to the anti-pattern purpose check.
- **Orphan source-of-truth files** — any `.md` under `{GITHUB_REPO}/docs/claude/` that is *not* indexed from `{GITHUB_REPO}/docs/claude/README.md` **and** is not linked from any other dev doc is effectively invisible. Flag for either indexing or removal.
