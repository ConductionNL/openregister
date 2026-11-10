---
status: done
---

# e-Depot BagIt Output Format

## Purpose

RFC 8493 (BagIt 1.0) SIP serialization as a per-connection output option in `SipPackageBuilder` (archival-transfer-hardening): a connection whose `edepot_package_format` is `bagit` produces a bag with `bagit.txt`, `bag-info.txt` (payload oxum, bagging date, source-organization), a complete `manifest-sha256.txt` + `tagmanifest-sha256.txt`, and SIP content under `data/`; the plain-zip layout stays the byte-for-byte default so existing connections are unchanged, and the same content serializes losslessly in either format.

**OpenSpec changes**: [archival-transfer-hardening](../../changes/archive/2026-07-06-archival-transfer-hardening/) _(archived 2026-07-06)_

## Requirements

### Requirement: BagIt 1.0 SIP serialization option
OpenRegister's `SipPackageBuilder` SHALL offer BagIt 1.0 (RFC 8493) serialization as an output
format option alongside the existing plain-zip SIP layout, selected per e-Depot connection in the
e-Depot settings (default remains plain zip — no behaviour change for existing connections). A
BagIt package MUST contain the bag declaration (`bagit.txt` with version and encoding),
`bag-info.txt` (incl. bagging date, payload oxum, source-organization from the register/settings),
a complete `manifest-sha256.txt` covering every payload file, a `tagmanifest-sha256.txt` covering
the tag files, and the SIP content (per-object MDTO XML, metadata JSON, and payload files) under
`data/`. The same transfer content MUST serialize losslessly in either format.

#### Scenario: Connection configured for BagIt produces a valid bag

@e2e openspec/specs/edepot-bagit-output/spec.md#connection-configured-for-bagit-produces-a-valid-bag

- **WHEN** a transfer executes against an e-Depot connection whose output format is `bagit`
- **THEN** the produced package contains `bagit.txt`, `bag-info.txt`, `manifest-sha256.txt`, `tagmanifest-sha256.txt`, and all SIP content under `data/`
- **AND** every payload file's SHA-256 in the manifest matches its content
