# Proposal: a halt for one organisation's work in one app

## Why

hermiq stops one organisation's AI agent work with its own kill switch (TenantControlService, TenantKillSwitchCheck, schema `tenantcontrol`), which gate 23 flags as an app re-implementing OpenRegister tenancy. OpenRegister offers only the instance-wide flow kill switch (`KillSwitchCheck`) and organisation suspension, which locks the organisation's people out of everything (for-ruben/hermiq-gate23-gap.md, gap H1). Ruben decided (5 Oct, DECISIONS row 64, Q3): an OpenRegister halt SCOPED BY APP AND NODE-TYPE PREFIX, with reason, actor and time, stopping matching flow steps and anything an app checks through the read API.

## What changes

- `OrganisationHaltService`: engage (organisation, app, reason, optional node-type prefix defaulting to `<app>.`; actor = the signed-in user; refused without actor, app, reason or an existing organisation, and when the same halt is engaged), release (by id, actor recorded), list, `haltFor(organisation, app, nodeType?)` (the read an app asks outside flows) and `haltsMatching(nodeType)`. Active halts are one JSON list in app config `openregister/organisation_halts`; engage and release write `organisation.halt.engaged` / `organisation.halt.released` audit rows.
- `OrganisationHaltCheck`, registered beside `KillSwitchCheck` by `FlowOversightRegistrationListener`: refuses a flow step whose node type starts with a halt's prefix when the run's organisation (FlowRun::organisation) is the halted one; a run whose organisation cannot be established is refused while a halt matches its node type.
- Admin routes: `GET`/`POST /api/organisations/{uuid}/halts`, `DELETE /api/organisation-halts/{id}`.

## Impact

- New classes under `lib/Service/Flow/Oversight/`, `lib/Controller/OrganisationHaltController.php`, three routes, one listener constructor argument.
- hermiq migrates afterwards (its lane): TenantKillSwitchCheck and the tenantcontrol record move onto this service; its own owner check stays in hermiq.
