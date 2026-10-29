# PDF libraries

OpenRegister reads and edits PDFs with two LGPL libraries. Each one sits behind a single class, so swapping a library touches one file.

| Library | Licence | Used for | The only class that calls it |
|---|---|---|---|
| `smalot/pdfparser` | LGPL-3.0 | Reading the text layer | `lib/Service/TextExtraction/PdfExtractor.php` (`extract()`, `extractText()`) |
| `ddn/sapp` | LGPL-3.0-or-later | Rewriting text in content streams, stripping metadata, reading the structure tree | `lib/Service/File/Pdf/` (`PdfTextReplacer`, with its helpers `PdfMetadataSanitizer` and `PdfStructureInspector`) |

Other services call those classes, never the libraries. When you add PDF code, go through them too.

## Why ddn/sapp comes from a fork

`composer.json` requires `ddn/sapp` from the branch `feat/chained-filter-text-replace` of [ConductionNL/sapp](https://github.com/ConductionNL/sapp), pinned to a commit, instead of a tagged release of [dealfonso/sapp](https://github.com/dealfonso/sapp).

We checked on 2026-10-29 whether a tagged upstream release can replace it. It cannot yet:

- The fork is 71 commits ahead of upstream `main`. Upstream's newest release is 1.5.9 (2026-09-17).
- Anonymisation depends on two methods that exist only in the fork: `PDFDoc::replace_text_in_document()` (text replacement inside FlateDecode content streams, with the font switch and Helvetica fallback) and `PDFDoc::buildFontContext()`. Upstream 1.5.9 has neither.
- The fork also adds the ASCIIHexDecode, RunLengthDecode and ASCII85Decode filters and array-form `/Filter` chains, which text replacement needs to decode real-world content streams.
- None of this has been offered upstream yet. The fork carries the plan for a series of upstream pull requests in `docs/upstream-prs/`.

The fork cannot be avoided either. No library with an MIT or BSD licence edits PDF content streams in PHP: `setasign/fpdi` imports pages but cannot rewrite them, and SetaPDF is commercial.

The way out is to send the fork's changes upstream as that series of pull requests. Once upstream tags a release with them, drop the `repositories` entry and require that release with a normal version range. The `pdf-anonymisation` spec describes the same transition.
