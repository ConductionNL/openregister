---
kind: code
depends_on: []
---

# Proposal: notification-routing-per-group-and-scope

## Summary

An alert nobody can turn off is an alert everybody ignores. OpenRegister
already lets a person set their own preferences, batch them into a digest
and pick a channel. What it cannot do is address a group, let a team leader
set the team's defaults, let a preference differ per case domain, dispatch
one event to a person and to an integration from one rule, or send one
message to everyone. This change adds those five.

## Candidates and cluster

Cluster 10 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Notification
preferences, per user, per group and per template". Owner openregister,
size L, nine candidates: C-communication-16, -17, -19, -29, -34, -46, -47,
-48 and -63. Two are `must` and two are matrix holes: C-communication-29
and C-communication-63. Passers: 13, all thirteen driven and none
documented, the only cluster in the sweep with no documented passer at
all. dossiq rates `partial` on three and `no` on six.

Ledger row the candidate notes name: 6.24.

## The decisions this rests on

**D6, relevance-led promotion.** Both holes are `must`: a per-user choice
of kind and channel, with three driven passers, and a shipped editable
template per system event, with two.

**D1, the dossiq-only rows.** Row 6.24 is renumbered centrally. This
change names the capability rather than the row id.

## Why

The proving system is kanboard, cited by the communication lane at
`communication.tsv:22`: "Configure this project, Notifications, plus
user_has_notifications, user_has_notification_types,
project_has_notification_types over web, mail, webhook and activity
stream". Four tables, and the third of them is the one nobody else has: a
preference per project, overriding the user's global default. That is
C-communication-46, with kanboard, openproject, taiga and vikunja driven.
The candidate clause names the alternative exactly: "everybody gets
everything and everybody filters it into a folder".

The rest:

- **A notification addressed to a group, not a person**
  (C-communication-16): dimpact-zac, "Signaleringen
  (signalering-notifications/spec.md)". An unassigned case approaching its
  term has no person to warn.
- **A group's alert settings, beside the per-user ones**
  (C-communication-34): dimpact-zac, "Signaleringen, admin". A team that
  all misses the same deadline warning is a configuration problem, not five
  user problems.
- **One event, a user notification and an integration call**
  (C-communication-17): znuny, "Notification events with two extra
  transports (AdminNotificationEvent.pm,
  Ticket/Event/NotificationEvent/Transport/Webservice.pm and Activity.pm)".
  dossiq's lane: "ZgwService.php publishes notifications separately from
  user notifications".
- **One message to every user at once** (C-communication-48): otobo,
  "Admin notification (AdminNotification.pm)", and tuleap. A
  storingsmelding to caseworkers.
- **An editable template per system event, shipped**
  (C-communication-63, `must`, a hole): dimpact-zac, "Mailtemplates", and
  xxllnc-zaken. The candidate says where the work is: "shipping a named
  template per moment is most of the implementation work".

**What exists here and does not close it.** `notificatie-engine` is the
most complete spec in this cluster's path. It carries the Nextcloud
notification manager integration, configurable rules per schema, several
channels, Twig templates with variable substitution, batching and digest
delivery, retry with a dead-letter, per-user preferences as override-only
values, an effective-preferences API that merges the schema default with
the override, a dispatcher that consults the merged preference, read and
unread tracking per user, rate limiting and organisation scoping.
`openregister-web-push-engine` carries the push to a device.
`notification-scheduled-filter-grammar` carries the scheduled filter. So
the per-user half of C-communication-29 is specified, and the digest of
C-communication-47 is specified. What is absent is every axis that is not
the individual user: no group is a recipient, no group holds a default, no
preference varies by scope, one rule cannot reach two transports, and
there is no broadcast.

## What changes

- **A group is a recipient.** A rule may address a Nextcloud group or a
  declared role on the object. Delivery resolves the members at dispatch
  time, and a member's own preference still applies.
- **A group holds defaults.** The effective preference becomes schema
  default, then group default, then the user's override. The API that
  already answers the merged preference answers the three-layer merge, and
  says which layer decided.
- **A preference may be scoped.** A user or a group may set a preference
  for one register, one schema or one declared domain, overriding their own
  global default for that scope only.
- **One rule reaches a person and an integration.** A rule declares its
  transports. The same event that notifies a caseworker fires the outbound
  call, once, with one record of what happened.
- **A broadcast reaches every user, once.** Administered, with a subject, a
  body and a period it is shown for, recorded with who sent it.
- **A named template per system event ships.** Each event the platform
  raises has a template in the box, editable, with its variables
  documented. An event with no shipped template is a gap the validator
  names rather than a silent fallback.

## Consumers

- **dossiq**: ships the Dutch templates for its own events and declares the
  team defaults per case type. It stops publishing ZGW notifications on a
  separate path.
- **integriq**: the outbound transport of a rule, rather than a second
  dispatch beside the user's notification.
- **humaniq, pipelinq, decidiq, keepiq**: group recipients and scoped
  preferences with no work per app.

## ADRs

- ADR-031: the recipient, the transports and the scope are declared on the
  rule, never branched in a listener.
- ADR-022: one notification engine in the platform layer, consumed by every
  leaf app.
- ADR-005: a group that cannot be resolved at dispatch fails closed and is
  reported, never silently delivered to nobody.
- ADR-009: group resolution happens once per dispatch, not once per member.

## Impact

- Extends: `notificatie-engine` with the group recipient, the three-layer
  preference merge, the scoped preference, the multi-transport rule and the
  broadcast.
- Affected code: the rule evaluation and the dispatcher, the
  effective-preferences API, the template registry and its seeds.
- Backwards compatible: a rule addressing a user behaves as today, and a
  user with no scoped preference keeps the merged answer they have now.
- Size: L.

## Out of scope

- The digest itself (C-communication-47), which `notificatie-engine`
  already specifies as batching and digest delivery.
- A notification reaching a device when the product is closed
  (C-communication-19), which `openregister-web-push-engine` carries.
- The per-user preference surface itself (the user half of
  C-communication-29), which the effective-preferences API already serves.
  What this change adds is the group and the scope around it.
