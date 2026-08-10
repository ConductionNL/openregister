# Mail Sidebar

## Overview

The mail sidebar integrates OpenRegister with the Nextcloud Mail app by injecting a sidebar panel that displays objects linked to the currently viewed email.

## Architecture

### Backend

- **EmailLink** (`lib/Db/EmailLink.php`) — Entity mapping emails to objects via `openregister_email_links` table.
- **EmailLinkMapper** (`lib/Db/EmailLinkMapper.php`) — Database queries for email links with findByAccountAndMessage, findBySender, findExistingLink.
- **EmailService** (`lib/Service/EmailService.php`) — Business logic for reverse-lookup (findByMessageId, findObjectsBySender), quickLink creation, and deleteLink.
- **EmailsController** (`lib/Controller/EmailsController.php`) — REST endpoints: `GET /api/emails/by-message/{accountId}/{messageId}`, `GET /api/emails/by-sender?sender=`, `POST /api/emails/quick-link`, `DELETE /api/emails/{linkId}`.
- **MailAppScriptListener** (`lib/Listener/MailAppScriptListener.php`) — Injects sidebar script into Mail app when conditions are met (Mail enabled, user has register access).

### Frontend

- **mail-sidebar.js** — Webpack entry point that mounts Vue sidebar into Mail app DOM.
- **MailSidebar.vue** — Root component hosting three `NcAppSidebarTab` children: Connections, Connect, Entities.
- **ObjectsTab.vue** — "Connections" tab; the objects linked to the current email.
- **ActionsTab.vue** — "Connect" tab; per-schema search-and-link plus create-from-email. This is the
  search-and-link affordance REQ-001 specifies, and it replaced the old inline "Link to Object"
  button and its modal.
- **EntitiesTab.vue** — "Entities" tab; the extracted-entity view.
- **LinkedObjectsList.vue** — Displays explicitly linked objects for current email.
- **SuggestedObjectsList.vue** — Displays sender-based discovery results.
- **ObjectCard.vue** — Card component with title, schema, register, deep link, unlink button.
- **useMailObserver.js** — Composable observing Mail app URL changes (hash-based routing).
- **useEmailLinks.js** — Composable for API state management with caching and abort control.
- **emailLinks.js** — Axios API wrapper for all email link endpoints.

### Styling

- **css/mail-sidebar.css** — NL Design System compatible styles using Nextcloud CSS variables.

## API Routes

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/emails/by-message/{accountId}/{messageId}` | Objects linked to specific email |
| GET | `/api/emails/by-sender?sender=` | Objects from same sender |
| POST | `/api/emails/quick-link` | Link email to object |
| DELETE | `/api/emails/{linkId}` | Remove email-object link |

## Database

Table `openregister_email_links` with columns: id, mail_account_id, mail_message_id, mail_message_uid, subject, sender, mail_date, object_uuid, register_id, schema_id, linked_by, linked_at.

Indexes: composite on (mail_account_id, mail_message_id), sender, object_uuid. Unique constraint on (mail_account_id, mail_message_id, object_uuid).

## Dependencies

Requires the Nextcloud Mail app to be installed for sidebar functionality. OpenRegister works normally without Mail app.
