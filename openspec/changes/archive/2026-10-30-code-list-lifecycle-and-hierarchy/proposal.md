---
kind: code
depends_on: [property-code-list-from-concept-scheme]
---

# Proposal: code-list-lifecycle-and-hierarchy

## Summary

A gemeente's code lists are trees, they expire, and their entries carry
data. A resultaattype has a bewaartermijn and a grondslag. A Woo category
is a hierarchy. A value that is retired must keep working on the records
that already hold it. OpenRegister ships SKOS schemes with hierarchy and
deprecation, and a property cannot yet use any of it: no tree, no dated
expiry, no fields on a list item, and one option set per property no
matter which case type is asking.

## Candidates and cluster

Cluster 6 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Code lists, hierarchies
and expiring values". Owner openregister, size M, 20 candidates, three of
them `must`: C-configuration-2, C-configuration-7, C-configuration-8,
C-configuration-9, C-configuration-10, C-configuration-11,
C-configuration-13, C-configuration-15, C-configuration-22,
C-configuration-39, C-configuration-46, C-configuration-54,
C-configuration-62, C-configuration-66, C-configuration-67,
C-configuration-80, C-configuration-99, C-configuration-104,
C-configuration-105, C-case-core-13.

Ledger rows named in the candidate notes: 11.34, 11.40, 11.44, 11.45.
Passers: 17, fourteen driven and three documented. dossiq: five `partial`,
fifteen `no`.

## Why

The proving system is osticket, cited by the configuration lane at
`configuration.tsv:132`: "Admin, Lists (scp/lists.php,
include/class.list.php, CustomList, and TicketStatus at :1121 as one of
them)". A code list item carries fields of its own, and the status
vocabulary is one such list. The Dutch case is in the candidate clause: a
resultaattype carries a bewaartermijn and a grondslag, which is a list
item with fields.

- **opencase**, `configuration.tsv:137`: "Configuration > Code lists
  (Configuration-CodeLists.md)". A value is expired rather than deleted,
  so older records keep it. The candidate note pairs it with pending row
  11.33: this is the other answer, which is to retire rather than remove.
- **openproject**, `configuration.tsv:130`: "Administration Custom fields,
  hierarchy format, resources :hierarchy_relations, enterprise". A choice
  field's values are a tree. The clause names ours: VNG waardelijsten and
  a Woo categorie tree are hierarchies, and a flat list forces the
  hierarchy into the label text.
- **openproject** again, `case-core.tsv:16`: "Administration Custom
  fields, weighted_item_list format, enterprise". Options carry weights
  that roll up to a score, which is how a subsidie aanvraag is scored.
- **jira-data-center**, documented, `configuration.tsv:103`: "Configuring
  projects, Configuring custom field contexts". One field carries a
  different option list per case type and domain, which is the
  alternative to forty near-identical fields.
- **forgejo** and **gitea**, `configuration.tsv:82`: "/labels with colour,
  description and an exclusive scope (api.go, code-census.md)". Labels are
  grouped so only one of the group applies.
- **glpi**, `configuration.tsv:135`: "Field unicity (Setup, Fields
  unicity, src/FieldUnicity.php:85-95, action_refuse and action_notify)".
  A named field combination is unique, and a breach is refused or only
  reported. The clause: one bezwaar per besluit per indiener is a rule
  nobody can express today.
- **tuleap**, `configuration.tsv:84`: "tracker administration, semantics
  (workflow.md)". Configuration declares which field means the title, the
  status, the assignee and the term. It is what lets one list view serve
  every case type.
- **youtrack**, documented, `configuration.tsv:78`: a field's type is
  changed after records hold values, with the possible conversions
  enumerated. Our own notes say adding a `format` to an OpenRegister
  property is breaking; this is the candidate that asks what happens next.
- **openproject** and **xxllnc-zaken**, `configuration.tsv:42`:
  "Administration Attribute help texts, resources
  :attribute_help_texts, AttributeHelpText". The clause is the one to
  quote in a tender: a field called archiefnominatie is a guess without
  help text, and a wrong guess is a records-management error nobody
  catches for seven years.

**What exists here and does not close it.** `skos-concept-registers` ships
`conceptScheme` and `concept` with `broader`, `narrower` and `related`,
an idempotent import that deprecates rather than deletes a removed
concept, and a resolution API. `property-code-list-from-concept-scheme`,
open and unbuilt, lets a property take its values from a scheme. Between
them a property gets a flat list of concepts. None of the four facts this
cluster is about is reachable: a dated validity window, fields on the
concept, the hierarchy as something the property uses, and a different
subset per context.

## What changes

- **A concept carries a validity window and fields of its own.** A scheme
  declares the shape of its concepts, so a resultaattype concept holds
  `bewaartermijn` and `grondslag` and is validated like any object. A
  concept carries `validFrom` and `validUntil`; outside its window it is
  not offerable and stays resolvable, so records that already hold it keep
  reading correctly.
- **A property uses the hierarchy.** A declaration may bound a property to
  a branch of the scheme, may require a leaf, and returns options as a
  tree. A filter on a branch matches every object holding a narrower
  concept.
- **A concept may carry a weight, and a property may roll weights up.** A
  multi-valued coded property may declare a derived score over its
  selected concepts, evaluated in the calculation engine.
- **One property, a different subset per context.** A property may bind
  its option set to the value of another property or to a declared
  context key, so one `categorie` serves many case types without forty
  near-identical fields.
- **A scheme may declare an exclusive group.** Inside one group at most
  one concept may be held by the same object, enforced on save.
- **A property declares its semantic role.** `title`, `status`,
  `assignee` or `term`, so a list component renders any schema without
  knowing it.
- **A property carries administered help text**, resolvable per language,
  beside the existing `description`.
- **A uniqueness constraint over a named field combination**, declared on
  the schema, with `refuse` or `report` as the action on a breach.
- **A property's type change on populated objects is a declared
  conversion.** The system names which conversions it supports, previews
  the result over the stored values and refuses the rest with a reason.

## Consumers

- **dossiq**: `code-lists-from-concepts`, which the build plan names as
  the consuming half, plus the resultaattype list becoming a typed
  scheme with bewaartermijn and grondslag on the concept rather than on
  the case type.
- **opencatalogi** (thema trees), **stackiq** (licence lists),
  **humaniq** (contract types), **pipelinq**: one scheme, many schemas.
- **nextcloud-vue**: a tree picker and a semantic-role-driven list, once,
  for every app.

## ADRs

- ADR-031: the list, its hierarchy and its lifecycle are declared data,
  not code in a leaf app.
- ADR-022: the code list lives where objects live, so an app that wants a
  shared vocabulary does not copy it into an `enum`.
- ADR-005: a value outside its validity window is refused on write and
  resolved on read, which fails closed for new data and never breaks old
  data.

## Impact

- Extends `skos-concept-registers` (typed concepts, the validity window,
  the exclusive group and the branch declaration) and `runtime-schema-api`
  (the semantic role, the help text, the uniqueness constraint and the
  declared conversion). Builds on
  `property-code-list-from-concept-scheme`, which stays as written.
- Affected code: the concept schemas and their importer, the concept
  resolution API, the schema property validator, the object query for the
  branch filter, and the calculation engine for the rolled-up score.
- Backwards compatible: a scheme with untyped concepts and no windows
  behaves exactly as today.
- Size: M.

## Out of scope, and where each one goes

- **C-configuration-80**, separate create, edit and view screens per case
  type, and **C-configuration-2**, a case tab that appears on a
  condition. Both are layout per case type, which is CT-6 under decision
  D16, owned by buildiq.
- **C-configuration-99**, renaming the product's own nouns per
  organisation. This is not translation and not a code list; it is a
  vocabulary layer over the interface and deserves its own change.
- **C-configuration-39**, translating administered content per language.
  `register-i18n` owns content translation; the umbrella records 11.13 as
  a deliberate no for interface labels.
- **C-configuration-11**, schema-driven pickers in the configuration
  screens, and **C-configuration-104**, custom fields on the workspace.
  Both are administration surfaces over what this change declares.
- **C-configuration-105**, typed custom fields on parties and other
  records. OpenRegister schemas already carry them per object; the gap the
  lane measured is the attachment of a form to a party, which belongs to
  cluster 14, the party model.
