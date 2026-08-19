# `ConductionNL/sapp` — fork notes

This is a fork of [`dealfonso/sapp`](https://github.com/dealfonso/sapp). All features in this fork are **planned for upstream contribution** — see [`docs/upstream-prs/`](docs/upstream-prs/) for the PR plan and per-feature drafts.

## What this fork adds

The `work/text-replacement` branch adds text-replacement primitives to SAPP. The downstream consumer is [`ConductionNL/openregister`](https://github.com/ConductionNL/openregister)'s `pdf-anonymisation` change — a GDPR-compliant anonymisation pipeline for Dutch Woo (Wet Open Overheid) requests.

The features here are generic PDF mechanics, not OpenRegister-specific behaviour:

- Stream-filter decoders covering `/ASCIIHexDecode`, `/RunLengthDecode`, `/ASCII85Decode`, `/LZWDecode` (upstream currently handles `/FlateDecode` only).
- Filter chaining via the `/Filter` array form.
- ToUnicode CMap parser + per-font encoding resolver (`Identity-H` / `Identity-V` composite fonts; `/Differences`-aware single-byte encodings).
- TJ kerning-array flattening helper.
- `PDFDoc::replaceTextInDocument(array $substitutions, array $options)` flagship API.

All eight features have draft issue bodies in `docs/upstream-prs/`, ready to post to upstream once the PoC validates the implementation.

## Branch layout

- `main` — tracks `dealfonso/sapp:main`. Never modified directly.
- `work/text-replacement` — long-lived integration branch. The downstream OpenRegister consumer's `composer.json` points here via a VCS repository. As upstream merges land, this branch shrinks.
- `feat/<feature>` — per upstream-bound feature, branched off `main`, opened as an upstream PR. Squash-merged into `work/text-replacement` for integration testing.

## Workflow

1. **PoC first**: build a working end-to-end thing on `work/text-replacement` before posting any upstream issues. We contribute code we've validated, not promises.
2. **Post issue** from `docs/upstream-prs/0X-*.md` (verbatim or with light edits).
3. **Open PR** off upstream `main`, referencing the issue.
4. **Merge to integration branch** for downstream testing.
5. **When upstream merges**, rebase `work/text-replacement`. When all features land, retire the integration branch and switch the downstream back to upstream tags.

## License

Same as upstream: **LGPL-3.0-or-later**. Contributions to the fork are made under the same license; upstream PRs preserve the original copyright and licensing.
