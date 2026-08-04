# Entity Replacement (Anonymisation)

How OpenRegister decides *which* text to redact once entities have been detected,
and what it reports about the result.

Detection is a separate concern — see
[Named Entity Recognition](./named-entity-recognition.md). This page covers only
what happens after: turning a `entity text => placeholder` map into a redacted
document.

## Why there is a planner

Replacement used to be a sequential `str_ireplace` over the substitution map.
That is the wrong primitive for redaction, for four independent reasons:

1. **Each replacement saw text an earlier one had already rewritten.** Two
   entities that overlap clobber each other, and an emitted placeholder can be
   matched again by a later needle.
2. **It could only match text that appeared contiguously in one string.** In a
   `.docx`, Word routinely splits a name across two `<w:r>` runs at formatting,
   spell-check or `rsid` boundaries. Those were never matched and stayed in the
   output verbatim.
3. **No word boundaries.** A `PERSON` entity `Jan` also rewrote `Januari`.
4. **No report.** Only the PDF path could tell you it had missed something.

The planner separates the decision from the application:

```
match (on the immutable original)  →  claim ranges  →  apply once  →  report
```

Implementation lives in `lib/Service/File/Anonymisation/`.

## How ranges are chosen

Every occurrence of every entity is enumerated against the **unmodified**
original text. From those candidates, the planner selects the non-overlapping
subset that redacts the **most characters** (weighted interval scheduling).

This subsumes the older "longest first" rule: a containing entity always covers
more characters than one nested inside it, so an `EMAIL`
`robert@rjzondervan.nl` beats a `PERSON` `rjzondervan` without any length rule
existing. It also fixes a case longest-first got wrong — two short entities that
together cover more than one long entity overlapping both.

Ties are broken deterministically (start, then span, then type, then entity
text), so **the same document and entities always produce byte-identical
output**, regardless of the order detection happened to return them in.

### Residue coverage

Two entities can compete for the same characters without either containing the
other: `Jan de Vries` and `Vries-Bakker` in `Jan de Vries-Bakker`. One wins; the
loser's *uncovered remainder* is then redacted too, so nothing identifying is
left behind. The output reads `[PERSOON: 1][PERSOON: 2]` rather than leaking
`Bakker`.

Remainders consisting only of whitespace or punctuation are left alone.

### Single-pass application

The output is **built** — original slice, placeholder, original slice — never
progressively mutated. An emitted placeholder therefore cannot be matched by a
later entity, including when the input already contains placeholders from a
previous anonymisation run.

## Boundary policy

Whether a match is allowed at a position depends on the entity type. The split is
by **numeric embeddability**, not by "structured versus free text".

| Policy | Rule | Types |
|---|---|---|
| **Word-bounded** | No adjacent letter, digit, mark or underscore | `PERSON`, `ORGANIZATION`, `LOCATION`, `ADDRESS`, and any type not listed here |
| **Delimited-token** | Word-bounded, and not part of a longer number | `DATE`, `SSN`, `PHONE`, `IP_ADDRESS` |
| **Literal** | No boundary requirement | `EMAIL`, `IBAN` |

**Why delimited-token exists.** A short numeric value inside a longer number does
not merely over-redact — it rewrites a *different* value. Under literal matching
the needle `192.168.1.1` matches inside `192.168.1.10`, emitting
`[IP-ADRES: 1]0`: a corrupted, different address. The same applies to a BSN
inside a longer digit run, or a year inside a case number.

A "number" here is a run of digits optionally joined by single `-`, `/`, `.` or
`:` separators **where each separator is immediately followed by a digit**. That
proviso is what lets a sentence-final `2026.` match while `2026-0012` does not.
An entity may itself be internally joined (`20260803`, `03-08-2026`,
`192.168.1.1`) — the rule constrains only what surrounds the match.

**Why `EMAIL` and `IBAN` stay literal.** They are long and distinctive enough
that substring false positives are negligible, which buys tolerance for
unseparated forms like `IBANNL91ABNA0417164300` where any boundary rule would
reject a genuine match.

**Why unlisted types default to bounded.** The two failure modes differ in
*visibility*. A boundary miss leaves the entity unmatched, which the report
surfaces. A literal false positive silently over-redacts or corrupts a longer
string, and nothing detects it. Defaulting to the policy whose failures are
observable is the safer choice.

**Note on `DATE`.** Date recognition is disabled by default as a settings
convention — only birth dates warrant anonymisation, and the date recogniser is
otherwise noisy. Its rule is therefore defence-in-depth for operators who
deliberately enable dates.

**Ad-hoc replacement is unaffected.** `replaceWords()` is a separate entry point
for plain find/replace with no entity context. It keeps literal substring
semantics; boundary policy applies only to entity anonymisation.

## What gets reported

`POST /api/files/{fileId}/anonymize` returns two kinds of finding. They demand
different responses, so they are reported separately.

| Kind | Meaning | What to do |
|---|---|---|
| `unmatched` | The entity matched nowhere. **Its text may still be in the output.** | Add a manual entity, adjust skip decisions, re-run. Not safe to publish as-is. |
| `partial` | Split-matched. Its text **is** gone, but removal needed more than one placeholder. | Review the overlapping detections. The output is safe; it just reads awkwardly. |

Response fields:

```json
{
  "success": true,
  "complete": false,
  "residual_count": 1,
  "residual_entities": [{"text": "...", "type": "PERSOON", "id": "7"}],
  "partial_count": 0,
  "partial_entities": []
}
```

Three rules matter for consumers:

- **`complete: false` does NOT mean PII remains.** Both kinds set it, so it means
  "a human should review this". A result whose only findings are `partial` is
  fully redacted. Consumers must read the *kind* to decide publishability.
- **`residual_count` counts only `unmatched`**, preserving the meaning it already
  had. Partials are counted separately.
- **Reporting never blocks.** A finding never fails the request or withholds the
  file. The output is always produced and persisted; the report is a diagnostic
  the operator iterates on.

An entity that matched nowhere *of its own accord* but whose every occurrence
sits inside another entity's redacted range is **not** reported. Its text is
gone — that is the ordinary containment outcome (a person inside an email
address), and flagging it would raise a false alarm on the most common case.

## Per-format behaviour

Formats do not hold text as one string, so each exposes its text as ordered
segments; the planner runs on the concatenation and the result is scattered back.
A placeholder lands entirely in the segment holding the match's start, so that
segment's formatting survives; later overlapped segments have their covered part
removed and are kept as empty strings rather than deleted.

| Format | Segments | Grouping scope |
|---|---|---|
| Plain text | one | whole file |
| `.docx` | PhpWord text runs | one element list — never across a section's paragraphs |
| `.odt` | `<text:span>` text nodes | one paragraph (`text:p` / `text:h`) |
| EML | decoded header and body parts | per part |
| PDF | — | SAPP performs the matching |

Grouping scope matters. Flattening more than one text flow lets a match span
text a reader never sees as adjacent — this repository's own docx fixture
concatenates to `Kerkstraat 123512 GK Utrecht` across two separate paragraphs.

### Hyperlinks in `.docx`

An entity in a Word hyperlink is unreachable by any text walk, so it is handled
separately, on the written file.

`PhpWord\Element\Link` exposes `getText()` but no `setText()` and no
`setSource()`, so neither half of a hyperlink can be rewritten through PhpWord's
object model. Worse, when a link's display text is a person's *name*, the email
address exists **only** in `word/_rels/document.xml.rels` — invisible in the
rendered document and absent from `document.xml` entirely.

Two operations are therefore applied to the docx after it is written (the same
approach already used to work around a PhpWord soft-line-break bug):

1. Display text inside each `<w:hyperlink>` is redacted through the planner, with
   the hyperlink's runs treated as one flow so a split entity still matches.
2. A hyperlink whose **target** contains entity text is **unwrapped** — the
   element is removed and its runs kept — and the relationship target is set to
   `about:blank`. Rewriting it to `mailto:[EMAIL: 1]` would leave a malformed
   address masquerading as a real one, so stripping the link is the honest
   redaction. Links whose targets are clean keep working.

Target matching is a plain case-insensitive substring test, not the boundary
policy: a URL is not prose and gives a needle no word boundary to sit on.

**Still not covered.** Comments, tracked changes, footnotes, endnotes, document
metadata and custom XML can all carry entity text and are untouched. A `.docx`
whose PII lives only in those structures is not safe to publish on the strength
of anonymisation alone.

### PDF is different, deliberately

The PDF path delegates matching and content-stream rewriting to SAPP, because
offsets in re-extracted text are not addressable positions in the underlying
content streams. Consequences:

- **The word-boundary policy does not apply to PDFs.** SAPP matches substrings,
  so a `PERSON` entity `Jan` still rewrites `Januari` in a PDF, where it no
  longer does in `.docx`, `.odt` or plain text. This is a known inconsistency;
  closing it requires boundary awareness inside SAPP.
- The PDF verification gate uses substring matching too, on purpose — a stricter
  gate than the replacer it audits would invent residuals for text no
  substitution ever claimed.
- Substitutions SAPP reports it could not apply are folded into the residual
  list, because re-extraction alone can miss them: an encoding miss can leave a
  value in bytes the extractor renders differently.

## Performance

Planning is measurably **slower** than the `str_ireplace` it replaced — roughly
3–13× on synthetic input, e.g. 266 ms against 90 ms for a 159 KB document
carrying 1000 distinct entities. The planner's cost is nearly flat in entity
count while the old approach scaled linearly, so the gap narrows as entity counts
rise.

This is accepted in exchange for correctness on the four defects above, but note
`POST /api/files/{fileId}/anonymize` runs synchronously inside the request, so
this is user-facing latency rather than background work. The added cost has not
been measured as a fraction of a real anonymise request; if it turns out to
matter, profile before optimising — the cause of the slowdown has not been
attributed.
