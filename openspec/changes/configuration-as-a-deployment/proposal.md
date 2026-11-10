---
kind: code
depends_on: []
---

# Proposal: configuration-as-a-deployment

## Summary

A configuration change that cannot be undone is how an instance stays
broken over a weekend. Three driven systems stage configuration and put it
live as one deliberate act with a history to roll back to. OpenRegister
writes every setting straight through. This change makes a configuration
change a draft, a review, a named deployment and a rollback, adds the
bundle that binds one configuration to many case types, and makes the
product able to say which configuration is in effect and why.

## Candidates and cluster

Cluster 20 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Configuration as code:
environments, review and rollback". Owner openregister, size L, depends on
CT-7, eleven candidates: C-configuration-18, -19, -21, -26, -28, -38, -49,
-53, -82, -92 and -93. Five are `must` and one is a matrix hole:
C-configuration-53. Passers: 11, eight driven and three documented. dossiq
rates `yes` on one, `partial` on one and `no` on nine.

## The decisions this rests on

**D15, option 2 first and option 1 when the export works.** A staging
state inside one instance, with drafts published as a named unit, comes
first. A second environment and configuration moving between them as a
package comes after CT-7 lands, because the case type export returns an
empty package behind a 200 today, which makes the package route a lie.
This change builds option 2 and names option 1 as out of scope until then.

**D6, relevance-led promotion.** C-configuration-53 is a `must` with three
driven passers and no row in the corpus to hold it.

**D21, documented candidates admitted and labelled.** C-configuration-18,
-19, -21, -26 and -28 have no driven passer, coming from atabix,
jira-data-center and easy-redmine. Three are in scope as documented, two
are recorded.

## Why

The proving system is otobo, cited by the configuration lane at
`configuration.tsv:36`: "Configuration as a deployment
(AdminSystemConfigurationDeployment.pm,
AdminSystemConfigurationDeploymentHistory.pm,
AdminSystemConfigurationSettingHistory.pm, sysconfig_deployment and
sysconfig_modified_version)". Two tables and a history, with zammad and
znuny driven on the same shape. That is C-configuration-53, a `must` and a
matrix hole, and dossiq's own lane returns "zero hits".

The rest:

- **The product says which configuration is in effect and why**
  (C-configuration-92, `must`): osticket, "Schedule precedence is silent
  (the journey's week-one note: the department's schedule overrides the
  SLA's)". The candidate records that nothing in the corpus passes it, and
  says so explicitly so it is not rediscovered a fourth time. dossiq's
  lane: "no permission explainer, and Settings shows configuration without
  evaluating it".
- **A named bundle bound to many case types at once**
  (C-configuration-21, `must`, documented): jira-data-center,
  "workflowscheme, issuetypescheme, priorityschemes, notificationscheme,
  permissionscheme, issuesecurityschemes, screens API groups". A gemeente
  running two hundred zaaktypen cannot keep two hundred copies of the same
  rechten- en notificatieopzet in step by hand.
- **A review and approval before publication** (C-configuration-18,
  `must`, documented): atabix, "/atabix-suite (Formulieren)". Four eyes on
  a published form is the same control as four eyes on a besluit.
- **An integration configured once at the top and inherited, with an
  override** (C-configuration-49): gitlab, "Settings, Integrations, 54
  shipped, and instance-level defaults that cascade to every project
  below". A gemeente configures one mail relay, not one per zaaktype.
- **A whole transition matrix copied onto another role or case type**
  (C-configuration-38): openproject, "Administration Work packages,
  Workflow, resource :copy with resource :from_role, Workflow.copy at
  app/models/workflow.rb:88". Twenty-one zaaktypen times six rollen is a
  matrix nobody fills in by hand twice.
- **A working default configuration seeded in one action**
  (C-configuration-93): redmine, "post 'admin/default_configuration'".
  dossiq: "lib/Repair/Seed.php seeds catalogues but not from an
  administrator-facing action".

**What exists here and does not close it.** `settings-management`
specifies per-domain sliced settings persisted as JSON in app config, a
facade delegating to handlers, cache orchestration, mass validation and
environment introspection with a configuration rebase. `environment-otap`
gives an organisation an environment type and specifies that configuration
promotion transfers settings between OTAP stages. `feature-toggle-surface`
and `settings-change-audit` are open for the toggle and the audit of a
change. What none of them has is a state between edited and live: a write
is live, and the only history is the audit entry saying it happened.

**One finding to raise rather than build.** The build plan names
`app-delta-override` as the vehicle for this cluster. A search of the
openregister openspec tree on 2026-09-14 returns zero hits for
`app-delta-override` or `delta-override` in `specs/` and in `changes/`. It
is either a buildiq artefact or an unwritten one, and this change does not
extend something that is not there. The capability is created here and the
buildiq lane should say which of the two it meant.

## What changes

- **A configuration change is a draft.** Editing a setting, a schema
  configuration or a rule writes a draft against the live value rather than
  over it. The live value is unchanged until a deployment.
- **Drafts are reviewed and approved.** A draft set carries a reviewer and
  an approval, and an instance may require that the approver is not the
  author.
- **A deployment publishes a draft set as one named unit.** It carries a
  name, an author, an approver, a time and the list of values it changed.
- **A rollback restores a previous deployment.** As a new deployment,
  naming what it restores, so the history stays append-only.
- **The product explains the effective configuration.** For any setting,
  it answers which value is in effect, which layer set it, and which
  deployment last changed it.
- **A configuration bundle binds one set to many subjects.** A named bundle
  of permissions, notification rules and lifecycle settings, bound to many
  schemas at once. Changing the bundle changes all of them, and a subject
  may override one value with the override recorded.
- **An integration configuration cascades with an override.** Set once at
  the instance or the register, inherited below, overridable, with the
  effective explainer saying where the value came from.
- **A matrix is copied.** A whole transition or permission matrix copies
  from one role or schema to another as a draft, so it is reviewed before
  it is live.
- **A working default configuration is seeded from an administered
  action.** The repair-time seed becomes an act an administrator can take
  on a running instance, and it writes drafts rather than live values.

## Consumers

- **dossiq**: stages a case type's configuration, reviews it, deploys it
  and rolls it back. `CaseTypePublishService` becomes the leaf half of a
  platform deployment rather than a private one.
- **buildiq**: the manifest delta binds to the bundle rather than to a
  copy per app.
- **every fleet app**: the effective-configuration explainer answers "why
  does this instance behave like this" with no work per app.

## ADRs

- ADR-031: what is configured stays declared; this change is about when it
  becomes true, not about moving it into code.
- ADR-022: one configuration lifecycle in the platform layer.
- ADR-005: a deployment that cannot be applied in full applies none of it,
  and an unreadable draft refuses rather than publishing a partial set.
- ADR-003: draft, approval, deployment and rollback are audit facts on the
  chain.

## Impact

- Extends: a new `configuration-deployment` capability, and
  `settings-management` with the draft write path and the effective
  explainer.
- Affected code: the settings facade and every domain handler, the schema
  configuration writers, a draft and deployment store, the permission and
  notification configuration readers.
- Backwards compatible: an instance that never creates a draft writes
  straight through as today, and the explainer answers for values that
  predate the first deployment.
- Size: L.

## Out of scope

- A second environment, with configuration moving between instances as a
  package. D15 puts it after CT-7, because
  `CaseDefinitionExportService::exportComponent()` is a declared
  placeholder behind three live routes and an export that returns an empty
  package makes the package route a lie. C-configuration-19 waits there.
- Editing the instance's configuration file in the browser
  (C-configuration-82). The candidate's own clause names the risk: it is
  root on the instance behind one screen.
- A part of the product installed separately with its own version
  (C-configuration-26, documented). The Nextcloud appstore owns it.
- Delegated partial administration (C-configuration-28, documented). It
  needs the grant model `permission-provenance-and-deny` is specifying, and
  belongs there.
