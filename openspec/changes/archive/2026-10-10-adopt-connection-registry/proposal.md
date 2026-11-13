# Proposal: adopt-connection-registry

## Why

OpenRegister talks to a dozen systems outside itself: an LLM provider, the
OpenAnonymiser ExApp, GitHub and GitLab, an e-Depot, the Integriq sources behind
BRP, KvK and the other lookup providers, and PDOK. Four more are seams bound
through dependency injection that answer with a stand-in by default:
translation, the two DSAR seams and the office converter.

Nothing tells an admin which of these work. The status of each is spread over
settings sections, test buttons and DI bindings that only a developer can read.

The hydra umbrella change `connection-registry` (hydra#667, amended in
hydra#673) gives every app one page for this. The app declares its connections
in `lib/Settings/connections.json`, integriq keeps a row per connection in its
`app_connection` schema and works out the status, and the app reports what only
it can observe.

## What changes

- New `lib/Settings/connections.json` with sixteen connections.
- A Connections page at `/settings/connections`: an index page over
  `integriq/app_connection`, preset to `app=openregister` by its menu entry,
  admin only, and gated by `requiresApp` integriq. The menu entry sits in the
  settings foldout, so the main menu does not grow (ADR-097).
- An Add integration header action that opens
  `/apps/integriq/connections?app=openregister&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters. The pinned
  `@conduction/nextcloud-vue` 2.48.1 does not ship them.
- `ConnectionReporter` sends `ConnectionStatusReportedEvent` and
  `ConnectionRefreshRequestedEvent` by string class name behind `class_exists`.
- Reports where OpenRegister already observes a state: the LLM settings save,
  the GitHub and GitLab token tests, the e-Depot connection test and the
  OpenAnonymiser connection test. A token save asks integriq to resolve again.
- `ConnectionSeamReportJob` reports the four DI seams every six hours, never on
  a request.
- Stable ids on the LLM and text extraction settings sections, so a settings
  link lands on them.

The existing Integrations registry page (`IntegrationsView`, ADR-019) is not
touched. It shows integrations beside an object. This page shows the
connections of the app.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D4, D6, D8, D9, D12.
- integriq's `app_connection` schema, sync, listeners and overview (integriq#1996)
  and the D12 amendments on integriq branch `feat/connection-registry-amendments`
  (`reportedOnly`, `limited`, `jsonPath`, `simulatedValues`).

OpenRegister takes no hard dependency on integriq. Without it the menu entry is
hidden, the page shows the missing-app screen and no event is sent.

## Out of scope

- Webhooks, OAuth2 connections and federated object sources. Each is many
  records, which design D12 leaves out.
- Changing `ExternalIntegrationRouter` or `FleetAppId` (open PR openregister#3713).

## Rollback

Revert this change. No schema, migration or register data belongs to it. Rows
integriq synced stay in integriq until its sync runs without the file.
