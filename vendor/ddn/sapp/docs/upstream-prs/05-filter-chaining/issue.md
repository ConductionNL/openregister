---
status: draft
target_repo: dealfonso/sapp
suggested_title: Support filter chaining (/Filter array form)
suggested_labels: enhancement, refactor
relates_to: openregister/pdf-anonymisation
prereq: 01-asciihex-decode, 02-runlength-decode, 03-ascii85-decode, 04-lzw-decode
---

# Upstream issue draft — Filter chaining

**Intended workflow:** post AFTER the four individual decoder PRs (01-04) have landed (or are well-progressed). This issue introduces the dispatch refactor that makes chaining work; trying to ship it earlier would require speculating about the API surface for filters that didn't exist yet.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add support for filter chaining — the `/Filter` array form `[/X /Y]` defined in PDF 1.7 §7.4.1 (Stream Decoding Parameters and Filter Pipelines). Currently `PDFObject::get_stream()` matches `$this->_value['Filter']` against single-name cases like `/FlateDecode`; the array form falls through to `p_error('unknown compression method')`.

The PDF spec allows `/Filter [/ASCII85Decode /FlateDecode]` (apply ASCII85 first, then FlateDecode to that result), and pairings like this are common in real-world PDFs.

## Proposed shape

Refactor the filter dispatch from inline switch-on-name into a small pipeline:

```php
public function get_stream($raw = true) {
    if ($raw === true) {
        return $this->_stream;
    }
    if (!isset($this->_value['Filter'])) {
        return $this->_stream;
    }

    // Normalise Filter into an array of name strings, in pipeline order.
    $filters = $this->_value['Filter'];
    $names = $this->normaliseFilterNames($filters);
    if ($names === null) {
        return p_error('unknown filter shape: ' . print_r($filters, true));
    }

    // DecodeParms parallel-arrays with Filter (per spec §7.4.1).
    $params_list = $this->normaliseDecodeParms($this->_value['DecodeParms'] ?? null, count($names));

    $data = $this->_stream;
    foreach ($names as $i => $name) {
        $data = $this->decodeOne($name, $data, $params_list[$i] ?? []);
        if ($data === false) {
            return p_error('failed to decode filter: ' . $name);
        }
    }
    return $data;
}

protected function decodeOne($filter_name, $data, $params) {
    switch ($filter_name) {
        case '/FlateDecode':       return self::FlateDecode(gzuncompress($data), $params);
        case '/LZWDecode':         return self::LZWDecode($data, $params);
        case '/ASCII85Decode':     return self::ASCII85Decode($data);
        case '/ASCIIHexDecode':    return self::ASCIIHexDecode($data);
        case '/RunLengthDecode':   return self::RunLengthDecode($data);
        case '/Crypt':             return p_error('encrypted streams not supported');
        default:                   return p_error('unknown filter: ' . $filter_name);
    }
}
```

`normaliseFilterNames()` accepts either:
- a single `PDFValueType` string (current behaviour),
- a `PDFValueList` of `PDFValueType` strings (the array form).

`normaliseDecodeParms()` handles the parallel-array convention from §7.4.1: when `/Filter` is an array, `/DecodeParms` (when present) is also an array of dictionaries (or `null` for filters without parameters).

The `set_stream` path gets the inverse: encode the input through the pipeline in reverse order. For round-trips through PDF documents whose original filter is a chain, this maintains byte-level fidelity.

## Acceptance test

- Decode `/Filter [/ASCII85Decode /FlateDecode]` stream → returns the same bytes as manually applying ASCII85Decode then FlateDecode.
- Round-trip via `set_stream(..., false)` followed by `get_stream(false)` → byte-equal to the original.
- Single-filter form still works (`/Filter /FlateDecode`) — backward-compat.
- Empty filter array `/Filter []` returns the stream unchanged (spec-permitted no-op).
- Unknown filter in a chain returns `p_error` after partial decoding (no crash).

## Out of scope

- Image-only filters (`/DCTDecode`, `/CCITTFaxDecode`, `/JBIG2Decode`, `/JPXDecode`) — they're already not handled for text streams; this PR doesn't address them.
- The optional `/F`, `/FFilter`, `/FDecodeParms` entries (filter pipelines on external streams) — separate concern.

## Ask

This is a small refactor of `get_stream` / `set_stream` — backward-compatible for single-filter case but touches the dispatch. Want me to keep the refactor scope tight (just the chaining + dispatch reshape) or include any other cleanup you've been planning?

## (copy ends)
