---
kind: code
depends_on: [settings-change-audit, processing-activity-register]
---

# Proposal: audit-trail-shipped-and-purpose-bound

## Summary

A gemeente's security operations centre watches one platform, and an audit
log that only exists inside one application is one nobody is watching. The
BRP only answers a query that names its purpose, and we name none. This
change writes the trail to a file the organisation's own log platform
takes, binds every registry query to an administered purpose, keeps a copy
of reported content so removing it does not destroy the evidence, and
mails the administrators when a security-relevant setting moves.

## Candidates and cluster

Cluster 34 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The audit trail and where
it is shipped". Owner openregister, size M, five candidates:
C-access-and-privacy-48, -54, -55, -71 and -84. One is a `must`:
C-access-and-privacy-55. No matrix hole. Passers: 7, six driven and one
documented. dossiq rates `no` on all five.

## The decisions this rests on

**D6, relevance-led promotion.** C-access-and-privacy-55 is a `must` with
one driven passer, dimpact-zac, and enters on relevance. Doelbinding is
also statutory, which is the case D6's own recommendation made before
Ruben widened the bar.

**D21, documented candidates admitted and labelled.**
C-access-and-privacy-71 has no driven passer: jira-data-center, "Audit log
integrations in Jira". It is in scope as documented.

**D22 as taken.** Access is compiled into the query in openregister, which
is where a purpose can be attached to a query at all.

## Why

The proving system is forgejo, cited by the access lane at
`access-and-privacy.tsv:82`: "models/moderation/, services/moderation/,
shadow_copy.go". Content reported for review, with a copy kept so deleting
it does not destroy the evidence. Batch 2 parked this at one passer of
seven and said to re-argue it when a second system shipped one; GitLab
ships admin abuse reports, so it has three driven passers now.

The rest:

- **Every registry query carries a declared purpose**
  (C-access-and-privacy-55, `must`): dimpact-zac, "Person search
  (admin-configuration/spec.md, client-customer-management/spec.md)". The
  candidate names the shape exactly: "two reference tables plus a
  processing-register value". dossiq's lane: "zero hits for doelbinding".
- **The audit log written to a file the organisation's platform takes**
  (C-access-and-privacy-71, documented): jira-data-center, "writes audit
  logs to the database and a log file, the main purpose of the file is to
  easily integrate".
- **Every call made with an API token logged with its caller**
  (C-access-and-privacy-54): plane, "db/models/api.py:51 APIActivityLog
  recording path, method, headers, body, response code, IP and user agent,
  deleted nightly by cleanup_task.delete_api_logs". The candidate's clause
  is the real question: "which koppeling wrote this field".
- **Administrators mailed when a security-relevant setting changes**
  (C-access-and-privacy-84): redmine, "config/settings.yml,
  security_notifications: 1 on more than thirty settings". The candidate
  calls it proposal 10.13's neighbour rather than its answer, and argues it
  is the better control for a small beheerteam.

**What exists here and does not close it.** `audit-trail-immutable` and
`audit-hash-chain` make the trail append-only and verifiable, which ADR-003
requires. `enhanced-audit-trail` makes its queries index-backed.
`public-audit-query-endpoint` exposes it. `audit-log-page` gives it a
paginated surface with filters on actor, period, action, register, schema
and object. `settings-change-audit` records a settings change, and
`processing-activity-register` is the open change for the verwerkingsregister.
So the trail is strong, queryable and immutable, and it never leaves the
instance, never carries a purpose, and never tells anybody at the moment
something changes.

## What changes

- **The trail is shipped.** Entries are written to a structured file in a
  configured location, in a named format, in addition to the database. The
  writer is append-only and a failure to write the file is itself an
  entry, so a silently unwatched trail is not possible.
- **A registry query names its purpose.** A query to a registry source
  carries a purpose chosen from an administered list, each purpose bound to
  an entry in the processing activity register. A query with no valid
  purpose is refused, and the purpose is on the audit entry.
- **Which integration wrote this field is answerable.** The audit entry of
  a write made with a token names the token, its owner and the consumer,
  beside the before and after it already carries. The payload is not
  stored, because the before and after answer the question the payload was
  being kept for.
- **Reported content keeps a copy.** Content reported for review is copied
  before it can be removed. The copy is readable by the reviewers only, has
  its own retention, and the removal names it.
- **A security-relevant setting change is announced.** Settings marked
  security relevant mail the administrators when they change, naming the
  setting, the actor and the old and new values where those are not
  secrets.

## Consumers

- **dossiq**: declares the purposes its BRP and KvK lookups run under, and
  stops having zero hits for doelbinding.
- **integriq**: passes the purpose through to the registry adapters, where
  the BRP asks for it.
- **every fleet app**: the shipped trail and the announcement with no work
  per app.
- **filinq**: the copy of reported content is the same primitive a
  redaction review reads.

## ADRs

- ADR-003: the trail stays immutable and hash-chained. The file is a second
  sink, never a second truth.
- ADR-022: one audit trail in the object layer, consumed by every leaf app.
- ADR-005: a registry query with no valid purpose is refused, and a
  destination that cannot be written is an entry rather than silence.
- ADR-019: the purpose list is data, bound to the processing activity
  register rather than to code.

## Impact

- Extends: `enhanced-audit-trail` (the file sink, the token attribution and
  the content copy) and `verwerkingsregister-api` (the purpose list and its
  binding).
- Affected code: the audit writer and its sinks, the registry query path,
  the token resolution, the settings change listener.
- Backwards compatible: an instance that configures no file location and no
  purposes behaves as today, except that registry queries require a purpose
  once one is declared.
- Size: M.

## Out of scope

- Storing request and response payloads (the second half of
  C-access-and-privacy-54). Plane keeps them for a day and deletes them,
  and a second copy of case data in a log is the failure the same sweep
  records elsewhere. The before and after on the audit entry answers the
  question.
- The processing activity register itself, which
  `processing-activity-register` carries and this change depends on.
- The settings change record, which `settings-change-audit` carries. This
  change announces; that change records.
