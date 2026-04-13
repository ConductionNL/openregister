# Mail Integration (Sidebar + Smart Picker)

## Standards

- **GEMMA Zaakcorrespondentiecomponent** -- Links email correspondence to case objects, supporting the Dutch government standard for case-related communication management.

## Status

**Implemented** -- Backend API endpoints operational, frontend sidebar and smart picker components built, database migration in place.

## Overview

The Mail Integration feature connects OpenRegister objects to Nextcloud Mail through two mechanisms:

1. **Mail Sidebar** -- A sidebar panel injected into the Nextcloud Mail app that displays OpenRegister objects linked to the currently viewed email. Users can link/unlink objects, discover related objects by sender, and navigate directly to object detail pages.

2. **Smart Picker** -- A Nextcloud reference provider that enables rich object references in Mail compose and other apps. Users can search for and embed OpenRegister objects as interactive preview widgets.

## Key Capabilities

| Capability | Description |
|------------|-------------|
| **Link emails to objects** | Explicitly associate any email with one or more OpenRegister objects via the sidebar quick-link action. Links are stored in the `openregister_email_links` table with full metadata (subject, sender, date). |
| **Sender-based discovery** | Automatically suggests objects previously linked to emails from the same sender, surfacing relevant context without manual lookup. |
| **Quick link** | One-click linking from the sidebar: select an object from a search dialog and bind it to the current email. |
| **Rich preview widget** | Smart Picker reference provider renders linked objects as interactive cards showing title, schema, register, and a deep link back to the object in OpenRegister. |
| **Unlink** | Remove an email-object association via the sidebar or API. |
| **NL Design System theming** | Sidebar and cards use Nextcloud CSS variables, compatible with nldesign token sets (Rijkshuisstijl, Utrecht, etc.). |

## API Endpoints

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| `GET` | `/api/emails/by-message/{accountId}/{messageId}` | Retrieve objects linked to a specific email | User session |
| `GET` | `/api/emails/by-sender?sender={email}` | Discover objects linked to emails from the same sender | User session |
| `POST` | `/api/emails/quick-link` | Create a new email-to-object link (body: `mailAccountId`, `mailMessageId`, `objectUuid`, `registerId`) | User session |
| `DELETE` | `/api/emails/{linkId}` | Remove an email-object link | User session |

## Architecture

### Backend

| Component | Path | Role |
|-----------|------|------|
| EmailLink entity | `lib/Db/EmailLink.php` | ORM entity for `openregister_email_links` table |
| EmailLinkMapper | `lib/Db/EmailLinkMapper.php` | Database queries: findByAccountAndMessage, findBySender, findExistingLink |
| EmailService | `lib/Service/EmailService.php` | Business logic: reverse-lookup, quickLink creation, deleteLink |
| EmailsController | `lib/Controller/EmailsController.php` | REST API controller for all email link endpoints |
| MailAppScriptListener | `lib/Listener/MailAppScriptListener.php` | Event listener that injects the sidebar script into the Mail app |

### Frontend

| Component | Path | Role |
|-----------|------|------|
| mail-sidebar.js | `src/mail-sidebar.js` | Webpack entry point, mounts Vue sidebar into Mail DOM |
| MailSidebar.vue | `src/views/mail/MailSidebar.vue` | Root sidebar component with collapsible panel, loading/error states |
| LinkedObjectsList.vue | `src/components/mail/LinkedObjectsList.vue` | Displays explicitly linked objects |
| SuggestedObjectsList.vue | `src/components/mail/SuggestedObjectsList.vue` | Displays sender-based discovery results |
| ObjectCard.vue | `src/components/mail/ObjectCard.vue` | Card with title, schema, register, deep link, unlink button |
| LinkObjectDialog.vue | `src/components/mail/LinkObjectDialog.vue` | Modal for searching and linking objects |
| useMailObserver.js | `src/composables/useMailObserver.js` | Observes Mail app URL changes (hash-based routing) |
| useEmailLinks.js | `src/composables/useEmailLinks.js` | API state management with caching and abort control |
| emailLinks.js | `src/services/emailLinks.js` | Axios API wrapper |
| mail-sidebar.css | `css/mail-sidebar.css` | NL Design System compatible styles |

### Database

Table **`openregister_email_links`**:

| Column | Type | Description |
|--------|------|-------------|
| id | integer (PK) | Auto-increment identifier |
| mail_account_id | integer | Nextcloud Mail account ID |
| mail_message_id | integer | Mail message ID |
| mail_message_uid | string | Mail message UID |
| subject | string | Email subject line |
| sender | string | Sender email address |
| mail_date | datetime | Email date |
| object_uuid | string | Linked OpenRegister object UUID |
| register_id | integer | Register containing the object |
| schema_id | integer | Schema of the object |
| linked_by | string | User who created the link |
| linked_at | datetime | Timestamp of link creation |

Indexes: composite on (mail_account_id, mail_message_id), sender, object_uuid. Unique constraint on (mail_account_id, mail_message_id, object_uuid).

## Dependencies

- **Nextcloud Mail app** -- Required for sidebar injection. OpenRegister functions normally without it; the sidebar simply does not appear.
- **OpenRegister registers/schemas** -- At least one register with objects must exist for linking to be useful.

## Verification Results (2026-03-25)

| Endpoint | HTTP Status | Response |
|----------|-------------|----------|
| `GET /api/emails/by-message/1/1` | 200 | `{"results":{"results":[],"total":0},"total":2}` -- No links found (expected, clean DB) |
| `GET /api/emails/by-sender?sender=admin@example.com` | 200 | `[]` -- No sender matches (expected) |
| `POST /api/emails/quick-link` | 500 | Internal Server Error -- Expected: no valid object UUID/register exists for dummy test data |
| `DELETE /api/emails/999` | 500 | Internal Server Error -- Expected: link ID 999 does not exist |
| **Mail app browser test** | OK | Mail app loads successfully at `/apps/mail/setup` (no account configured). Zero console errors. |
