# Spec — TJ flattening

## Requirements

### REQ-01. `flatten()` MUST rewrite TJ operators to Tj operators

When encountering a `TJ` operator, the flattener MUST:

- Parse the bracketed array operand.
- Concatenate the string elements (literal strings stay literal, hex strings stay hex, picked by the first element's form).
- Discard numeric kerning elements.
- Emit a single `Tj` operator with the concatenated string operand.

**Scenario: simple literal-string TJ flattens**

GIVEN content stream `[(J) 5 (a) -3 (n) 10 ( ) (J) -2 (a) -3 (n) 5 (s) -1 (e) -5 (n)] TJ`
WHEN `flatten()` runs
THEN the output contains `(Jan Jansen) Tj` (or equivalent literal form with the same byte content)

**Scenario: hex-string TJ flattens**

GIVEN content stream `[<004A> 5 <0061> -3 <006E>] TJ` (Identity-H glyph IDs for "Jan")
WHEN `flatten()` runs
THEN the output contains `<004A0061006E> Tj`

### REQ-02. Other operators MUST pass through unchanged

The flattener MUST NOT modify any operator other than `TJ`. Specifically: `Tj`, `Tf`, `Tm`, `TD`, `Td`, `T*`, `BT`, `ET`, `q`, `Q`, and all graphics operators pass through byte-for-byte.

**Scenario: Tj passes through unchanged**

GIVEN content stream `(Hello) Tj`
WHEN `flatten()` runs
THEN the output is `(Hello) Tj` (byte-equal)

**Scenario: text-object boundary operators pass through**

GIVEN content stream `BT /F1 12 Tf 100 700 Td [(H) -5 (i)] TJ ET`
WHEN `flatten()` runs
THEN the output is `BT /F1 12 Tf 100 700 Td (Hi) Tj ET` (with TJ→Tj rewrite; everything else byte-equal)

### REQ-03. Literal-string parser MUST handle PDF escape sequences

PDF 1.7 §7.3.4.2 defines escapes in literal strings. The parser MUST handle:

- Balanced nested parens: `(hello (world))` — one string, content `hello (world)`.
- Escaped paren: `\\\\(` and `\\\\)` — literal `(` and `)` characters.
- Char escapes: `\\\\n`, `\\\\r`, `\\\\t`, `\\\\b`, `\\\\f`, `\\\\\\\\`.
- Octal escapes: `\\\\nnn` (1–3 octal digits).
- Line-continuation: `\\\\\\n` (backslash + newline) within a string suppresses the newline.

**Scenario: escaped paren in TJ string**

GIVEN content stream `[(He said \\\\(hi\\\\))] TJ`
WHEN `flatten()` runs
THEN the output's Tj operand contains the literal text `He said (hi)`

**Scenario: octal escape**

GIVEN content stream `[(\\\\101)] TJ`
WHEN `flatten()` runs
THEN the output's Tj operand contains the literal byte `\\x41` ('A')

### REQ-04. Mixed-form TJ arrays MUST flatten without losing content

When a TJ array contains both literal `(...)` and hex `<...>` strings (rare but spec-valid), the flattener MUST emit a single output in the form of the FIRST string element, converting subsequent elements to that form.

**Scenario: mixed-form array flattens**

GIVEN content stream `[(Jan) <00207368> (en)] TJ` (literal-first, hex middle, literal end)
WHEN `flatten()` runs
THEN the output is a literal-form Tj operator containing the concatenated text content

### REQ-05. Empty TJ array MUST be handled

An empty `[] TJ` is spec-permitted but has no visible effect. The flattener MAY emit `() Tj` OR drop the operator entirely; either is acceptable.

**Scenario: empty TJ array**

GIVEN content stream `q [] TJ Q`
WHEN `flatten()` runs
THEN the output contains either `q () Tj Q` OR `q Q` (the empty array form is removed); the visible behaviour is unchanged

### REQ-06. No font-state tracking

The flattener MUST NOT track which font is active at any TJ operator. It operates on TJ-array shape only.

### REQ-07. Performance — O(n) on content-stream size

The flattener MUST be linear in the size of the input content stream. No quadratic behaviour for long TJ arrays or large streams.

**Scenario: 100 KB content stream flattens in < 50ms**

GIVEN a 100 KB content stream with ~500 TJ operators
WHEN `flatten()` runs
THEN it completes in < 50ms on a standard developer machine (relaxed bound — for sanity)
