# Design — TJ flattening

## Decisions

### D1. Pure-function utility, opt-in

The flattener is `public static function flatten(string $content_stream): string`. No PDFObject coupling, no document-wide state. Consumers (PR #08's text-replacement code, possibly other downstream uses) call it before they run their own logic.

SAPP itself doesn't auto-apply flattening — kerning fidelity matters for some uses. The opt-in shape preserves SAPP's neutrality on text-rendering semantics.

### D2. Tokenise, identify, rewrite

A content stream is a sequence of operands followed by operators. The flattener:

1. Walks the stream byte-by-byte.
2. Identifies operands (parenthesised literals `(...)`, hex strings `<...>`, integer / float numbers, arrays `[...]`, names `/...`).
3. Identifies operators (alphanumeric tokens — `Tj`, `TJ`, `Tf`, etc.).
4. When an operator is encountered, decides the action:
   - `TJ` — read backwards through the operand stack, parse the array operand, rewrite to a `Tj` with concatenated strings.
   - Anything else — emit operands + operator unchanged.

The walker is ~80 LOC. PDF tokenisation isn't trivial (parentheses can nest in literal strings, hex strings can contain whitespace, escape sequences `\\\\(`, `\\\\)`, `\\\\\\n`, `\\\\xxx` octal); the helper handles each per PDF 1.7 §7.3.

### D3. String concatenation preserves encoding form

A TJ array can mix literal strings (`(...)`) and hex strings (`<...>`). The flattener inspects the first string element of the array — its form determines the output form:

- First element is a `(...)`: emit `(concatenated literal) Tj`.
- First element is a `<...>`: emit `<concatenated hex> Tj`.

For composite (Identity-H) fonts, the elements are typically all hex strings carrying 2-byte glyph IDs. The hex form is preserved verbatim.

For mixed-form arrays (rare), we convert all elements to the first element's form before concatenating. This loses some byte-fidelity but produces a well-formed output stream.

### D4. Numeric kerning operands are dropped

The default behaviour: discard ALL integer / float operands in the TJ array. The flattened output has no kerning hint.

**Optional refinement (out of scope for v1):** kerning numbers with |n| > 200 (1/1000 font units) often represent intentional word-break spacing in justified text. A flag like `treat_large_kerning_as_space` could insert a literal space character at those positions. We don't include the flag in v1 — strict-discard is simpler; the flag adds complexity only if real-world cases demonstrate degradation.

### D5. Escape handling in literal strings

PDF literal strings can contain:
- Balanced nested parentheses: `(hello (world))` is one string with content `hello (world)`.
- Escaped parens: `\\\\(` and `\\\\)` are literal characters.
- Other escapes: `\\\\n`, `\\\\r`, `\\\\t`, `\\\\b`, `\\\\f`, `\\\\\\\\` and `\\\\xxx` octal.
- Line continuations: `\\\\\\n` at end of line continues the string to next line.

The flattener's literal-string parser MUST handle all five cases. It's the trickiest part of the implementation; reference: PDF 1.7 §7.3.4.2.

### D6. Hex string handling

Hex strings (`<48656c6c6f>`) are simpler: hex digits with optional whitespace. The flattener's hex-string parser strips whitespace, validates each char is a hex digit, accumulates. Odd-length input pads trailing-0 per spec (matches the ASCIIHexDecode rules).

### D7. Graphics-state delimiters (q / Q) pass through

The flattener walks the entire content stream, not just text-object sections. Graphics-state-save (`q`) and graphics-state-restore (`Q`) operators pass through unchanged. Text-object boundaries (`BT` / `ET`) pass through unchanged.

### D8. No font-state tracking

The flattener doesn't track which font is active at any given TJ operator. It rewrites the array shape; downstream code (PR #08) handles the font-aware byte matching.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Literal-string parser mishandles escape sequences | Medium | Unit test all five escape forms (balanced parens, escaped parens, char-escapes, octal escapes, line continuations); reference real-world Word PDF output |
| Mixed-form TJ array (literal + hex elements) breaks | Low | Spec REQ-04 covers; explicit test with mixed-form fixture |
| Off-by-one in operand-stack walking | Medium | Test with TJ operators surrounded by various other operators (BT/ET boundaries, q/Q states, multiple Tj/TJ in a row) |
| Kerning loss has unexpected visual impact on a Woo PDF | Low | Document the trade-off; the consumer can choose to skip the flattener for kerning-sensitive use cases |
| Performance — content streams can be large (multi-MB for image-heavy PDFs) | Low | Tokenisation is single-pass linear; profile and confirm O(n) on a realistic input |

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-tj-flattening/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/tj-flattening/spec.md`). Key as-shipped facts: D2 split shape preserved (leading TJ array + placeholder Tj + trailing TJ array per match boundary); multi-match in same TJ supported via iterative re-resolve loop; numeric tokenizer rejects scientific notation per PDF 1.7 §7.3.3; odd-length hex padded with `0` per §7.3.4.3; comments + spec-defined whitespace honoured inside TJ arrays.
