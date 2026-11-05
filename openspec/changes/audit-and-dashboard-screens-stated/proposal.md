---
kind: code
---

# Proposal: audit-and-dashboard-screens-stated

## Summary

Two built screens have no requirement that says them. The audit trail page lets an administrator browse, filter and export the trail. The dashboard shows registers, object counts and recent activity when the app opens. The specs they cite describe the service behind each screen (index-backed queries, the dashboard statistics service) but not the screen itself. This change adds one requirement per screen.

## Rows this closes

| row | capability | built in |
|---|---|---|
| hist-viewer | Browse, filter and export the audit trail in a screen | `src/views/logs/AuditTrailIndex.vue`, `src/sidebars/logs/AuditTrailSideBar.vue`, `auditTrail#export` |
| op-dashboard | See registers, record counts and recent activity on a dashboard when you open the app | `src/views/dashboard/DashboardIndex.vue`, `src/manifest.json` route `/` |

The other history, retention, automation, files, records and operate rows in gap (a) already have a requirement that says them; this PR records it as `built.requirement` in `openspec/parity/capabilities.json`.

## What changes

- `enhanced-audit-trail`: one added requirement for the audit trail page.
- `built-in-dashboards`: one added requirement for the dashboard page.

## Screens

`openregister/OrAuditTrails` and `openregister/OrDashboard`; see `screens.md`.

## Out of scope

- Audit of schema and settings edits (`hist-admin-actions`, change `history-schema-and-settings-edits-audited`).
- The per-object history tab (`openregister/OrObjectHistorie`).
