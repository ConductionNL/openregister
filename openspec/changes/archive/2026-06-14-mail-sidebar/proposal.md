# Mail Sidebar

## Problem

When a Nextcloud user views an email in the Mail app, there is no way to see which OpenRegister objects are related to that email. Case handlers working with Procest, ZaakAfhandelApp, or Pipelinq must manually search for cases by copying sender addresses or subject lines from emails into the OpenRegister search. This context-switching breaks workflow continuity and wastes time.

The nextcloud-entity-relations spec establishes the `openregister_email_links` table that maps emails to objects, and the `EmailService` that manages those links. However, this linkage is only visible from the OpenRegister side (object detail -> emails tab). There is no reverse integration: when viewing an email in the Mail app, users cannot see or manage the objects linked to that email.

## Context

- **Existing infrastructure**: `openregister_email_links` table, `EmailService`, `EmailsController` (from nextcloud-entity-relations spec)
- **Nextcloud Mail integration point**: The Mail app does not provide a formal sidebar extension API. Integration requires injecting a sidebar panel via Nextcloud's collaboration resources system or registering a custom script that extends the Mail app UI
- **Alternative approach**: Nextcloud 28+ supports apps registering "additional scripts" that load into other apps' pages via `OCP\Util::addScript()`
- **Consuming apps**: Procest (case workflows), Pipelinq (pipeline management), ZaakAfhandelApp (ZGW case handling)
- **Related specs**: nextcloud-entity-relations (email linking), object-interactions (notes/tasks/files), deep-link-registry (deep links to objects)

## Proposed Solution

Build a Mail sidebar integration that shows OpenRegister objects related to the currently viewed email. The integration consists of:

1. **Backend API** -- A reverse-lookup endpoint that finds objects by mail message ID, mail account ID, or sender email address. This leverages the existing `openregister_email_links` table.
2. **Mail app script injection** -- Use `OCP\Util::addScript()` to inject a JavaScript bundle into the Mail app that renders a sidebar panel showing linked objects.
3. **Sidebar panel UI** -- A Vue component that displays linked objects with key metadata (title, schema, register, status), allows quick linking/unlinking, and provides a "search and link" flow for associating new objects with the email.
4. **Auto-suggestion** -- When viewing an email, automatically query for objects that match the sender's email address, even if not explicitly linked, providing discovery of potentially relevant cases.

## Scope

### In scope
- Reverse-lookup API endpoint (find objects by mail message/sender)
- Mail app script injection via `OCP\Util::addScript()`
- Sidebar panel Vue component for the Mail app
- Display of linked objects with metadata
- Quick link/unlink actions from the sidebar
- Search-and-link flow (search objects, link to current email)
- Auto-suggestion of objects matching sender email address
- Deep links from sidebar to object detail in OpenRegister
- i18n support (Dutch and English)

### Out of scope
- Sending emails from OpenRegister (n8n's responsibility)
- Modifying the email itself
- Integration with other mail clients (Thunderbird, Outlook)
- Creating new objects from the sidebar (navigate to OpenRegister for that)
- Nextcloud Talk/Spreed sidebar integration (separate future change)
