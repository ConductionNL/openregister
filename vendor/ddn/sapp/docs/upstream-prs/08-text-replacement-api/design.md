# Design — `replaceTextInDocument()` flagship API

## Decisions

### D1. Single public method on `PDFDoc`

The API is `PDFDoc::replaceTextInDocument(array $substitutions, array $options = []): array`. One entry point on the class consumers already use (`PDFDoc::from_string`, `$doc->to_pdf_file_s`). Discoverable, simple to document.

Power users who want to compose primitives directly (call `FontEncodingResolver::resolveAll` themselves, walk objects via `get_object_iterator`, manually run `TextContentStreamFlattener::flatten`) still can — the underlying classes are all public. The flagship method is the "happy path" for the common case.

### D2. Options dictionary

```php
$defaults = [
    'replacement_font' => '/Helvetica',          // one of the 14 PDF standard base fonts
    'font_switch_strategy' => 'inline',          // emit Tf operators before/after each replacement
    'longest_first' => true,                     // sort substitutions DESC by needle length before matching
    'collapse_adjacent_duplicates' => true,      // post-pass regex collapse for adjacent identical placeholders
    'placeholder_pattern' => null,               // regex (PCRE) the post-collapse pass uses; default suits OpenRegister's [TYPE: id] format
    'match_case_sensitive' => true,              // case-sensitive byte matching
    'skip_unresolvable_fonts' => true,           // when a font has no /ToUnicode and we can't translate, skip rather than throw
];
```

The defaults are tuned for the GDPR anonymisation use case but each is independently sensible.

`placeholder_pattern` default is `null`; when null and `collapse_adjacent_duplicates` is true, the helper uses `(\[[A-Z]+:\s*\d+\])` (matches OpenRegister's `[PERSON: 7]` style). Callers using a different placeholder convention pass their own regex.

`font_switch_strategy` is enum: `'inline'` (default, what the use case needs), `'document-wide'` (switch once per content stream — lower fidelity, smaller diff; deferred to follow-up).

### D3. Algorithm

```
1. Validate inputs.
   - $substitutions: array<string, string> with non-empty needles.
   - Encrypted PDF → throw TextReplacementException.

2. Resolve font encodings.
   - $resolutions = $resolver->resolveAll($this) — see PR #06.

3. Register placeholder font on every page's /Resources/Font.
   - $placeholder_resource_name = BaseFontFallback::register($this, $options['replacement_font']);

4. For each content-stream object:
   a. $decoded = $obj->get_stream(false)     ← PR #01-#05
   b. $flattened = TextContentStreamFlattener::flatten($decoded)    ← PR #07
   c. Walk operators in $flattened. Maintain font-state stack
      (current $Tf font; updated on each Tf operator).
   d. For each Tj operator's string operand:
      i. Look up the current font's FontResolution.
      ii. For each substitution (longest_first): build needle bytes via
          $resolution->unicodeToBytes($needle).
          If unicodeToBytes returns null, skip this substitution for this font.
      iii. Search the Tj string bytes for needle-byte matches.
      iv. On match, rewrite to:
            (prefix bytes) Tj
            /F-Replacement <current size> Tf
            (placeholder bytes — Helvetica/WinAnsi encoding) Tj
            /<current font> <current size> Tf
            (suffix bytes) Tj
   e. Apply collapse_adjacent_duplicates regex post-pass on each Tj string.
   f. $reencoded = $obj->set_stream($modified, false)     ← inverse pipeline
   
5. Rebuild xref. SAPP's to_pdf_file_s(true) handles this.

6. Return diagnostics:
   [
     'replacements_made' => int,
     'per_font_counts' => array<font_name, int>,
     'per_substitution_counts' => array<needle, int>,
     'unmatched_substitutions' => array<needle, no_match>,
     'fonts_skipped' => array<font_name, reason>,
     'bytes_changed' => int,
   ]
```

### D4. Font-state tracking

Inside a content stream, the active font is set by `Tf` operators and persists until the next `Tf`. The walker maintains a stack:

- `BT` (begin text object): push the current font (likely null at first).
- `Tf`: set current font.
- `ET` (end text object): pop. (PDF spec says font state is scoped to text objects within a `q`/`Q` graphics-state save/restore; we honour that.)
- `q` / `Q`: save / restore the entire text-state, including current font.

This is the minimum state tracking needed to know which font's encoding to apply at each Tj. The walker tolerates streams without explicit BT/ET (some PDFs put Tj outside text objects — uncommon but spec-permitted in v1.5+).

### D5. Substitution matching — longest-first, no overlap

With multiple substitutions sharing characters (e.g. `"Jan Jansen"` AND `"Jansen"` mapped to the same `[PERSON: 7]`), the longest needle wins at any given position. After a match, advance past the matched bytes; subsequent matches start at the position after the match end.

The `longest_first` option controls this. When `false`, substitutions are matched in iteration order (useful for testing edge cases).

### D6. Per-font byte translation

For each font, the substitution map's Unicode needles get translated to that font's byte sequences via `FontResolution::unicodeToBytes()`. The resulting per-font byte map is cached per `replaceTextInDocument()` call (the resolution itself is cached at the resolver level — see PR #06 REQ-06).

If a needle contains characters the font can't represent (`unicodeToBytes` returns null), that needle is silently skipped for that font. The diagnostic surface records this in `fonts_skipped`.

### D7. Font switch on replacement

The replacement text (`[PERSON: 7]`) gets rendered in Helvetica (or whichever base font the options name), not the original font. Reasons:

- The original font might be a SUBSET — the glyphs for `[`, `]`, `:`, `0-9` might not be present. Even if the encoding table has them, the actual glyph data might be absent in a subset font.
- Helvetica is one of the 14 PDF standard fonts that every PDF reader MUST render natively without embedding. The placeholder's ASCII characters are guaranteed to render.

The font switch is "inline":

```
... (text before match) Tj
/F-Replacement <size> Tf
([PERSON: 7]) Tj
/F<original-font-name> <size> Tf
(text after match) Tj
```

The original font is restored after the placeholder so following text in the same Tj operator renders unchanged.

### D8. Collapse adjacent duplicates (post-pass)

Variants of one entity might produce multiple placeholders. Example: needles `"Jan Jansen"`, `"Jansen"`, `"Jan"` all map to `[PERSON: 7]`. A Tj operator containing both `"Jan"` and `"Jansen"` adjacent (e.g. `"Jan Jansen"` matched as two parts via longest-first prioritisation gone awry, OR via the input having `"Jan-Jansen"` style hyphenation) might produce `[PERSON: 7] [PERSON: 7]` or `[PERSON: 7]-[PERSON: 7]`.

The collapse regex matches:
- `[<TYPE>: <id>][ \-_][<TYPE>: <id>]` (separator: space, hyphen, or underscore)
- Same TYPE and id on both sides → replace with single `[<TYPE>: <id>]`
- Different TYPE or id → preserved (two distinct entities, not a single one)

Run iteratively until no more matches.

### D9. Encrypted PDFs throw

If `$this->is_encrypted()` is true (SAPP-existing check), throw `TextReplacementException` with `reason: 'encrypted_pdf'`. The consumer should decrypt upstream; SAPP doesn't handle decryption itself.

### D10. Out-of-scope: Form XObjects, annotations, bookmarks

The v1 API walks page content streams only. Text in Form XObjects (`/Type /XObject /Subtype /Form`), annotation `/Contents`, and outline `/Title` entries is NOT replaced. The diagnostic surface doesn't even surface that this text exists.

For the GDPR anonymisation use case, the consumer SHOULD additionally:
- Use the PDF metadata sanitiser (separate concern, lives in OpenRegister).
- Apply a validation gate that re-extracts the entire output via smalot/pdfparser and checks for residual entity text — this catches Form XObject + annotation cases too.

We don't try to make the SAPP-side API responsible for all-layer coverage; the consumer's validation gate is the safety net.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| The flagship PR is too big for a single review | High | Already discussed in #06 — offer split if the maintainer prefers. This PR ALWAYS depends on #01–#07 being merged or in flight — the PR's diff is the composition layer, not the primitives themselves |
| Replacement injection produces malformed content streams | Medium | Round-trip every fixture through `set_stream` → `get_stream` → re-parse; verify each is well-formed |
| Font-state tracking misses a state-change operator | Medium | Test with realistic Word-generated content streams that use q/Q + BT/ET nesting |
| Performance — large PDFs trigger O(n × m) blow-up across substitutions and operators | Low | Substitution-count is bounded (operator-supplied list, usually <100 entries); per-operator search is bounded by operator size; overall O(operators × substitutions) is acceptable |
| Helvetica fallback renders wrong on a weird PDF reader | Low | Helvetica is a PDF base font; test against Adobe Acrobat, Firefox, Chrome, evince |
| Substitution map keys that produce invalid placeholder bytes (control chars) crash the encoder | Low | Validate input substitutions: reject map values containing control characters or characters outside the Helvetica/WinAnsi range |

---

> **Implementation note**: canonical contract + decision log + as-shipped notes live in `openspec/changes/feat-text-replacement-api/` (`proposal.md`, `design.md`, `tasks.md`, and `specs/text-replacement/spec.md`). Key as-shipped facts: public method is `replace_text_in_document` (snake_case per upstream convention); Helvetica subset-font fallback q/Q-wraps placeholders the active font cannot encode (resource name `/F-fb-anonym`, collision-handled); 12-key diagnostic surface frozen and locked via `@phpstan-type ReplaceTextStats`; input validation rejects empty needles + placeholders containing control chars or `()\`.
