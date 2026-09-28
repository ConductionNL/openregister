# Design: export-pdf-house-style

Read at openregister development 555af7212 and thematiq development fd992ea
(`openspec/changes/surfaces-document-house-style/design.md`).

## Context

- `ExportService::exportToPdf()` (`lib/Service/ExportService.php:332`) builds
  HTML with a fixed stylesheet (`:545-550`: DejaVu Sans, a dark table header),
  renders it with Dompdf with `isRemoteEnabled` false, and writes the page
  number with `Canvas::page_text()` (`:560-590`). No logo, font or footer.
- Thematiq's profile (its design D1):
  `DocumentStyleService::forUser(?string $uid): array` returning
  `tokenSet`, `organisation`, `logo {url, mime}`, `cover`, `colours
  {primary, primaryText, text, background, accent}`, `fonts {heading, body:
  {family, url|null}}` and `footer {lines, accessibilityUrl, privacyUrl}`.
  Read in-process by resolving `OCA\Thematiq\Service\DocumentStyleService`
  from the server container when thematiq is installed (its design D2).
- Thematiq's `appinfo/info.xml` on development has `<id>thematiq</id>` and
  namespace `Thematiq`.

## D-1: a duck-typed reader that never fails the export

`DocumentStyleReader::forUser(uid)` checks `IAppManager::isEnabledForUser('thematiq')`
and `class_exists('OCA\Thematiq\Service\DocumentStyleService')`, resolves it
from the container, and calls `forUser()`. Any miss or throw returns null and
logs once. The class name is the one thematiq's change publishes; if thematiq
renames it, the reader's unit test with the real class name is the place that
must change with it.

## D-2: remote stays off, assets are inlined

Dompdf keeps `isRemoteEnabled` false. The logo is read through Nextcloud's own
app data or URL generator in-process (the profile's URL points at thematiq's
route on the same instance) and embedded as a data URI, capped at 1 MB and to
PNG, JPEG and SVG sanitised the way thematiq sanitises its logo. Custom fonts
with a `url` are fetched in-process and registered with Dompdf's font metrics
from a temporary file; system fonts keep DejaVu Sans. A font that fails to load
falls back to DejaVu Sans for that role.

## D-3: layout

Header: logo left, the export title and filter line right. Table header
background: `colours.primary`, text `colours.primaryText`. Footer on every page
through `page_text()`: the footer lines, then the accessibility and privacy
URLs, with the page number on the right as today.

## D-4: which user

The profile is read for the requesting user, so a group-mapped house style
applies. A scheduled export with no session uses the instance default profile
(`forUser(null)`).
