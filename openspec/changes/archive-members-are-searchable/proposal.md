---
kind: code
depends_on: []
---

# Proposal: archive-members-are-searchable

## Summary

A zip archive is opened under hard bounds and each file inside it is extracted and searchable on its own, resolving to the owning object under the same read rights.

- Rows: 16.8 (not statutory).
- Wave 1, size L.
- Dependencies: none. Shares `ArchiveMemberEnumerator` with `openregister/upload-malware-scan` (https://github.com/ConductionNL/openregister/issues/4399); whichever lands first adds it and the other reuses it.
- No Ruben decision bears on it.
- Build rules: openspec/woo-build-rules.md

## Why

Row 16.8, "An archive is opened and each file inside it is searchable on its own", is `no` in our column (the round 1 baseline: nothing opens an archive and indexes its members). A Woo request answered with a zip of mails and attachments is a black box to search: an officer cannot find the one letter inside it, and a citizen searching the portal cannot either.

OpenRegister already reads zip containers for OOXML (`DocumentExtractor`, `OoxmlPackage`, bounded reads through `ZipArchive`), and treats `application/zip` as a generic type it does not open. This change opens real archives.

## What changes

- `ArchiveMemberEnumerator` lists the members of a zip archive (and a zip nested one level inside it) with their path, size and type, under hard bounds: member count, total uncompressed size, compression ratio and nesting depth. The bounds are settings with stated defaults.
- Text extraction extracts each supported member with the existing extractors and stores its chunks as the archive file's chunks with `positionReference.member` set to the member path.
- Search (the file search API and Nextcloud unified search) returns a member hit as the archive file plus the member path, resolving to the owning object exactly as a file hit does, under the same read rights.
- What could not be opened is reported per member (encrypted, over a bound, unsupported type), and the file's extraction status says the archive was partly indexed.
- `upload-malware-scan` uses the same enumerator, so scanning and indexing see the same members.

## What does not change

- How a single file is extracted or chunked.
- Archives are not unpacked into Nextcloud Files; members exist only as indexed text.
- tar, 7z and rar: out of scope, reported as unsupported archive types.

## Dependencies

None. `upload-malware-scan` (wave 1) consumes the enumerator; whichever lands first adds it, and the other reuses it.

## Wave and decision

Wave 1, size L. Closes 16.8.
