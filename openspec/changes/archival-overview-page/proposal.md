---
kind: code
depends_on: []
---

# Proposal: archival-overview-page

## Summary

An archivist gets one page in OpenRegister for the archiving work: the destruction lists with their approval, the records under a legal hold, the transfers to the e-Depot and the selection list in force. The page is drawn on board `openregister/OrArchivering` ("Archivering", identity.conduction.nl/screens).

## Why

The archiving process is built on the server side: destruction lists with approval and rejection (`/api/archival/destruction-lists`), legal holds per matter, e-Depot transfer lists with approval and retries (`/api/transfers`) and the selection list import (`/api/archival/selectielijst`). None of it has a screen. An archivist can only reach it through the API, and the board-review list (`FLEET-LIVEURL-GAPS.md`) has `OrArchivering` under "not built: no route, component or modal".

## What changes

- **One read for the page.** `GET /api/archival/overview` answers the counts per tab, the destruction lists with a title, the name of who drew them up and a translated status, the records under an active legal hold, the transfer lists, the last e-Depot transfer and the selection list in force. Archivists and admins only, like every archival endpoint.
- **Legal holds are found, not assumed.** The overview lists the records whose retention carries an active hold, through the same paged scan the destruction sweep uses, and says when the scan was cut short.
- **Draw up a list from the page.** `POST /api/archival/destruction-lists/draw-up` does now what the daily sweep does: every record eligible today and not on an open list goes on a new list, through the existing creator. It is the page's primary action.
- **A page `Archival` at `/archival`**, under Administration next to the GDPR register, declared as a manifest fragment (ADR-037) over library widgets: tabs for destruction lists, legal holds, e-Depot transfers and the selection list; a notice for records left off a list by a hold; e-Depot and selection list fact cards. Approve and reject on a destruction list call the existing endpoints.

## What does not change

- How a destruction list is built, approved or carried out, how a hold is placed, how a transfer runs. The page reads and calls what exists.
- The legacy `GET /api/archival/legal-holds`.

## Capabilities

### Modified capabilities

- `archival-destruction-workflow`: an overview read and the page that shows it.
