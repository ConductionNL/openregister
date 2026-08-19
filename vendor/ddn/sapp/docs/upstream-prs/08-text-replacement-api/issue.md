---
status: draft
target_repo: dealfonso/sapp
suggested_title: Add replaceTextInDocument() — text replacement with font-fallback
suggested_labels: enhancement, feature
relates_to: openregister/pdf-anonymisation
prereq: 01..07 — all of the prior issues. This is the flagship API; everything upstream of it is foundation.
---

# Upstream issue draft — `replaceTextInDocument()` flagship API

**Intended workflow:** post LAST. Every prior PR in the series builds toward this — the filter decoders give us readable content streams, the CMap parser gives us the search keys for each font, the TJ flattening gives us logical text to match against. This issue's PR is where they all come together.

By the time we post this, the prior issues will already have demonstrated working contributions, established the code-style alignment with the maintainer, and put the foundation pieces in place. The flagship API PR then says "here's the user-facing feature; here's the working downstream consumer (link to OpenRegister) that proves it works end-to-end."

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add `PDFDoc::replaceTextInDocument(array $substitutions, array $options = []): array` — a high-level API that performs text replacement across a PDF document's content streams while preserving visual layout. The PR is the user-facing capstone of the foundation work in PRs #01–#07.

## What the API does

```php
$doc = PDFDoc::from_string(file_get_contents('input.pdf'));

$substitutions = [
    'Jan Jansen' => '[PERSON: 7]',
    'Acme Holdings BV' => '[ORGANIZATION: 12]',
    '12-345-678' => '[ID: 99]',
];

$result = $doc->replaceTextInDocument($substitutions, [
    'replacement_font' => '/Helvetica',  // base font for placeholder rendering
    'font_switch_strategy' => 'inline',  // emit `/F-Replacement Tf ... /F<original> Tf`
    'longest_first' => true,             // sort substitutions by key length DESC before matching
    'collapse_adjacent_duplicates' => true,  // post-pass: "[P:7] [P:7]" → "[P:7]"
]);

// $result carries diagnostics:
//   - replacements_made: int total count of replacements
//   - per_font_counts: array<font_name, int> per-font replacement counts
//   - unmatched_substitutions: list<string> (substitutions that found no occurrences)
//   - bytes_changed: int total bytes mutated

file_put_contents('output.pdf', $doc->to_pdf_file_s(true));
```

## Why a single API, not a la carte primitives

The downstream use case (GDPR anonymisation) needs the full pipeline. Exposing it as a single API with sensible defaults makes the common case simple. Power users who want to compose primitives (e.g. iterate objects themselves, call the encoding resolver directly) still can — the API is built on top of `FontEncodingResolver` (PR #06), `TextContentStreamFlattener` (PR #07), and the filter dispatch (PR #05), all of which are public.

## Algorithm

```
For each content-stream object in the document:
  1. Decode the stream via the filter pipeline (#05).
  2. Apply TJ-array flattening (#07).
  3. For each font referenced in the page's /Resources:
     a. Resolve the encoding via FontEncodingResolver (#06).
     b. Translate substitution keys into per-font byte sequences (using
        the resolver's `unicodeToBytes` callable).
  4. For each Tj operator in the (now-flattened) stream:
     a. Find the longest matching substitution key in the current font's
        byte sequences (longest-first per the `longest_first` option).
     b. If matched: emit
          /F-Replacement <current-size> Tf
          (<placeholder-bytes>) Tj
          /<original-font> <current-size> Tf
     c. If no match: emit the operator unchanged.
  5. Register `/F-Replacement` in the page's resource dictionary once per
     document (defaults to /Helvetica with /WinAnsiEncoding — covers the
     placeholder format's ASCII characters).
  6. After all replacements: if `collapse_adjacent_duplicates`, run a
     regex post-pass over each output stream's Tj contents:
       (\[[A-Z]+:\s*\d+\])([ \-_])\1  →  \1
  7. Re-encode the stream via the filter pipeline (inverse of step 1).
  8. Replace the object's stream in the document.
After all objects: rebuild xref via to_pdf_file_s(true).
```

## Options

| Option | Default | Behaviour |
|--------|---------|-----------|
| `replacement_font` | `/Helvetica` | Base font for placeholder rendering. One of the 14 PDF standard fonts (always-available, no embedding required). Caller can override (e.g. `/Times-Roman`). |
| `font_switch_strategy` | `'inline'` | `'inline'` — emit Tf operators before/after each replacement (default, what we need). Future: `'document-wide'` — switch font once per content stream; lower fidelity but smaller diff. |
| `longest_first` | `true` | When multiple substitutions could match overlapping positions (e.g. `"Jan Jansen"` AND `"Jansen"`), prefer the longest. Disabling falls back to substitution-map iteration order — useful for testing. |
| `collapse_adjacent_duplicates` | `true` | Regex post-pass collapsing `[X:N] [X:N]` style pairs to a single `[X:N]`. Arises when variants of one logical entity are matched separately. |
| `match_case_sensitive` | `true` | Whether the byte-sequence match is case-sensitive. Default true to avoid false positives. |
| `placeholder_pattern` | `null` | Optional regex (PCRE) the post-collapse pass uses to identify "placeholder pairs". When `null`, defaults to `(\[[A-Z]+:\s*\d+\])` — the OpenRegister format. Callers using a different placeholder convention pass their own. |

All options have sensible defaults so `$doc->replaceTextInDocument($substitutions)` works out of the box.

## Acceptance test

The PR includes:

1. A real-world fixture (a one-page Word-generated PDF carrying `"Jan Jansen"`; one synthesised, no PII).
2. Test: replace `'Jan Jansen' => '[PERSON: 7]'` → output opens cleanly + re-extracts without "Jan Jansen" + re-extracts with "[PERSON: 7]".
3. Test: replace with variants `['Jan Jansen', 'Jansen', 'Jan']` all mapped to `[PERSON: 7]` → output has no residual variants, adjacent-duplicates collapsed.
4. Test: replacement crossing font boundaries (entity in Identity-H font) → encoding resolver translates correctly, font switch works.
5. Test: replacement-not-found (substitution key absent from document) → `unmatched_substitutions` array includes the key; no error.
6. Test: passing a substitution key that produces invalid placeholder bytes (e.g. control characters) → API returns an error indicating the substitution was rejected before mutation.
7. Diagnostic surface: per-font replacement counts visible in the return value for downstream use cases that want to verify coverage.

## Out of scope

- Path-style fonts (rare in body text; encoded as glyph outlines directly in content streams) — not handled.
- Text rendered via XObjects rather than direct content streams — out of scope; we don't recurse into Form XObjects in v1.
- Annotations carrying duplicate copies of body text — out of scope; consumers handle via the existing object-iterator API.
- Encryption (`/Filter /Crypt`) — `replaceTextInDocument` errors with a clear message; consumers handle decryption upstream.

## Real-world consumer

For context — this API has a real downstream consumer at `ConductionNL/openregister`'s `pdf-anonymisation` change. The OpenRegister code uses the API as documented above:

```php
use ddn\sapp\PDFDoc;

$doc = PDFDoc::from_string($pdf_bytes);
$result = $doc->replaceTextInDocument($entity_substitution_map, [
    'placeholder_pattern' => '/(\\[[A-Z]+:\\s*\\d+\\])/',
]);

// OpenRegister applies a validation gate: re-extract via smalot/pdfparser and
// assert no entity text remains. The gate uses $result['unmatched_substitutions']
// as a coverage check.
```

The use case puts a hard FOSS-only constraint on the implementation (rules out Setasign's SetaPDF-Redactor + similar commercial options) — SAPP's LGPL + minimal-dependencies stance is what makes it viable. The work in PRs #01–#07 fills the gaps that prevented this use case from working on the un-modified SAPP.

If you'd like the OpenRegister side to test against your branch as the PR rolls in, happy to do that and feed back any integration issues.

## (copy ends)
