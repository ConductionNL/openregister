# Proposal: selectielijst-reference-resolve

## Why

A result type stores its selectielijst class as the URL of the published VNG Selectielijst row. A reader needs "6.1, Ingewilligd" (board DqZaakArchivering, Selectielijstregel), and a page on Nextcloud may not call another host. Nothing on the server turned the URL into its code and name.

## What changes

- `SelectielijstReferenceResolver` reads the row behind an https URL on an allowed selectielijst host (`selectielijst.openzaak.nl`), result or process type, and keeps `{url, code, name}` in the distributed cache for a day. Any other URL is not fetched.
- `GET /api/archival/selectielijst/resolve?url=` answers it: 400 for a URL it does not resolve, 404 when the row cannot be read. Any signed-in user; the list is public reference data.
