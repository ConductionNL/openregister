---
title: Office Document Sanitisation
sidebar_position: 16
---

# Office Document Sanitisation

## Overview

When OpenRegister anonymises an Office document (`.docx` or `.odt`), the entity walker can only reach the visible text runs. Office documents also carry personally-identifying information (PII) in **non-text structures** that the walker is structurally blind to — reviewer comments, tracked-change author attributes, document metadata, person-identifying field codes, custom XML data bindings, and hyperlink URLs. These regularly leak names and case numbers into published Woo/dossier documents.

The **document sanitiser** runs *ahead* of the anonymisation walker. It produces a cleaned derivative of the input by performing XML-level surgery on the ZIP container; the cleaned file is then passed to the walker for entity detection and replacement. **The original file is never modified.**

## What it strips

| Category | DOCX | ODT |
| --- | --- | --- |
| Comments | `word/comments*.xml` + `people.xml` + inline `commentRange*`/`commentReference` markers | `office:annotation` / `office:annotation-end` |
| Tracked changes | accept `<w:ins>` (keep text), drop `<w:del>` (remove text), strip `w:rsid*` revision attributes | accept `text:change-start..end`, drop `text:change`, remove the `text:tracked-changes` container |
| Document metadata | `docProps/core.xml`, `app.xml`, `custom.xml` (Author, Last-Modified-By, Title, Subject, Keywords, Description, Category, Content-Status, Company, Manager, string-typed custom props) | `meta.xml` (creator, initial-creator, title, subject, keyword, description, string-typed user-defined) |
| Person field codes | `AUTHOR`, `USERNAME`, `USERINITIALS`, `LASTSAVEDBY` (simple `<w:fldSimple>` + complex `<w:fldChar>` forms) | `text:author-name`, `text:author-initials`, `text:initial-creator` |
| Custom XML | all `customXml/item*.xml` parts; data-bound `<w:sdt>` content controls are unwrapped (visible text preserved) | — |
| Hyperlinks | `<w:hyperlink>` flattened to plain text; URL + relationship dropped | `text:a` flattened to plain text |

Timestamps (`dcterms:created`/`modified`) and non-person field codes (`DATE`, `PAGE`, etc.) are preserved. Removed parts are reconciled out of `[Content_Types].xml` and `_rels/*.rels` so the output opens cleanly in Microsoft Word and LibreOffice (no "found unreadable content" recovery).

## Metadata sentinel (design D5)

Scrubbed metadata fields are replaced with the sentinel string **`DocuDesk Anonymisation`** rather than deleted. Keeping the element with a recognisable value (a) signals in-file that the document was processed, and (b) defends against Word's "fill missing metadata on save" behaviour, which would otherwise re-populate `<dc:creator>` with the current user on the next save — re-leaking PII. The sentinel is a single constant and can be changed to a generic value (e.g. `Anonymous`) without touching the surgery logic.

## Hyperlink flattening (design D7)

Hyperlinks are flattened to their visible text; the URL (which often carries PII such as `mailto:p.jansen@…` or query strings with case numbers) and its relationship entry are dropped. The visible link text remains and is then walker-anonymised in the entity pass.

## Audit report

Each sanitisation produces a `SanitizationReport` with per-category counts (comments removed, tracked changes accepted/dropped, revision attributes stripped, hyperlinks flattened, metadata fields scrubbed, custom XML parts dropped, field codes stripped, sentinel applied). The report is PII-free (counts only — never document content) and is retained on `DocumentProcessingHandler::getLastSanitizationReport()`. Logging follows ADR-005: only file ID, MIME type, strategy class and counts are logged.

### Anonymisation log persistence

Per-run rows land in the new `openregister_anonymisation_log` table. Each row captures the file id, originating object UUID / register / schema (when applicable), MIME type, engine label, status, structured failure reason, replacement count, wall-clock duration, and — for Office documents — the JSON-serialised `SanitizationReport` on the nullable `sanitization` column. The column is `null` for non-Office runs (PDF, plain text) and pre-sanitisation legacy rows. Consumers (DocuDesk's grondslagen-summary renderer) treat `null` as "no sanitisation data; not applicable" and do not raise an error.

The entity / mapper live at:

- `lib/Db/AnonymisationLog.php` — entity with `STATUS_SUCCESS` / `STATUS_FAILURE` constants and a `getSanitizationArray()` decode helper.
- `lib/Db/AnonymisationLogMapper.php` — `findByFileId()`, `findByObjectUuid()`, `findLatestSuccessForFile()`.
- `lib/Migration/Version1Date20260611000000.php` — idempotent create-if-missing migration.

Persistence is best-effort — a DB-side failure is logged PII-free and swallowed so a successful redaction is never masked.

## Encrypted documents

Password-protected `.docx`/`.odt` cannot be sanitised without the password. The sanitiser raises a typed `SanitizationException` (reason `encrypted`); the anonymisation flow surfaces this as a caller-correctable "Cannot anonymise an encrypted document" error.

## Scope

- One-way only — the original file is the source of truth for the un-sanitised content; there is no reverse operation.
- v1 is always-on, all categories (no per-tenant or per-category toggles).
- Internal-document anchor hyperlinks (`#bookmark1`) are flattened along with external ones in v1.
