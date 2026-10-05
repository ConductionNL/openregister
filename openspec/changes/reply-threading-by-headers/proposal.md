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

## Woo capability programme amendment (2026-10-05)

The Woo capability programme (round 1, `woo-round1/mi/opencatalogi/_round1/build-plan/plan.md`, wave 1) amends this change with one row. Re-read on `development` at 1dc6a4667 immediately before writing: the change is open at 0 of 7 tasks, with `ReplyThreadResolver` built for the header order and the refusals. Nothing already done is rewritten.

| row | capability | ours today (`baseline/openwoo.tsv`) |
|---|---|---|
| 19.6 | An email conversation is assembled as a thread, and a message is read with the messages around it | no: `EmlParser::extractHeaders()` reads From, To, Cc, Subject, Date and `Message-ID`, and splits body from attachments, so a message is a first-class source; nothing reads `In-Reply-To` or `References`, and nothing assembles collected messages into a thread |

This change threads a live reply onto an object. A Woo request has the other need: hundreds of `.eml` files collected into one corpus, which a reviewer must read as conversations. The same header rules apply, so the amendment reuses `ReplyThreadResolver`'s reading of the headers (case-insensitive names, `References` walked from its last entry, nothing guessed from the subject).

What the amendment adds:

- `EmlParser` also reads `In-Reply-To` and `References` into `EmlStructure`, and extraction stores the three ids per `.eml` file in a `openregister_mail_headers` table (`file_id`, `message_id`, `in_reply_to`, `references`, `sent_at`).
- `MailThreadAssembler` builds threads over a set of `.eml` files (an object's files, a folder, or a list) by those headers only. A parent that is not in the set is kept as a named gap (its `Message-ID`, "not in this set"), so the thread does not silently close up. Two messages sharing a `Message-ID` are shown as duplicates of one node. The subject is never used.
- `GET /api/files/{fileId}/thread` returns the thread the message belongs to, in date order with parent links, the position of the asked message, and gaps; only files the caller may read are included, and an unreadable member is a gap like a missing one, never a leak of its headers.
- The files sidebar of an `.eml` file gets a "Conversation" section listing the messages around it, each opening that message.

Dependencies: none new. Consumer: `dossiq/woo-review-triage` (wave 3) reads the thread endpoint for its reader. Without dossiq, the sidebar section is the reader. Closes 19.6.
