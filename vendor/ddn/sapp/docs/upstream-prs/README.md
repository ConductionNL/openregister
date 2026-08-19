# Upstream PR plan — text replacement series

This directory tracks the upstream-bound work happening on the `work/text-replacement` branch of this fork.

## What this fork adds

`ConductionNL/sapp` is a fork of [`dealfonso/sapp`](https://github.com/dealfonso/sapp) (LGPL-3.0-or-later). The fork adds **text-replacement capability** to SAPP: the primitives needed to read, match, and rewrite text in PDF content streams while preserving visual layout. The downstream consumer is [`ConductionNL/openregister`](https://github.com/ConductionNL/openregister)'s `pdf-anonymisation` change — a GDPR-compliant anonymisation pipeline for Dutch government Wet Open Overheid (Woo) requests.

The work is **planned for upstream contribution**, not as a permanent fork. The features here are generic PDF mechanics that benefit anyone working with PDF text manipulation in PHP, not OpenRegister-specific behaviour. The OpenRegister side stays in OpenRegister; the SAPP-side primitives go upstream.

## Branch layout

| Branch | Purpose |
|--------|---------|
| `main` | Mirrors `dealfonso/sapp:main` — never modified directly. |
| `work/text-replacement` | Long-lived integration branch. Downstream OpenRegister `composer.json` points here. As upstream merges features, the integration branch shrinks. |
| `feat/<feature-name>` | One per upstream-bound feature, branched off `main`, opened as an upstream PR. Squashed into `work/text-replacement` for integration testing. |

## PR sequence

The work is split into eight focused PRs, in dependency order. Each is small enough to review individually. Bigger PRs sit on top of merged smaller PRs so they can land incrementally without one big-bang review.

**Note on numbering schemes**: the `01..08` prefix on directory names below is the planned-submission order to upstream `dealfonso/sapp`. **It is intentionally NOT the same as the fork's GitHub PR numbers** — the foundation (`05-filter-chaining`) lands first in our fork so the four dependent filter codecs (`01..04`) can attach to it locally, but it's submitted upstream AFTER they merge there. The fork's PR sequence in `ConductionNL/sapp` is: PR #1 (PoC), PR #2 (OpenSpec scaffolding), PR #3 (filter-chain-dispatch = upstream #05), PRs #4-#7 (the four filter codecs = upstream #01-#04), PR #8 (CMap = upstream #06), PR #9 (TJ flattening = upstream #07), PR #10 (text-replacement API = upstream #08). Cross-references throughout these draft files use the `upstream-PR #NN` form to disambiguate.

Each feature has its own directory containing:

- `issue.md` — outward-facing draft to post on `dealfonso/sapp`. Frontmatter records `Posted at:` once the issue is live.
- `proposal.md` — what + why (inward-facing).
- `design.md` — decisions, alternatives, risks.
- `spec.md` — normative requirements with scenarios (REQ-NN format).
- `tasks.md` — work breakdown.

| # | Topic | Size | Depends on | Directory |
|---|-------|------|------------|-----------|
| 01 | `/ASCIIHexDecode` filter | tiny (~10 LOC) | — | [`01-asciihex-decode/`](01-asciihex-decode/) |
| 02 | `/RunLengthDecode` filter | tiny (~20 LOC) | — | [`02-runlength-decode/`](02-runlength-decode/) |
| 03 | `/ASCII85Decode` filter | small (~30 LOC) | — | [`03-ascii85-decode/`](03-ascii85-decode/) |
| 04 | `/LZWDecode` filter + PNG-predictor refactor | medium (~80 LOC + helper extract) | — | [`04-lzw-decode/`](04-lzw-decode/) |
| 05 | Filter chaining (`/Filter` array form) | small refactor (dispatch) | 01–04 | [`05-filter-chaining/`](05-filter-chaining/) |
| 06 | ToUnicode CMap parser + font encoding resolver | large (~500 LOC) | 05 | [`06-tounicode-cmap/`](06-tounicode-cmap/) |
| 07 | TJ kerning-array flattening helper | small (~80 LOC) | — | [`07-tj-flattening/`](07-tj-flattening/) |
| 08 | `PDFDoc::replaceTextInDocument()` flagship API | large (composition) | 01–07 | [`08-text-replacement-api/`](08-text-replacement-api/) |

## Workflow

1. **PoC first.** Build a working end-to-end thing on `work/text-replacement` (smallest possible: one filter, one font, one substitution) BEFORE posting upstream issues. The PoC proves the design works; we contribute code we've validated, not promises.
2. **Post issues.** Once the PoC is real, post the corresponding upstream issue. Each draft above carries `## Issue body (copy from here)` / `## (copy ends)` markers — copy verbatim into a new issue on `dealfonso/sapp` from the appropriate account.
3. **Open PR off `main`.** Branch from upstream `main` (NOT from `work/text-replacement`); apply the feature; open the PR referencing the issue.
4. **Merge to integration branch.** Squash-merge the feature branch into `work/text-replacement` so the downstream OpenRegister consumer can test the combined feature set.
5. **As upstream merges land**, rebase `work/text-replacement` against the merged upstream feature, dropping the now-duplicate commits. When the last feature lands, retire `work/text-replacement` and switch OpenRegister back to upstream tags.

## Why PoC-first

A maintainer is much more likely to accept a PR that says "here's working code, here's the downstream consumer that's using it, here's the test fixture proving it works end-to-end" than one that says "here's my plan, please tell me if you'll accept it." We do the work, prove the value, then ask. Issues are written in "we have this working" framing — see the drafts.

## Out of scope for this fork

Anything that's OpenRegister-specific stays in OpenRegister, not here:

- The `[<TYPE>: <id>]` placeholder format convention (one specific consumer choice; SAPP exposes a generic `placeholder_pattern` option in #08).
- The validation gate (smalot re-extract, OpenRegister-specific safety mechanism).
- PDF metadata sanitisation rules tied to OpenRegister's anonymisation contract.
- Adjacent-duplicate placeholder collapse rules tied to OpenRegister's `entity-relation-grondslagen` change.

The split forces the upstream API to be generic; the consumer-specific behaviour stays where it belongs.
