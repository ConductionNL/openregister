# Proposal — TJ kerning-array flattening helper

## Why

Word-generated PDFs encode kerned body text using the `TJ` operator with mixed-content arrays:

```
[(J) 5 (a) -3 (n) 10 ( ) (J) -2 (a) -3 (n) 5 (s) -1 (e) -5 (n)] TJ
```

The strings between parentheses are per-character glyph fragments; the integers are per-character kerning adjustments (in 1/1000 of font units; negative = move closer to the next character, positive = move farther apart).

Substitution-map matching has to operate on the **logical text** ("Jan Jansen") not on per-character fragments. Without flattening, search would have to scan across multiple TJ-array string elements — fragile and slow. With flattening, each TJ becomes a single Tj string and matching reduces to plain string search.

This helper is a self-contained utility that text-replacement code (PR #08) calls before running substitution-map matching.

## What Changes

- **NEW class** `ddn\sapp\helpers\TextContentStreamFlattener` under namespace `ddn\sapp\helpers`.
- **NEW static method** `public static function flatten(string $content_stream): string`:
  - Tokenises the content stream.
  - For each `TJ` operator: parses its bracketed array, concatenates string elements (preserving their original encoding form — literal `(...)` strings stay literal; hex `<...>` strings stay hex), discards numeric kerning adjustments, emits a single `Tj` operator.
  - All other operators (`Tj`, `Tf`, `Tm`, `TD`, `BT`, `ET`, ...) pass through unchanged.
  - Returns the rewritten content stream.
- **NO change** to existing API.

## Impact

- **Spec target:** PDF 1.7 §9.4.3 (Text-Showing Operators).
- **Trade-off:** typographic kerning is lost in flattened output. For body text in Word-generated PDFs this is visually imperceptible. For typographic publications relying on per-character kerning, the difference would be visible.
- **Use case:** the downstream consumer (text-replacement) calls `flatten()` BEFORE matching. SAPP itself doesn't currently take any opinion on text-fidelity — the helper is purely opt-in.
- **Out of scope:**
  - Other text-showing operators (`'` and `"`) — these don't take kerning arrays.
  - Font / text-state tracking — the helper rewrites TJ-array shape only; it doesn't track `Tf`/`Tm` state.
  - Optimal-kerning preservation (we discard all numbers; PR #08 documents the loss).

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-tj-flattening/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/tj-flattening/spec.md`). Key as-shipped facts: D2 split shape preserved (leading TJ array + placeholder Tj + trailing TJ array per match boundary); multi-match in same TJ supported via iterative re-resolve loop; numeric tokenizer rejects scientific notation per PDF 1.7 §7.3.3; odd-length hex padded with `0` per §7.3.4.3; comments + spec-defined whitespace honoured inside TJ arrays.
