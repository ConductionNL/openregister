---
kind: code
depends_on: [send-at-on-the-messaging-leaf]
---

# Proposal: reply-threading-by-headers

## Summary

Thread a reply onto its object by the mail headers, not by the subject line.
The email leaf links a Nextcloud Mail message to an object by the Mail app's
internal ids (`mailAccountId`, `mailMessageId`, `mailMessageUid`,
`lib/Db/EmailLink.php`) and never records the RFC `Message-ID`. A reply
whose subject a citizen edited cannot be matched. This change records the
`Message-ID` of every linked and every sent message on the object, and adds
a resolve endpoint that answers `In-Reply-To` and `References` before any
subject tag is consulted.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q6.18 | Is a reply to a case mail threaded onto its case, and by what | partial | M |

## Why

The register's note: "Row 1.5: `InboundEmailJob.php` polls IMAP and links
a mail to an existing case by the `[ZAAK-…]` subject tag, and creates
nothing. A citizen who edits the subject line loses the thread; nothing
reads `In-Reply-To`. Odoo threads by `References` and `In-Reply-To`
(`mail_thread.py:1185-1193`) and falls back to the alias." The best
competitor, verbatim from the `best` column: "Odoo 19.0:
mail_thread._message_route on References and In-Reply-To
(`_round4/compare/proposed-rows-batch6.md`)".

The register's `why`: "threading a reply onto its object by Message-Id and
References is the email leaf; integriq's intake hands it the message".

## What changes

- `EmailLink` gains `rfcMessageId`, read from the message's headers through
  the Mail app at link time and backfilled by a one-shot job for existing
  links.
- Every e-mail the dispatch leaf sends bound to an object writes an
  `EmailLink` row with the minted `Message-ID` and `direction: outbound`,
  so a sent message is part of the object's thread without a Mail account.
- `POST /api/emails/resolve` takes `{messageId?, inReplyTo?, references?: []}`
  and returns the objects whose links match, `In-Reply-To` first, then
  `References` newest first, with the matching header named. It requires an
  authenticated caller and returns only objects the caller may read; a
  system caller (integriq's intake, ADR-099) resolves across RBAC and is
  told so in the response.
- `GET /api/emails/by-message/{account}/{message}` (mail-sidebar) also
  matches by `rfcMessageId` when the Mail ids are unknown.
- A resolve that matches nothing returns an empty list and no error, so
  the caller falls through to its subject tag.

## Consumers

- dossiq: store the `Message-ID` of every mail sent from a case (done by
  the outbound link row) and read `In-Reply-To` before the subject tag in
  `email-case-matching`. Specified in dossiq by the dossiq lane (register
  row Q6.18).
- integriq: the mail intake calls resolve with the inbound headers before
  its own rules.
- pipelinq (customer replies), humaniq (applicant replies).

## ADRs

- ADR-091: the mailbox and intake are integriq's; the leaf answers the
  question.
- ADR-099 (acting on behalf of a user): the system caller is a granted,
  run-scoped identity.
- ADR-022.

## Impact

- Extends: `integration-email` requirement "Sidebar Tab: List and Link" and
  `mail-sidebar` requirement "Reverse-lookup API to find objects by mail
  message ID".
- Affected code: `lib/Db/EmailLink.php` and mapper (`rfcMessageId`,
  `direction`, indexes), a migration and backfill job, the email provider,
  `lib/Controller/EmailsController.php` (resolve), the dispatch leaf's
  outbound hook.
- Size: M.
