## Context

`PDFObject::set_stream` and `PDFObject::get_stream` are the two methods every other piece of stream-handling code in sapp routes through. Today they branch on `$this->_value['Filter']` with a single `switch` statement that has one arm — `'/FlateDecode'` — and a `p_error("unknown compression method ...")` default. The branch assumes the `/Filter` entry is a string. PDF 1.7 §7.4.1 defines the entry as **either** a name **or** an array of names, with the array form representing a chain of filters to apply in sequence.

Real-world content streams that arrive with array-form filters:

- Forensic and sanitiser pipelines that emit `[/ASCIIHexDecode /FlateDecode]` so the compressed payload survives transports that mangle binary data.
- Older toolchains (PDFKit 1.x, some report generators) that wrap a Flate stream in ASCII85 for the same reason.
- xref streams encoded as `[/FlateDecode]` (single-entry array form — semantically equivalent to the string form but lexically different).

Constraints from upstream `dealfonso/sapp`:

- PHP ≥ 7.4 minimum (composer constraint `^7.4 || ^8.0`). Do not raise it.
- Zero external composer dependencies. The library is intentionally lean.
- snake_case method names (`get_stream`, `set_stream`, `to_pdf_entry`, etc.). No camelCase.
- `PDFObject` / `PDFDoc` / `PDFValue*` class boundaries are load-bearing. Refactors across them are rejected on principle in upstream review.
- Each change in our fork should map 1:1 to an eventual upstream PR. This change is the foundation that PRs `#01`–`#04` (per-filter implementations) attach to.

The PoC verify (`examples/poc-replace-text.php`) currently passes on a single-FlateDecode fixture; the dispatch refactor must keep it green.

## Goals / Non-Goals

**Goals:**

- Accept both the string and array forms of `/Filter` on encode (`set_stream($_, false)`) and decode (`get_stream(false)`) without changing method signatures.
- Apply chain ordering normatively per PDF 1.7 §7.4.1 ¶3: encode reverses the chain (innermost-first), decode follows the chain (outermost-first).
- Handle the parallel `/DecodeParms` array shape — one entry per filter, `null` permitted for filters that take no parameters (Table 5 of §7.4.1).
- Fail safely on unknown filter names in the chain: emit `p_error()` and leave the stream unchanged so the caller can detect the failure. Do not throw.
- Keep all string-form callers passing through the existing code paths exactly (no behavioural drift on the 95% case).
- Provide the plug-in surface (`case` arms in the dispatcher) that PRs `#01`–`#04` attach to without further structural changes.

**Non-Goals:**

- Implementing new filters (`ASCIIHexDecode`, `ASCII85Decode`, `RunLengthDecode`, `LZWDecode`) — those are separate upstream PRs `#01`–`#04`.
- Changing the public `PDFObject` API surface beyond the internal dispatch.
- Refactoring `PDFDoc::get_object_iterator()` / `to_pdf_file_b()` — they don't see filter chains directly.
- Compression-level / quality knobs on FlateDecode. Out of scope.
- Stream encryption (`/Crypt` filter) — separate concern, not part of the contribution series.
- xref-stream-specific compression handling — separate concern.

## Decisions

### D1 — Inline protected-static dispatch helpers, not new public methods

We add two `protected static` helpers — `apply_filter_chain_decode($bytes, array $filters, array $params)` and `apply_filter_chain_encode($bytes, array $filters, array $params)` — both inside `PDFObject`. (`protected static` rather than `private` so test-suite subclasses can stub the helpers — the chain-ordering verification gate uses this — and matches the existing `FlateDecode` static helper's visibility.) The public `get_stream` / `set_stream` shape stays identical; they normalise the `/Filter` value (string → 1-element array) and the parallel `/DecodeParms` (single value → 1-element array), then delegate.

**Alternative considered:** add a public `apply_filter()` method to `PDFObject`. **Rejected** because it would expose internal-only mechanics, widen the API surface, and create a separate code path that callers might bypass to inject malformed input. The two existing public methods already encapsulate the right level of abstraction.

### D2 — Normalise string → 1-element array on the way in, keep the original shape on the way out

Inside `set_stream` / `get_stream`, immediately coerce the `/Filter` entry to an array regardless of source shape. The chain helpers always operate on arrays. When `set_stream` updates the object's `Filter` value after a chain encode, it preserves the original shape: string in → string out, array in → array out. This avoids gratuitous diff churn when round-tripping a single-filter stream (matters for `to_pdf_file_b`'s rebuild path — a single-filter stream that comes in as a string should not flip to an array on write-back).

**Alternative considered:** always normalise to array form on write-back. **Rejected** because it produces unnecessary diffs against upstream-format-compatible PDFs and complicates byte-for-byte comparison testing.

### D3 — `/DecodeParms` shape parity, with `null` as the per-filter no-params marker

PDF 1.7 §7.4.1 Table 5 says `DecodeParms` "shall be either a dictionary or an array of dictionaries", parallel to `Filter`. When a filter in the chain has no parameters, the corresponding slot in the array is the PDF `null` value. We handle this by indexing the parameters array by chain position and treating `null` / missing as "no params" (existing FlateDecode path already tolerates this).

**Alternative considered:** require callers to always supply a parallel `/DecodeParms` array. **Rejected** — PDF 1.7 makes `/DecodeParms` optional even when `/Filter` is present, and our test fixtures don't include it.

### D4 — Unknown filter ⇒ `p_error()` + return `false` from the chain helper

If any filter in the chain is unknown, the chain helper logs via `p_error()` (mirroring how the existing FlateDecode `switch` handles unsupported predictors / colours / bit-counts) and returns `false`. `get_stream` and `set_stream` propagate the failure: `get_stream` returns `$this->_stream` unchanged (matching existing behaviour); `set_stream` leaves the object's `_stream` field and `Length` unchanged. Result: the caller observes the original bytes still in place, and the `p_error` is logged for debugging.

**Alternative considered:** throw an exception. **Rejected** — upstream sapp's existing convention is `p_error()` + soft-fail, not exceptions. Diverging would block the upstream PR.

### D5 — Implementation lives entirely inside `src/PDFObject.php`

No new files, no signature changes on dependent classes (`PDFDoc`, `PDFUtilFnc`). The two helpers are added inside `PDFObject` next to the existing `FlateDecode()` static method, keeping the change set tight and the diff narrowly reviewable.

**Alternative considered:** factor filter implementations out into a `Filter\` namespace (one class per filter, registry pattern). **Rejected** for this PR — it's the right long-term refactor but would touch every existing callsite and inflate the upstream PR beyond what a single round-of-review can absorb. We'll revisit after PRs `#01`–`#04` land and the per-filter implementations have settled.

### D6 — Tests live as round-trip fixture assertions in `examples/`

The existing `examples/poc-replace-text.php` validation gate proves the single-FlateDecode path. We add a sibling `examples/poc-filter-chain-roundtrip.php` (introduced in this change) that exercises four scenarios at the dispatcher contract level:

1. A single-element `/Filter [/FlateDecode]` array-form round-trip (proves the array shape routes through the chain dispatcher, semantically equivalent to the string form).
2. An empty `/Filter []` pass-through (REQ-1's empty-chain scenario).
3. A string-form `/Filter /FlateDecode` round-trip + shape-preservation check (D2; REQ-4).
4. An unknown filter `/Filter [/SappTestUnknownFilter /FlateDecode]` chain-failure assertion: `get_stream(false)` returns `false`, `set_stream($_, false)` leaves `_stream` + `Length` untouched (REQ-5).
5. A `/DecodeParms` positional smoke test: single `/Filter [/FlateDecode]` + `/DecodeParms [<</Predictor 1 /Columns 4>>]` round-trip (REQ-3 smoke; Predictor=1 keeps it lossless).

A genuine two-filter `[/<filter-a> /<filter-b>]` round-trip — one that would prove decode/encode ORDERING is correct in the dispatcher — requires a second implemented filter and is therefore deferred to upstream-PRs #01-#04. Each of those PRs' verification gates extends `poc-filter-chain-roundtrip.php` with a two-filter test pairing the new codec with `/FlateDecode`. The chain-ordering scenario in `spec.md` REQ-1 / REQ-2 is locked at the spec layer but un-exercised by this PR's gate alone; the four follow-up PRs are jointly responsible for proving the ordering by induction.

**Alternative considered:** defer chain-aware tests until upstream-PR #01 (when there's a real second filter to chain). **Rejected** — the dispatch behaviour is the entire contract of this PR; testing it via the stub path locks the contract in before any real filter depends on it.

## Risks / Trade-offs

- **Risk**: A PDF in the wild has an unusual `/Filter` representation (e.g. `null`, an empty array, or a single-element nested array). → **Mitigation**: defensive normalisation in `set_stream` / `get_stream` — treat `null` and empty array as "no filter" (existing pass-through path), single-element array identically to the string form. Unit-test these shapes explicitly.

- **Risk**: A caller mutates `$this->_value['Filter']` directly after `set_stream` and the object ends up in an inconsistent state. → **Mitigation**: out of scope — same risk exists in the current code. Document the contract: callers must use `set_stream` (which manages `Filter` + `Length` together) and not poke at `_value` directly.

- **Trade-off**: We commit to applying filters in a specific order that's normative for the PDF 1.7 spec but contrary to the way some PDF inspectors visualise the chain (some inspectors show outermost-first regardless of operation). → **Mitigation**: the rule is in the dispatcher's PHPDoc with the §7.4.1 ¶3 reference; tests anchor the ordering with explicit `encode(decode($x)) === $x` round-trips.

- **Trade-off**: Adding a chain dispatcher without any second filter to chain means the new helper is technically dead code until upstream-PR #01 lands. → **Mitigation**: the stub-based test in D6 exercises the dispatcher today; upstream-PR #01 only needs to swap the stub `case` for a real `case`.

- **Trade-off**: Choosing `p_error()` over an exception means callers won't be jolted into handling failures explicitly. → **Mitigation**: this is upstream convention. We document the failure mode clearly in PHPDoc; the `p_error()` log gives operators the signal they need.

## Migration Plan

No migration is needed for consumers. The change is strictly additive on the input shape: callers handing in a string-form `/Filter` continue to work; callers (mostly OR-side once upstream-PR #01–`#04` land) handing in array-form `/Filter` start working where they were silently broken before. No data migrations, no schema changes, no version bumps required on `dealfonso/sapp` consumers.

For the rollback story: revert the single commit on `feat-filter-chain-dispatch`. Because all behaviour for string-form `/Filter` is preserved exactly, the revert is safe even if downstream code has already started using the array form (it just resumes failing in the same way it failed before this change).

## Open Questions

- **OQ1**: Should we proactively log a `p_debug()` line on every successful array-form decode for observability during the upstream review window? Leaning **no** — it'd pollute the log on every page-content read of a chain-encoded PDF, and the failure path already logs via `p_error()`. **Provisional**: skip `p_debug`. Confirm during PR review.

- **OQ2**: When `/DecodeParms` is an array shorter than `/Filter`, do we treat missing trailing entries as `null` or as a parse error? PDF 1.7 doesn't speak to under-length arrays explicitly. **Provisional**: treat trailing missing entries as `null` (no params) — most lenient, matches reader behaviour in Adobe / pdf.js / poppler. Lock this with a unit test.
