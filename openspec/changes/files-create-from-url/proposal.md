---
kind: code
depends_on: []
---

# Proposal: files-create-from-url

## Why

Row 1.18, "A caller hands the product a URL, and the product fetches the document's bytes itself", is `partial` in our column (`baseline/openwoo.tsv`). `FilePropertyHandler::processStringFileInput()` already fetches bytes from an http(s) URL behind `SecurityService::assertSafeFetchUrl()` with redirects off, but only for a file-typed schema property. `POST /api/objects/{register}/{schema}/{id}/files` (`FilesController::create()`) requires `content` and refuses a URL, and opencatalogi's publication schema has no file-typed property, so the stated case (hand a publication a document by URL) does not work.

Decision D5 (Ruben, 2026-10-05) resolved the conflict with opencatalogi's `integration-publish-by-reference` REQ-PBR-001, which stores a URL as a reference and never fetches it: both behaviours exist, and the caller chooses explicitly. Link stays REQ-PBR-001 in opencatalogi. Copy is this change, in OpenRegister.

## What changes

- `FilesController::create()` accepts `source: {url}` in place of `content`. The bytes are fetched by the same code `FilePropertyHandler::fetchFileFromUrl()` uses, behind `assertSafeFetchUrl()`, with no redirects, a 30 second timeout and the instance's file size ceiling enforced while streaming.
- `content` and `source` together are refused; one of the two is required. There is no implicit fetch: a plain string in `content` stays content.
- The created file records where it came from (`sourceUrl`, `fetchedAt`, `sha256`) in its metadata, and the fetch is on the object's audit trail.
- A fetch that fails (refused address, timeout, non-2xx, over the size ceiling, empty body) creates no file and returns 422 naming the reason.
- The fetched bytes go through the same upload checks as any upload (`ExecutableContentDetector`, and the malware scan once `upload-malware-scan` lands).

## What does not change

- Linking by reference: opencatalogi's REQ-PBR-001.
- The file-typed property path, which already fetches.

## Dependencies

None. `opencatalogi/integration-publish-by-reference` (built as written) offers the editor the choice between link and copy and calls this endpoint for copy; its spec names the call.

## Wave and decision

Wave 1, size S. Implements D5 for 1.18 (both, chosen explicitly). Closes 1.18.
