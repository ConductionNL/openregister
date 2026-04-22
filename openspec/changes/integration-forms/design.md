# Design: Integration — Forms

> Umbrella decisions apply.

## Approach

`FormResponseService` wraps NC Forms REST API. `FormsProvider` delegates. Tab supports two link modes: (1) link an individual response, (2) link a form so future responses auto-link to the object.

## Architecture Decisions

### AD-1: Two link modes — individual response vs form-mapping

**Decision**: Links table has two row types: `response_link` (object ↔ specific response) and `form_mapping` (object ↔ form id, causing future responses to auto-link).

**Why**: Case intake pattern — when a citizen submits a new form, the response needs to auto-create or auto-link to a case. Form-mapping covers this; individual-response covers ad-hoc linking of historical responses.

**Trade-off**: Two row types, slightly more complex link schema. Worth it — without form-mapping, integration is useless for the intake workflow.

### AD-2: Response view is read-only

**Decision**: Responses render question + answer with no edit affordance. Editing goes to Forms app.

**Why**: Responses should be immutable in Forms (audit trail). Editing in OR would desync.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/FormResponseService.php` | Wraps Forms REST API |
| `lib/Controller/FormResponsesController.php` | REST endpoints |
| `lib/Db/FormLink.php` + `FormLinkMapper.php` | Entity + mapper |
| `lib/Migration/Version0xxxDate…CreateFormLinks.php` | Migration |
| `lib/Service/Integration/Providers/FormsProvider.php` | Provider |
| Unit tests |

### Modified — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | DI-tag + service registration |
| `appinfo/routes.php` | `/api/objects/.../forms` routes |

### New files — Frontend

| File | Purpose |
|---|---|
| `CnFormsTab/CnFormsTab.vue` | Linked responses + link flows |
| `CnFormsCard/CnFormsCard.vue` | 4 surfaces |
| `src/integrations/builtin/forms.js` | Registration |
| Barrels + tests |

## Risks

| Risk | Mitigation |
|---|---|
| Forms API versioning across NC releases | Adapter in `FormResponseService`; version-detect at bootstrap |
| Very long responses overflow widget | Truncate + expand; use full-page response viewer for edit-like experience |
| Auto-link via form-mapping misfires | Link creation runs in a post-submit hook with object-lookup logic documented; errors fall back to unlinked response + admin alert |
