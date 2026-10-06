---
status: proposed
---

# file-actions

## ADDED Requirements

### Requirement: A file can be created from a URL the product fetches itself (REQ-FCU-001)

`POST /api/objects/{register}/{schema}/{id}/files` SHALL accept either `content` or `source: {url}`, and SHALL refuse a request with both or neither with 400. For `source.url` the server SHALL fetch the bytes through the shared fetcher behind `SecurityService::assertSafeFetchUrl()`, with redirects disabled, a 30 second timeout and the file size ceiling enforced while reading. The created file SHALL carry `sourceUrl`, `fetchedAt` and `sha256` in its metadata, and the object's audit trail SHALL record the fetch. The same object-level access check as a content upload SHALL apply.

#### Scenario: an integrator hands over a decision by URL
- **GIVEN** an object the caller may update and a public PDF at `https://example.org/besluit.pdf`
- **WHEN** the caller posts `{name: "besluit.pdf", source: {url: "https://example.org/besluit.pdf"}}`
- **THEN** the file is created with the fetched bytes, and its metadata carries the URL, the moment and the SHA-256

#### Scenario: an internal address is refused
<!-- @e2e exclude SSRF guard; covered by PHPUnit FilesCreateFromUrlTest::testAPrivateAddressIsRefused. -->

- **GIVEN** `source.url` `http://169.254.169.254/latest/meta-data`
- **WHEN** the caller posts it
- **THEN** the response is 422 naming the refused address and no file is created

#### Scenario: a redirect is not followed
<!-- @e2e exclude Covered by PHPUnit FilesCreateFromUrlTest::testARedirectIsNotFollowed with a stub HTTP client. -->

- **GIVEN** a URL that answers 302 to an internal address
- **WHEN** the caller posts it
- **THEN** the response is 422 and no second request is made

#### Scenario: content and source together are ambiguous
<!-- @e2e exclude Covered by PHPUnit FilesCreateFromUrlTest::testContentAndSourceTogetherAreRefused. -->

- **GIVEN** a body with both `content` and `source`
- **WHEN** it is posted
- **THEN** the response is 400 and no fetch happens

### Requirement: A failed fetch creates nothing and says why (REQ-FCU-002)

A fetch that times out, answers outside 2xx, exceeds the size ceiling or returns an empty body SHALL create no file and SHALL return 422 with `reason` (`timeout`, `status`, `too-large`, `empty`, `refused-address`). Fetched bytes SHALL pass the same upload checks as uploaded content before the file is written.

#### Scenario: an oversized file is stopped while streaming
<!-- @e2e exclude Covered by PHPUnit FilesCreateFromUrlTest::testTheCeilingStopsTheReadNotTheWrite. -->

- **GIVEN** a URL serving more bytes than the file size ceiling
- **WHEN** it is fetched
- **THEN** reading stops at the ceiling, no file is written, and the response says `too-large`
