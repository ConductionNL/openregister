# SAPP fork — OpenSpec

This folder contains the implementation contracts for the
text-replacement feature being staged on the `work/text-replacement`
integration branch of `ConductionNL/sapp`. Each change corresponds to
one PR that we eventually intend to contribute back upstream to
`dealfonso/sapp` once the feature stabilises in our primary consumer
(OpenRegister's PDF anonymisation pipeline).

## Why an OpenSpec scaffold inside a PHP library?

SAPP is not a Nextcloud app, but the text-replacement work is being
done under time pressure for a Nextcloud-app consumer (OpenRegister)
that lives in the wider Conduction OpenSpec workflow. Putting the
specs next to the code that implements them keeps the contract close
to the source, makes upstream-PR submission easier later (each spec
becomes the PR's design doc), and gives us a place to record the
implementation notes worth surfacing in the upstream review.

The `docs/upstream-prs/01-asciihex-decode/` ... `08-text-replacement-api/`
folders are the actual artefacts we'll paste into the upstream PRs.
These directories are created lazily by the individual implementation
PRs; this scaffold change ships only the `openspec/` tree. The
`openspec/changes/` folder is where the work is planned and tracked
**before** those upstream-PR-draft folders get the polished final
write-up.

## Folder structure

| File / Folder | Purpose |
|---|---|
| `config.yaml` | OpenSpec project config — rules tuned for a PHP library, not a Nextcloud app |
| `architecture/` | ADRs specific to the fork (e.g. fork-and-give-back ordering) |
| `specs/` | Accepted capability specs — created when a `change/` is archived |
| `changes/` | One folder per planned upstream PR — proposal, design, spec, tasks |

## Mapping to the upstream-PR series

Rows are listed in dependency order — `feat-filter-chain-dispatch` is
the foundation that PRs `#01`–`#04` attach to. The "Upstream-PR draft"
column references the `docs/upstream-prs/NN-<slug>/` folder index,
which intentionally differs from the dependency-order rows. To
disambiguate "upstream-PR draft" indices from ConductionNL/sapp PR
numbers throughout the rest of this scaffold, references are written
as `upstream-PR #NN` (or by change name).

| OpenSpec change (dependency order) | Upstream-PR draft | PDF 1.7 ref |
|---|---|---|
| `feat-filter-chain-dispatch` (foundation) | `docs/upstream-prs/05-filter-chaining/` | §7.4 (Filters, array form) |
| `feat-asciihex-decode` | `docs/upstream-prs/01-asciihex-decode/` | §7.4.2 |
| `feat-runlength-decode` | `docs/upstream-prs/02-runlength-decode/` | §7.4.5 |
| `feat-ascii85-decode` | `docs/upstream-prs/03-ascii85-decode/` | §7.4.3 |
| `feat-lzw-decode` | `docs/upstream-prs/04-lzw-decode/` | §7.4.4 |
| `feat-tounicode-cmap` | `docs/upstream-prs/06-tounicode-cmap/` | §9.10 (ToUnicode CMaps) |
| `feat-tj-flattening` | `docs/upstream-prs/07-tj-flattening/` | §9.4 (text-showing operators) |
| `feat-text-replacement-api` | `docs/upstream-prs/08-text-replacement-api/` | n/a (new public API) |

## Workflow

- `/opsx-ff` — Create + scaffold a new change end-to-end
- `/opsx-continue` — Resume work on an in-progress change
- `/opsx-apply` — Drive the implementation from `tasks.md`
- `/opsx-verify` — Verify implementation matches the spec
- `/opsx-archive` — Move a completed change into `specs/`
