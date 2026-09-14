---
kind: config
---

# Proposal: competitor-parity-2026-09

## Summary

The openregister half of the OpenSpec phase of the dossiq competitor parity
programme. Input: the gap register in market-intelligence,
`procest/_gaps/` (`README.md`, `gap-register.md`, `gap-register.json`,
`ownership-rules.md`, written 2026-09-13). The register puts 70 gaps on
openregister: 45 it marks covered by an existing spec or change, 25 it marks
uncovered. This umbrella records what the coverage check found when each
cited artefact was opened, indexes the changes opened for the uncovered
rows, and sets the build order. Ruben's rule applied throughout: dossiq
reaches 100% comparability, and logic that belongs to openregister is
specified here and consumed by dossiq.

Nothing in this change is implemented. Every change it indexes has its own
`proposal.md`, `design.md`, `specs/` and `tasks.md`, validated with
`openspec validate --strict`.

## Coverage confirmation

Every one of the 45 covered rows was opened. The verdict is on the artefact
the register cites: "confirmed" means a requirement, a "What changes"
bullet or a task in that artefact delivers what the row asks for.

**34 confirmed, 11 not covered by the cited artefact.** Of the 11: four
now have an openregister change in this programme, five are covered by a
different openregister artefact the register did not cite while the dossiq
half stays open, and two are deliberate no's to re-rate.

| row | cited artefact | verdict | what proves it, or what is missing |
|---|---|---|---|
| 1.10 | changes/files-leaf-save-to-object | confirmed | "OpenRegister registers a Files action 'Add to object' on files and folders" |
| 6.12 | changes/files-leaf-save-to-object | confirmed | "a Talk conversation action 'Save chat to object'" |
| 2.1 | changes/generated-identifier | confirmed | "A string property may declare `x-openregister-generated`: a `sequence` name, a `format`" |
| Q2.27 | changes/generated-identifier | confirmed | design: "`UPDATE ... SET value = value + 1 RETURNING value` on Postgres and a `SELECT ... FOR UPDATE` pair on MariaDB, inside the object create transaction" |
| 2.19 | changes/favourites-and-recent | confirmed | "A per-user star on any object ... A per-user view history" |
| 2.20 | dossiq specs/realtime-updates-ui | confirmed | "Store-rendered views MUST subscribe to live updates for their scope"; the case page is such a view. The register's own note stands: verify it is wired, re-rate |
| 3.1 | changes/flow-bpmn-interchange | confirmed | "BPMN is an interchange FORMAT here, never an execution semantic: symfony/workflow remains the core (ADR-065 Decision 2)"; the convergence of other engines is `changes/flow-engine-unification`. No separate convergence change is needed |
| 11.5 | changes/flow-bpmn-interchange | confirmed | "Add BPMN 2.0 XML import and export for flows. Export serialises ... with diagram interchange (BPMN DI)" |
| 3.4 | changes/flow-task-forms | confirmed | "A `form` block on the `openregister.user-task` node ... inherits that transition's declared `inputs`" |
| 3.17 | specs/computed-fields | confirmed | "Save-Time Evaluation" with `{{ ingangsdatum|date_modify('+1 year')|date('Y-m-d') }}` |
| 4.17 | changes/unified-search-file-content | confirmed | "`ObjectsProvider::search()` passes `_content_search: true`" |
| 9.6 | changes/content-search-index | confirmed | "one query over object data and over the extracted text of files ... The provider accepts scopes" |
| Q4.25 | specs/text-extraction | confirmed | "each format (PDF, Word, spreadsheet, EML) and the chunking algorithm live in their own class" |
| 12.15 | specs/search-index | not covered | every requirement targets Solr classes; Tika is absent. `openspec/architecture/adr-007` records that the external Solr and Elasticsearch backends were removed. Deliberate no for the engine half; the extraction half is `specs/text-extraction` (Q4.25). Re-rate |
| 9.1 | changes/unified-search-index | confirmed | "Pair each searchable schema with its OWN owning register" |
| 9.12 | changes/unified-search-index | confirmed | "Repoint/confirm `ObjectsProvider`" |
| 5.4 | changes/contacts-leaf-cases-panel | confirmed | "a detail surface for one contact with a cases panel" |
| 9.9 | changes/contacts-leaf-cases-panel | confirmed | "a name search: an index surface that finds a contact by (part of) a name" |
| 5.6 | specs/row-field-level-security | not covered | the group half is delivered; the reveal audit is a named gap: "Audit logging of RLS/FLS decisions exists at debug level via `LoggerInterface` but is not integrated with Nextcloud's audit log". Now `sensitive-field-reveal-audit` |
| 13.8 | specs/row-field-level-security | confirmed | "Schemas MUST support field-level security via property authorization blocks" |
| 6.4 | changes/activity-leaf | confirmed | "merges five sources: the object's audit trail, file events, notes, mail linked to the object, and NC Activity rows" |
| 10.8 | changes/activity-leaf | confirmed | "Export of the filtered feed as CSV or PDF" |
| 9.2 | changes/query-related-schema-rows | confirmed | "The object query accepts a related-row filter: `_related[<schema>][<fk>]`" |
| 10.5 | changes/audit-log-page | confirmed | "a paginated list over the whole instance's audit trail, with filters on actor, period, action, register, schema and object" |
| 11.10 | specs/skos-concept-registers | not covered | the spec ships schemes, an importer and a resolution API; no requirement lets a property reference a scheme as its code list. Now `property-code-list-from-concept-scheme` |
| 11.13 | specs/register-i18n | not covered | the spec is data-level content translation; "UI labels use IL10N" and property labels use `t()`. A runtime label translation UI is out by round 2 C05. Deliberate no; re-rate |
| 11.19 | changes/rbac-department-role-matrix | confirmed | "An admin surface on the schema page edits the matrix as a grid" |
| 13.6 | changes/rbac-department-role-matrix | confirmed | "a `matrix` declaration: a field of the object (`department`)" |
| 13.16 | changes/rbac-department-role-matrix | confirmed | the same admin surface |
| 13.2 | specs/rbac-scopes | confirmed | "conditional matching where access depends on both group membership AND runtime conditions evaluated against the object's data" |
| 13.3 | changes/object-level-sharing-and-private-scope | confirmed | "An invitation names a user or a group on one object" |
| 13.5 | changes/object-level-sharing-and-private-scope | confirmed | the primitive is schema-agnostic; a document object is an object |
| 2.23 | specs/mdm-merge | confirmed | "`MergeService::executeMerge(from, into, reason, mergedBy)` ... entity-type-agnostic" |
| 2.24 | specs/duplicate-detection | not covered | the rules and the scoring are there; no requirement scores an unsaved candidate at create time, which the intake warning needs. Now `dedup-check-before-create` |
| 11.26 | specs/integration-xwiki | confirmed | "Permission Inheritance: `requiresPermission() === null`; XWiki's own ACLs govern" |
| 2.27 | changes/run-scoped-object-locking | confirmed | "A person who tries to write to a locked object is refused with a message naming the run that holds it" |
| 4.20 | dossiq specs/archief-edepot-handover | confirmed | "Dossiq SHALL delegate MDTO/TMLO metadata generation, SIP packaging, transfer batching ... to OpenRegister" |
| 7.7 | dossiq specs/archief-edepot-handover | not covered | retention is declared per zaaktype (`x-openregister-archival`), not written from `resultType.archivalPeriod` at close. The openregister half is `specs/retention-management` ("calculate archiefactiedatum using configurable afleidingswijzen", destruction lists, legal holds). The result-type wiring is dossiq's: to the dossiq lane |
| 8.7 | dossiq specs/archief-edepot-handover | not covered | as 7.7; the destruction date on the case is a dossiq surface over `retention-management` |
| 13.10 | dossiq specs/archief-edepot-handover | not covered | as 7.7 |
| 11.22 | dossiq changes/archive/2026-09-08-case-type-authoring-extras | not covered | its task reads "[blocked: openregister the verwerkingsregister as a referenceable schema] ... STILL BLOCKED, and the interim shipped: a plain string". The openregister half is the open change `changes/processing-activity-register`; dossiq's `$ref` waits on it |
| 13.11 | same artefact | not covered | as 11.22 |
| 8.12 | dossiq changes/termijnbewaking-op-engine-timers | not covered | dossiq's clocks move onto the engine; no admin surface anywhere, as the register itself notes. Now `working-calendar-admin` |
| 9.4 | nextcloud-vue changes/saved-views-shared-by-role | confirmed for the control | "Share a view with a group ... a group multiselect and a read or write mode per selected group"; its proposal says the openregister half "is proposed in the OpenRegister repo" and no such change existed. Now `view-group-share` |
| 10.10 | nextcloud-vue changes/dashboard-layout-per-user | confirmed | "A saved-view preset SHALL list the user's saved views and bind the chosen one to an `object-list` widget" |

## Changes opened

The register's 25 uncovered rows resolve to 22 openregister slugs; seven
rows carry a slug the register marks `(dossiq)` and those are the dossiq
lane's. The coverage check added four more openregister changes. One
register slug, `objecten-api-facade`, is not opened here (see
Disagreements).

| change | rows | size | depends on | consumers |
|---|---|---|---|---|
| working-calendar-admin | 8.12, Q8.20 | M | flow-business-timers | dossiq, shillinq, integriq, humaniq |
| end-date-roll-on-the-calendar | 8.11 | S | flow-business-timers | dossiq (Atw), shillinq, humaniq |
| calendar-time-zone | Q8.19 | S | flow-business-timers | every business-timer app |
| calendar-change-recomputes-timers | Q8.17 | M | working-calendar-admin | every business-timer app |
| term-engine-diagnostic | Q8.18 | S | working-calendar-admin | dossiq |
| object-watchers | 13.18 | S | favourites-and-recent | dossiq, zaakafhandelapp, decidiq, pipelinq, keepiq, humaniq |
| timeline-entry-visibility | 6.15 | S | activity-leaf | dossiq, portaliq, zaakafhandelapp, pipelinq, decidiq |
| object-presence | Q2.31 | S | complete-live-updates | every detail page |
| note-edit-history | Q6.17 | S | notes-leaf-rich-text-lock-export | dossiq, humaniq, keepiq, zaakafhandelapp |
| relation-types-with-inverses | 2.26 | S | none | dossiq, decidiq, stackiq, pipelinq, keepiq |
| identity-survives-a-move | Q2.30 | S | generated-identifier | dossiq, pipelinq, stackiq, opencatalogi |
| saved-view-count-alert | 9.13 | S | notification-scheduled-filter-grammar | dossiq, pipelinq, humaniq, keepiq |
| view-group-share | 9.4 (openregister half) | S | none | dossiq, nextcloud-vue, every index page |
| send-at-on-the-messaging-leaf | 6.9 | S | messaging-dispatch-leaf | dossiq, pipelinq, humaniq, portaliq |
| reply-threading-by-headers | Q6.18 | M | send-at-on-the-messaging-leaf | dossiq, integriq, pipelinq, humaniq |
| feature-toggle-surface | 11.15 | S | apphost-settings-plane | every app on the settings plane |
| settings-change-audit | Q10.13 | S | apphost-settings-plane, audit-log-page | every app on the settings plane |
| scoped-api-tokens | Q13.20 | M | none | dossiq, integriq, portaliq, stackiq, keepiq |
| field-rules-by-state | 11.25 | M | none | dossiq, decidiq, humaniq, pipelinq, nextcloud-vue |
| sensitive-field-reveal-audit | 5.6 (openregister half) | S | none | dossiq, humaniq, keepiq, zaakafhandelapp |
| property-code-list-from-concept-scheme | 11.10 (openregister half) | S | none | dossiq, opencatalogi, stackiq, humaniq, pipelinq |
| dedup-check-before-create | 2.24 (openregister half) | S | none | dossiq, pipelinq, opencatalogi, humaniq |
| macro-flows-with-next-item | 3.20 | M | none | dossiq, nextcloud-vue, pipelinq, decidiq, humaniq |
| migrate-run-between-versions | 3.16 | M | flow-definition-versioning | dossiq, decidiq, humaniq, shillinq |
| external-register-view-leaf | 5.13 | M | object-source-providers | dossiq, integriq, pipelinq, humaniq, zaakafhandelapp |
| object-archive-state | Q2.33 | M | none | dossiq, decidiq, pipelinq, keepiq, stackiq, opencatalogi |
| rbac-inherits-to-children | Q13.23 | M | object-level-sharing-and-private-scope | dossiq, opencatalogi, stackiq, decidiq, buildiq |
| permission-provenance-and-deny | Q13.25 | M | rbac-inherits-to-children | dossiq, keepiq, integriq, decidiq, humaniq, portaliq |

Sizes: 17 S, 8 M. Rows closed on openregister: 26 register rows (22 slugs
plus 5.6, 11.10, 2.24 and 9.4's openregister halves; 8.12 and Q8.20 share
one change).

Every proposal names the dossiq half from the register's `dossiq_half`
column. Those halves are specified in dossiq by the dossiq lane; where the
register already names a dossiq slug (`sensitive-fields-declared`,
`code-lists-from-concepts`, `duplicate-warning-at-intake`,
`edit-lock-on-the-case-page`, `case-merge`, `field-rules-declared`,
`admin-inspect-entry`) the proposal uses it.

The last two rows arrived with the regenerated register (2026-09-13,
market-intelligence #123), which lists eight gaps with no change. Two of
the eight are openregister's: Q2.33 `object-archive-state` and Q13.23
`rbac-inherits-to-children`. Both are opened by the last sweep of the
phase, with dossiq consumer changes named in each proposal.

**Q13.25 arrived a day later**, with gap register v3
(market-intelligence #128) and batch 12 of round 4. Where a role's
permissions come from, and whether one can be taken away, is this layer's
question: the grantable set is not published anywhere an administrator
can read it, and nothing subtracts. It also reopens one line
`rbac-inherits-to-children` wrote off, "no competitor in the register has
one": Huly ships nine `Forbid` permissions beside its fifty-two, driven.
`permission-provenance-and-deny` depends on that change and completes the
rule from the other side.

## Build order

The calendar cluster first: statutory correctness, and the register's own
"what to do next" ends on it. Then the two cheapest rows with the widest
failure. Then the four small changes that unblock a dossiq half already
planned. Then the rest, grouped by the spec they extend.

1. `working-calendar-admin`
2. `end-date-roll-on-the-calendar`
3. `calendar-time-zone`
4. `calendar-change-recomputes-timers`
5. `term-engine-diagnostic`
6. `object-watchers`
7. `timeline-entry-visibility`
8. `sensitive-field-reveal-audit`, `dedup-check-before-create`, `property-code-list-from-concept-scheme`, `view-group-share`
9. `object-presence`, `note-edit-history`, `relation-types-with-inverses`, `identity-survives-a-move`
10. `send-at-on-the-messaging-leaf`, then `reply-threading-by-headers`
11. `feature-toggle-surface`, `settings-change-audit`
12. `saved-view-count-alert`
13. `field-rules-by-state`, `scoped-api-tokens`
14. `macro-flows-with-next-item`, `migrate-run-between-versions`, `external-register-view-leaf`

## Disagreements with the register

- **12.3, `objecten-api-facade`, not opened here.** The register puts the
  Objecten and Objecttypen API on openregister "where the other ZGW
  mappings live". Two facts against it: openregister's `specs/zgw-api-mapping`
  is a redirect stub whose only requirement is "Consult the canonical
  zgw-api-mapping spec" in dossiq; and ADR-091 §6 says "ZGW, StUF, DSO,
  Notificaties ... belong in OpenConnector, even where the endpoint happens
  to be unauthenticated". openregister's half already exists: the Endpoint
  system the ZGW routes ride, the objects API and schema export. The
  façade is integriq's under ADR-091 §2, as endpoint configuration over
  those. Recommendation: the integriq lane opens `objecten-api-facade`.
- **12.15 is a deliberate no**, not a covered row: ADR-007 removed the
  external backends. Re-rate the row to `specs/text-extraction`.
- **11.13 is a deliberate no**: labels are compiled l10n by decision (round
  2 C05); `register-i18n` is content, not labels.
- **9.4's slug reads "none needed" and names openregister work in the same
  breath.** The work is now `view-group-share`.
- **7.7, 8.7, 13.10, 11.22, 13.11 cite the wrong artefact.** The
  openregister half of each is covered elsewhere (`retention-management`,
  `processing-activity-register`); the dossiq half is open and goes to the
  dossiq lane.
- **3.1 needs no "flow engine convergence" change**: `flow-bpmn-interchange`
  and `flow-engine-unification` cover it, as the register's own slug says.
- **The calendar cluster is one change per slug, not one change.** The
  brief grouped 8.12, 8.11 and Q8.17 as one; the register's one-slug-per-row
  rule and `depends_on` chaining keep each reviewable. `working-calendar-admin`
  absorbs Q8.20 as the register says.
- **6.9 needed e-mail as a channel of the dispatch leaf.** The leaf carried
  SMS and WhatsApp only; `send-at-on-the-messaging-leaf` adds `email` over
  a seeded SMTP source, because a scheduled e-mail with no e-mail channel
  is nothing.
- **`scoped-api-tokens` has prior art the register missed**: `auth-system`
  already narrows an OAuth2 token to a subset of the user's groups (a
  requirement the validator reports as invisible because it sits outside
  the spec's `## Requirements` section, an inherited finding). The change
  generalises it and says so.

## ADRs cited across the programme

Company: ADR-019, ADR-022, ADR-023, ADR-024, ADR-025, ADR-031, ADR-045,
ADR-046, ADR-047, ADR-048, ADR-052, ADR-065, ADR-066, ADR-067, ADR-069,
ADR-071, ADR-076, ADR-078, ADR-079, ADR-091, ADR-095, ADR-098, ADR-099,
ADR-102, ADR-103, ADR-108. openregister: ADR-001, ADR-002, ADR-003,
ADR-006, ADR-007, ADR-008, ADR-009, ADR-010.

## Discovery wave 1 (2026-09-14)

A second input arrived after this umbrella was written: the round 4
discovery sweep in ConductionNL/market-intelligence,
`procest/_round4/discovery/`. `build-plan.md` groups 631 consolidated
candidates into 70 clusters plus nine from the case-type depth study
`casetype-configurability.md`, and the ownership rule puts **263 of the
631 candidates on openregister**, more than on any other app. `decisions.md`
holds 22 decisions; Ruben took all 22 on 2026-09-14 and lifted the build
hold.

Wave 1 is the platform under everything else. These are its openregister
clusters, one change each, except where the plan names an existing change
as the vehicle and it is extended instead.

| change | cluster | candidates | size | decision | dossiq consumer |
|---|---|---|---|---|---|
| `rules-engine-operability` | 19, the rules engine | 17 | L | D3 option 2 | `field-rules-declared` |
| `object-dates-as-a-calendar-feed` | 9, the case and its term in the caseworker's calendar | 2 | M | D11 option 1, feed first, and D5 | `every-term-on-the-engine-calendar` |
| `bulk-action-jobs` | 52, bulk action as a background job | 8 | L | none, kept under D6 | renders progress and skips |
| `object-read-state` | 62, per-user unread state | 6 | M | none | unread badge on the case tabs |
| `code-list-lifecycle-and-hierarchy` | 6, code lists, hierarchies and expiring values | 20 | M | none | `code-lists-from-concepts` |
| `delete-window-and-recorded-destruction` | 39, delete, restore and destroy | 5 | M | D10 as taken, no new recycle state | `case-delete-guard`, unchanged |
| `archiving-as-a-process-with-sign-off` | 43, the archiving process | 17 | L | D7 option 1, owner moved to openregister | declares the resultaattype |
| `property-vocabulary-published` | CT-1, the schema half | 10 study rows | M | none | `property-definition-management` |
| `computed-values-by-json-ast` | CT-3, computed values | study row B2 | S | D3, second half | `property-definition-management` |

Three changes are extended rather than created, because the build plan
names each as the vehicle for its cluster:

| change extended | cluster | what the extension adds |
|---|---|---|
| `permission-provenance-and-deny` | 11, roles and provenance (23 candidates) and 54, access compiled into the query (1) | the filter compiled into the query, permitted actions on the record, provenance in both directions, derived, scoped and expiring grants. D22 names this change by name |
| `field-rules-by-state` | CT-2, the rules engine behind the smart field | a condition over the object's own data, a rule that makes a field required, entry and exit conditions on a state. The two gaps D3 names |
| `property-code-list-from-concept-scheme` | CT-4, code lists a property takes its values from | a choice property with no source of values is refused at schema save |

**Four decisions differ from the recommendations the plan assumed, and all
four land here.** D10 takes no new recycle state: the existing soft delete
of `deletion-audit-trail` carries the stated window and the recorded
destruction, beside `object-archive-state`. D7 puts the archiving process
with sign-off in openregister rather than filinq, so the evidence stays
with the objects. D5 brings all five revivals back, which keeps the
calendar cluster in this wave. D6 makes the promotion bar relevance-led,
which is why cluster 52 stays with three driven passers and five `must`
candidates.

**Build order inside the wave.** `property-vocabulary-published` first: it
is the cheapest row per hour in the study and two other changes read it.
Then `permission-provenance-and-deny` with its extension, because access
inside the query is the foundation under six other candidates. Then
`rules-engine-operability` with `computed-values-by-json-ast` and the
`field-rules-by-state` extension, which are one engine in three parts.
Then `code-list-lifecycle-and-hierarchy`, `object-read-state` and
`bulk-action-jobs`. Then `delete-window-and-recorded-destruction` and
`archiving-as-a-process-with-sign-off`, which depend on the first of those
two. `object-dates-as-a-calendar-feed` runs beside them, after
`working-calendar-admin`.

**One finding to raise rather than build.** The case-type study names the
wall between the two property vocabularies as
`schemas.case.properties.caseType.x-openregister-extends-form.map`. That
annotation sits in OpenRegister's `x-` namespace, and a code search of
`ConductionNL/openregister` on 2026-09-14 returned zero hits for
`extends-form`. A leaf app is carrying an annotation that reads as a
platform contract and is not one. `property-vocabulary-published` makes it
one.
