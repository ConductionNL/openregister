---
kind: code
depends_on: [property-vocabulary-published]
---

# Proposal: party-roles-beyond-the-requester

## Summary

A case has one requester and a dozen other people. The gemachtigde, the
buurman who files a zienswijze, the aannemer, the jurist at the
omgevingsdienst: none of them is the aanvrager and every one of them
belongs on the record. Eleven driven systems model a party as a typed role
on the record. OpenRegister models it as a property that points at a
contact. This change makes the party a role, gives a party without an
account its own fields and its own notification, and lets a schema say
which kinds of party it accepts.

## Candidates and cluster

Cluster 14 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The party model beyond
the requester". Owner openregister, size L, sixteen candidates:
C-parties-and-contacts-2, -3, -5, -6, -8, -9, -10, -12, -13, -14, -16,
-18, -19, -20, -21 and C-configuration-4. Six are `must` and three are
matrix holes: C-parties-and-contacts-3, C-parties-and-contacts-9 and
C-configuration-4. Passers: 13, eleven driven and two documented. dossiq
rates `partial` on three and `no` on thirteen, which makes this the
fifth heaviest cluster in the sweep.

Ledger rows the candidate notes name: 5.7, 5.11, 5.14 and 5.16.

## The decisions this rests on

**D6, relevance-led promotion.** Every `must` enters whatever the passer
count, which is what admits C-parties-and-contacts-9 (two driven passers,
a hole) and C-configuration-4 (two driven passers, a hole) rather than
leaving them as capabilities with no row.

**D21, documented candidates admitted and labelled.** Two members carry no
driven passer: C-parties-and-contacts-19 (youtrack) and
C-parties-and-contacts-21 (jira-service-management). They are in scope as
documented, and they are never counted in a driven tally.

**D1, the dossiq-only rows.** Rows 5.7, 5.11, 5.14 and 5.16 are cited by
the lanes and renumbered centrally under D1. This change names the
capability, not the row id.

## Why

The proving system is glpi, cited by the parties lane at
`parties-and-contacts.tsv:10`: "Anonymous actors
(src/CommonITILActor.php:68-76, users_id = 0 plus alternative_email)". A
party row with no account, carrying its own address, still reachable. That
is C-parties-and-contacts-3, a `must` and a matrix hole, with glpi and
otobo driven.

The other members and their driven passers, each from the candidate file:

- **A party joins the thread who is not the requester** (C-parties-and-contacts-10,
  `must`): osticket, "Collaborators (include/class.collaborator.php)".
- **An indicator on the party changes how every case of theirs is handled**
  (C-parties-and-contacts-9, `must`, a hole): dimpact-zac "Betrokkenen
  (docs/user-manual-features.md)" and itop. Writing to a deceased person
  or publishing a protected address is the failure this prevents.
- **The party a case is filed against is changed after intake**
  (C-parties-and-contacts-16, `must`): freescout, "ajax change_customer,
  conversation_change_customer". An intake filed on the wrong BSN is an
  AVG incident, not a typo.
- **Two party records that turn out to be one person are merged**
  (C-parties-and-contacts-20, `must`): freescout, "GET /customers/{id}/merge".
  Two BRP rows for one resident is an accuracy duty.
- **A case type declares which kinds of party it accepts** (C-configuration-4,
  `must`, a hole): opencase and xxllnc-zaken, "Case type > Relaties
  (case-type-editor-anatomy.md)".
- **Organisations nest as a tree and carry their own fields**
  (C-parties-and-contacts-12): zammad, "Hierarchical groups
  (app/models/group.rb:27 parent, path, cycle and depth guards,
  HasObjectManagerAttributes at :12)".
- **One person holds several addresses** (C-parties-and-contacts-5):
  redmine, "resources :email_addresses under users".
- **A person query over a declared size is refused** (C-parties-and-contacts-6):
  xxllnc-zaken, "Contact zoeken (ContactZoeken.md)". Proportionality made
  mechanical, which is what a functionaris gegevensbescherming asks for.
- **Relations between contacts are read on the contact**
  (C-parties-and-contacts-14): xxllnc-zaken, "Contactbeeld
  (ContactBeeld-persoon.md)".
- **The correspondence address, the case address and the map location are
  chosen separately** (C-parties-and-contacts-18): xxllnc-zaken, "Case type
  > Relaties".

The dossiq reading is flat: "a party can be a contact record; a
field-carrying non-account party type not found", "register.d/25-brp-kvk.json
holds one email per party", "InitiatorPicker.vue attaches a party and
nothing replaces the primary one", and "zero hits for a party merge".

**What exists here and does not close it.** `mdm-merge` already specifies
an entity-type-agnostic merge with a preview, an atomic reversible
execution and a reversal window, so the merge machinery is built and only
the party vocabulary is missing. `integration-contacts` renders a contact
tab grouped by role and resolves a reference property to a person chip.
`contacts-leaf-cases-panel` gives one contact a detail surface with the
cases on it. None of the three lets a party exist without a Nextcloud
account, none carries an indicator that travels with the person, and none
makes the accepted party kinds a declaration on the schema.

## What changes

- **A party is a typed role on an object, not a property that points at a
  person.** A party row names the object, the party, the role, and the
  period the role runs. More than one party holds a role and one party
  holds several roles.
- **A party may have no account.** A party record carries its own
  properties and its own addresses, and the notification engine reaches it
  over the addresses it holds. It is a party, not a user.
- **A party holds several addresses, each with a kind.** Correspondence,
  case and location are three kinds, and inbound mail from any address
  resolves to the same party rather than creating a second record.
- **An indicator on the party is read on every object it holds a role
  on.** An indicator declares its effect: a warning to the reader, a
  refusal to publish, or a refusal to send. A protected address and a
  deceased person are the two the corpus names.
- **The primary party is replaced, recorded.** Changing who the case is
  filed against writes an audit fact naming both parties and the actor.
- **Parties merge over `mdm-merge`.** The party vocabulary declares which
  properties survive, and the existing preview, execution and reversal
  carry it.
- **Organisations nest.** A party of kind organisation names a parent, with
  cycle and depth guards, and carries its own properties.
- **A schema declares the party kinds it accepts.** A picker offers those
  kinds and refuses the rest. This is the declaration dossiq consumes per
  case type.
- **A person query over a declared cap is refused and says so.** The cap is
  administered, the refusal names it, and the attempt is on the audit
  trail.

## Consumers

- **dossiq**: declares which party kinds each case type accepts, renders
  the parties block, and stops treating the initiator as the only party.
- **portaliq**: a citizen who is a party without an account reads their own
  case through the subject-scoped reader.
- **humaniq, pipelinq, keepiq, decidiq**: a party role on any object with
  no work per app, which is the ownership rule doing its job.
- **integriq**: BRP and KvK adapters write into the same party record under
  CT-5 rather than into a copy per app.

## ADRs

- ADR-022: one party model in the object layer, consumed by every leaf app.
- ADR-031: the accepted party kinds are declared on the schema, not coded
  per app.
- ADR-005: the person query cap fails closed, and a refusal is recorded.
- ADR-003: replacing the primary party and merging two parties are audit
  facts on the chain.

## Impact

- Extends: a new `party-model` capability, and `mdm-merge` with the party
  vocabulary.
- Affected code: the object save pipeline where a reference property is
  resolved, the contacts integration provider, the notification recipient
  resolution, the schema validator.
- Backwards compatible: a schema that declares no party kinds keeps its
  reference properties and behaves as today.
- Size: L.

## Out of scope

- Reading a party live from BRP, KvK or BAG. That is CT-5 and integriq owns
  the adapters; this change owns the record they write into.
- Merging two Nextcloud accounts (C-parties-and-contacts-19, documented,
  youtrack). Accounts are the platform's; parties are ours.
- Entitlements shown on the case (C-parties-and-contacts-21, documented,
  jira-service-management). Recorded, not built.
- Loading parties from a file with a column mapping
  (C-parties-and-contacts-13). That is cluster 8,
  `import-preview-and-conflict-policy`.
