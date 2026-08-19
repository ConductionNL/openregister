# Design — Filter chaining

## Decisions

### D1. Normalise-then-apply, not switch-in-place

The current dispatch hard-codes `switch ($this->_value['Filter'])` and only handles single-name `PDFValueType` inputs. We don't extend the switch with array-cases — that'd be brittle. Instead:

1. **Normalise** the `/Filter` entry into an ordered list of filter-name strings.
2. **Normalise** the `/DecodeParms` entry into a parallel list of param dicts.
3. **Walk** the list, dispatching each filter through `decodeOne()`.

This shape works for both single-name and array forms with no per-shape branching in the apply loop.

### D2. Backward-compatibility guarantee

For single-name inputs, the refactored dispatch MUST produce byte-identical output. The simplest proof is that `examples/testdoc.pdf` (FlateDecoded body) re-extracts byte-equal post-refactor. We make this explicit in the spec (REQ-01) and in the PR description.

The `decodeOne()` cases internally are the same code that the old switch had — we're not changing the per-filter behaviour, only the dispatch shape.

### D3. `decodeOne()` as the per-filter dispatch

A simple inner switch on filter name:

```php
protected function decodeOne(string $filter_name, string $data, array $params): string|false {
    switch ($filter_name) {
        case '/FlateDecode':
            return self::FlateDecode(gzuncompress($data), $params);
        case '/LZWDecode':
            return self::LZWDecode($data, $params);
        case '/ASCII85Decode':
            return self::ASCII85Decode($data);
        case '/ASCIIHexDecode':
            return self::ASCIIHexDecode($data);
        case '/RunLengthDecode':
            return self::RunLengthDecode($data);
        case '/Crypt':
            return p_error('encrypted streams are not supported');
        default:
            return p_error('unknown filter: ' . $filter_name);
    }
}
```

Image-only filters (`/DCTDecode`, `/CCITTFaxDecode`, `/JBIG2Decode`, `/JPXDecode`) intentionally fall through to `p_error`. They're not relevant to the text-content-stream use case; if a PDF object includes them, the consumer should detect that and skip (image objects are out of scope for the text-replacement use case anyway).

### D4. Parameter alignment with the filter array

PDF 1.7 §7.4.1 says: when `/Filter` is an array, `/DecodeParms` (when present) is also an array of dictionaries, parallel-indexed. A filter without parameters has a corresponding `null` entry in the `/DecodeParms` array.

The normaliser must:

- Recognise `PDFValueList` as the array form.
- Recognise `null` entries within and substitute an empty dict.
- Pad short `/DecodeParms` arrays to the filter count (rare but spec-permitted).

### D5. Encode path inversion

`set_stream($bytes, false)` runs the filters in REVERSE order on the encode side. If the original `/Filter` was `[/ASCII85Decode /FlateDecode]`, the encoder:

1. FlateDecode-encode the input bytes.
2. ASCII85-encode that result.

This produces the byte stream readable by the matching decode chain.

### D6. No new dispatch abstraction (`FilterRegistry`)

A nicer design would be a `FilterRegistry` mapping names → classes. We don't introduce that here — the dispatch in `decodeOne()` is 7 lines of switch and lives where the rest of the filter logic lives. If the maintainer prefers the registry approach, happy to refactor — flagged in the issue's "Ask" section.

### D7. `Filter` and `DecodeParms` keys access

Currently `$this->_value['Filter']` returns whatever PDFValue-derived type is stored. For the normaliser:

- `PDFValueType` (a single name): wrap in a 1-element array.
- `PDFValueList`: iterate its items.
- Anything else: `p_error("unrecognised /Filter shape")`.

This keeps the type discipline tight and gives a clear error path on malformed PDFs.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Refactor breaks the single-name FlateDecode path | Low | REQ-01 byte-equivalence check against `examples/testdoc.pdf` |
| `decodeOne` swallows a filter-specific error that the old switch didn't | Low | Every error path runs through `p_error`; behaviour matches the old switch's "unknown compression method" |
| Maintainer prefers the registry abstraction now | Medium | Offer in the issue's "Ask"; defer is fine but documented |
| Parallel-array DecodeParms edge cases (mismatched length, embedded nulls) | Medium | Spec REQ-04 covers normaliser semantics; unit-test all three malformed-input branches |


---

---

## Implementation note

See `openspec/changes/feat-filter-chain-dispatch/design.md` (the canonical artefact) for the shipped-implementation note, decisions D1-D6, and the method-name listing. This file intentionally keeps the original proposal/design/tasks content for upstream submission to dealfonso/sapp; the implementation specifics are tracked in the OpenSpec change to avoid duplicate-source drift across four files.
