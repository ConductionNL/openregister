---
status: draft
target_repo: dealfonso/sapp
suggested_title: Add /ASCIIHexDecode filter support
suggested_labels: enhancement
relates_to: openregister/pdf-anonymisation-discovery
---

# Upstream issue draft — `/ASCIIHexDecode`

**Intended workflow:** post this body verbatim (or with your edits) as a new issue on `dealfonso/sapp` from your own GitHub account. Once posted, paste the issue URL back below for the audit trail.

**Posted at:** _(fill in once posted)_

---

## Issue body (copy from here)

## Summary

Add `/ASCIIHexDecode` to the supported stream filters in `PDFObject::get_stream()` + `PDFObject::set_stream()`. Currently only `/FlateDecode` is handled — any object with a different `/Filter` value falls through to `p_error('unknown compression method ...')` and the stream can't be read or written.

## Why this filter, why now

I'm working on a downstream use case (text replacement in PDFs as part of a GDPR-anonymisation pipeline) that needs SAPP to read content streams across the filter set the PDF 1.7 spec defines for text. The plan is a series of small, focused PRs adding one filter at a time, plus eventually a higher-level text-replacement API on top.

Starting with `/ASCIIHexDecode` because it's the simplest of the bunch (~10 lines of decoder logic, no external dependencies, no compression library involved) — gives us a clean first PR to confirm we're aligned on scope, code style, and where in the codebase new decoders should live, before we move to the meatier ones (LZW, ASCII85, RunLength).

## Proposed API

Mirror the existing `FlateDecode` pattern in `src/PDFObject.php` so the change reads as a natural extension:

```php
// New static helper, sibling of FlateDecode():
protected static function ASCIIHexDecode($_stream) {
    // Strip whitespace per PDF 1.7 §7.4.2; '>' is the EOD marker.
    $cleaned = preg_replace('/\s+/', '', $_stream);
    $eod = strpos($cleaned, '>');
    if ($eod !== false) {
        $cleaned = substr($cleaned, 0, $eod);
    }
    if ((strlen($cleaned) % 2) !== 0) {
        // Odd-length input: spec says treat trailing nibble as if followed by 0.
        $cleaned .= '0';
    }
    $decoded = @hex2bin($cleaned);
    if ($decoded === false) {
        return p_error('ASCIIHexDecode: invalid hex characters in stream');
    }
    return $decoded;
}

// New case in get_stream():
case '/ASCIIHexDecode':
    return self::ASCIIHexDecode($this->_stream);

// New case in set_stream() (inverse — for round-tripping):
case '/ASCIIHexDecode':
    $stream = bin2hex($stream) . '>';
    break;
```

Single-filter form only — `/Filter` as a plain name, not the array-of-filters form. That bigger refactor (filter chaining via `[/ASCII85Decode /FlateDecode]`) is a separate concern; happy to open it as a follow-up issue if you want me to take it on.

## Acceptance test

The `examples/` directory doesn't include an ASCIIHexDecode-wrapped sample, so I'd add a small fixture (a few KB) covering:

1. Parse a PDF whose content stream uses `/Filter /ASCIIHexDecode` → `get_stream(false)` returns the decoded bytes.
2. Modify and write back via `set_stream(..., false)` → re-parse the output → bytes round-trip cleanly.
3. Edge cases: whitespace within the hex stream (spec-permitted), odd-length payload (spec-defined trailing-zero handling), invalid hex character (returns error via `p_error`, doesn't crash).

If there's a preferred location / shape for verification scripts (looking at `pdfdeflate.php` / `pdfcompare.php` as inspiration) I'll match that — I noticed the repo doesn't carry a `tests/` directory, so I'll mirror the script-in-root convention you already use.

## Explicitly out of scope here

To keep this PR small:

- `/LZWDecode`, `/ASCII85Decode`, `/RunLengthDecode` — each their own follow-up issue.
- Filter chaining (`/Filter [/X /Y]` array form) — separate refactor, possibly precondition for the others.
- Higher-level text-replacement API — much bigger; would be a separate proposal once the filter coverage is in.

## Ask

Before I open the PR — are you OK with this scope and the proposed integration point (extending the existing `get_stream` / `set_stream` switch rather than introducing a separate filter-registry abstraction)? If yes, I'll branch off `main`, push, and open the PR referencing this issue. If you'd prefer a different shape (e.g. extract filters into a `Filters/` subdirectory now), happy to follow that lead.

I'm working from a fork at `ConductionNL/sapp` (a long-lived `work/text-replacement` integration branch where the eventual full set of features is staged for downstream testing); each upstream-targeted PR will branch off your `main` directly so the diffs stay clean and rebasable.

Thanks for SAPP — the LGPL choice + the minimal-dependencies stance is what made it the right pick for our use case after we ruled out the commercial alternatives.

## (copy ends)
