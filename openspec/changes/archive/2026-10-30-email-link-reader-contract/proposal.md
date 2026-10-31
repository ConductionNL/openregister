## Why

pipelinq's client page shows the client's activity in one feed: contact moments, notes, tasks, appointments, messages and mail. The Nextcloud Mail messages linked to an object live in `openregister_email_links`. Only OpenRegister can read that table, and its read sits on `EmailLinkService`, which is not a published contract. A consuming app would have to type-hint an internal class or copy the table read.

## What changes

- New published contract `OCA\OpenRegister\Contract\EmailLinkReaderInterface` with `getLinkedEmails(objectUuid, cursor, limit)`. It returns the shape the email tab already reads: `items`, `total`, `nextCursor`.
- `EmailLinkService` implements it.
- The container binds the interface to the service.

## Impact

Only additions, no behaviour change. First consumer: pipelinq `/api/timeline`, for the "E-mail" kind on the client's Activity card. The read applies no object rights, so the caller checks access to the object first, as pipelinq's timeline controller does.
