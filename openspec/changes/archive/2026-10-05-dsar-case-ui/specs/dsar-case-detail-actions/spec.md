## ADDED Requirements

### Requirement: Case detail surface with deadline and escalation display
OpenRegister SHALL present a per-case DETAIL surface showing the case status, handler, denial fields, evidence, redactions, audit history, and its deadline/escalation state.
The deadline display MUST render the Phase-1 deadline-tracking calculations (`daysRemaining`, `isOverdue`, `escalationTier`) against the effective deadline, and the escalation tier MUST be conveyed by text and icon, not by colour alone (WCAG AA). Tier and status wording MUST resolve from the active policy pack, never from inlined jurisdiction strings.

#### Scenario: Detail shows the case deadline and escalation tier
- **WHEN** a handler opens a case detail
- **THEN** the surface MUST display the case's days-remaining/overdue state and its escalation tier from the deadline-tracking calculations
- **AND** the tier MUST be distinguishable without relying on colour alone

#### Scenario: Overdue case is shown as breached
- **WHEN** a case's effective deadline has passed
- **THEN** the detail MUST show it as overdue with the breached escalation tier label from the active pack

@e2e A handler opens an overdue case and sees its detail show days-remaining, an overdue indicator, and the breached escalation-tier label (with a text/icon indicator, not colour-only).

### Requirement: Lifecycle transition controls reflect the declared state graph
OpenRegister SHALL present lifecycle transition controls on the case detail that reflect the Phase-1 declared `x-openregister-lifecycle` transitions and invoke `POST /api/gdpr/cases/{id}/transition` through the extended `avg` store.
The controls MUST offer the declared transitions (e.g. `assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`, `redact`, `bundle`, `retain`) for the case's current state, MUST post the chosen transition to the case API, and MUST reflect the resulting status. The UI MUST NOT embed its own state machine — the declared graph and its guards remain authoritative server-side.

#### Scenario: Advancing a case posts the declared transition
- **WHEN** a handler runs a transition control (e.g. assign then collectEvidence) on a case
- **THEN** the UI MUST post that transition to `POST /api/gdpr/cases/{id}/transition`
- **AND** the detail MUST reflect the case's new status returned by the API

#### Scenario: Controls follow the case's current state
- **WHEN** a case is in a given state
- **THEN** the controls MUST offer only the transitions declared from that state, not an arbitrary hard-coded button set

@e2e A handler opens a received case, assigns a handler, and advances it through collectEvidence, and the detail reflects each status change driven by the case API.

### Requirement: Finalise-denial control is gated on a regulator reference
OpenRegister SHALL gate the `finaliseDenial` transition control so it is blocked until the case carries a `regulatorReference`, reflecting the Phase-1 denial-finalise guard.
Drafting a denial (`draftDenial`) MUST NOT be gated. The finalise control MUST be disabled or refuse to submit while `regulatorReference` is empty, and MUST surface the server's refusal if a finalise is attempted without it — the server-side guard remains authoritative.

#### Scenario: Finalise is blocked without a regulator reference
- **WHEN** a case has no `regulatorReference` recorded
- **THEN** the finalise-denial control MUST be blocked/disabled and MUST NOT finalise the denial

#### Scenario: Finalise proceeds once the reference is recorded
- **WHEN** a handler records a `regulatorReference` and then finalises the denial
- **THEN** the control MUST post `finaliseDenial` and the case MUST reach the refused outcome

@e2e A handler drafts a denial (allowed), sees finalise blocked while no regulator reference is present, records the reference, then finalises successfully.

### Requirement: Denial composer resolves grounds from the active policy pack
OpenRegister SHALL present a denial composer whose ground options are the active `dsarPolicyPack` denial-grounds enum, displaying each ground's pack-supplied label and statutory citation.
The composer's ground selector MUST source its options from the pack (key → label + citation), MUST carry an accessible input label (WCAG AA), and MUST NOT inline jurisdiction-specific ground wording in the component. The selected ground key MUST be recorded on the case via the transition/denial endpoint.

#### Scenario: Ground options come from the pack with label and citation
- **WHEN** a handler opens the denial composer on a case
- **THEN** the ground options MUST be the active pack's denial grounds, each shown with its pack label and citation
- **AND** no jurisdiction-specific ground wording MUST be inlined in the component

#### Scenario: Selecting a ground records it on the case
- **WHEN** a handler selects a ground and drafts the denial
- **THEN** the selected ground key MUST be sent to the case API and recorded on the case

@e2e A handler opens the denial composer, sees the pack's grounds with their labels and citations, selects one, and drafts a denial that records the ground on the case.

### Requirement: Evidence panel lists items with status and triggers a harvest
OpenRegister SHALL present an evidence panel on the case detail that lists the case's evidence items with their per-item collection status and lets a handler trigger an evidence harvest.
The panel MUST render the case's `evidence` sub-collection (source, status per item) and MUST let a handler trigger collection via `POST /api/gdpr/cases/{id}/evidence`, reflecting the returned per-item status (including a re-runnable failed item). It MUST NOT duplicate the harvest logic client-side — it triggers and displays.

#### Scenario: Evidence items show source and collection status
- **WHEN** a handler opens the evidence panel on a case
- **THEN** each evidence item MUST show its source and its collection status

#### Scenario: Triggering a harvest updates item status
- **WHEN** a handler triggers an evidence harvest
- **THEN** the UI MUST post to the evidence endpoint and reflect the returned per-item collection status

@e2e A handler opens a case, triggers an evidence harvest, and sees the evidence items listed with their per-item collection status.

### Requirement: Redaction action records a field-level redaction
OpenRegister SHALL present a redaction action on the case detail that applies a field-level redaction (before/after + ground) via `POST /api/gdpr/cases/{id}/redactions`.
The redaction form MUST live in its own dialog file (ADR-004 modal isolation), MUST capture the target field, the redaction, and a ground, and MUST post to the redactions endpoint. The applied redaction MUST appear in the case's redactions list on success.

#### Scenario: Applying a redaction records it on the case
- **WHEN** a handler applies a field-level redaction with a ground
- **THEN** the UI MUST post it to the redactions endpoint
- **AND** the applied redaction MUST appear in the case's redactions list

@e2e A handler applies a field-level redaction with a ground on a case and sees it recorded in the case's redactions list.

### Requirement: Export-bundle generation offers a one-time download
OpenRegister SHALL present an export-bundle action that generates the signed bundle via `POST /api/gdpr/cases/{id}/bundle` and offers exactly one download of the returned one-time token.
The UI MUST request bundle generation, receive the one-time download reference, and offer a single authenticated download (`GET /api/gdpr/cases/{id}/bundle/download?token=…`); it MUST NOT expose the token for reuse. Any placeholder shown in fixtures/docs MUST be `YOUR_TOKEN_HERE`, never a realistic token.

#### Scenario: Generating a bundle yields a single download
- **WHEN** a handler generates the export bundle
- **THEN** the UI MUST request generation and offer exactly one download of the returned one-time reference
- **AND** it MUST NOT present the download token for repeated reuse

@e2e A handler generates a case export bundle and downloads it once via the one-time reference.

### Requirement: Identity-verify and regulator-escalate triggers reflect the fail-closed seam result
OpenRegister SHALL present an identity-verify trigger and a regulator-escalate action that invoke the Phase-2 seams through the case API and render the seam's result faithfully, including a fail-closed outcome.
The identity-verify trigger MUST render the seam's `verified`/`failed`/`needs-more` result (never optimistically showing success), and the regulator-escalate action MUST render whether escalation was performed or refused (fail-closed). Neither MUST treat a fail-closed/unavailable seam result as success.

#### Scenario: Identity verification renders the real result
- **WHEN** a handler triggers identity verification on a case
- **THEN** the UI MUST render the seam's returned status (`verified`, `failed`, or `needs-more`)
- **AND** an unverified/fail-closed result MUST NOT be shown as verified

#### Scenario: Regulator escalation renders performed-or-refused
- **WHEN** a handler triggers regulator escalation on a case
- **THEN** the UI MUST render whether the escalation was performed or refused
- **AND** a refused/fail-closed result MUST NOT be shown as a successful escalation

@e2e On an install with no leaf identity or regulator provider bound, a handler triggers identity-verify and regulator-escalate and sees the fail-closed results (unverified / escalation refused), not a false success.

### Requirement: Template content is referenced as a leaf, not inlined
OpenRegister SHALL render letter/notification template content from the active policy pack's `template:` reference as a leaf (ADR-022), the UI referencing the template rather than inlining its body text.
The UI MUST resolve the template by the pack-supplied reference and render/link it as a leaf; it MUST NOT embed the template body text in the Vue component. Changing the referenced template MUST change what the UI renders without a component change.

#### Scenario: A denial letter is rendered from a template reference
- **WHEN** a case's active pack supplies a letter template reference for a denial
- **THEN** the UI MUST render/link the template from that reference as a leaf
- **AND** the template body text MUST NOT be inlined in the component

@e2e A handler composing a denial sees the letter rendered from the pack's template reference, and updating the referenced template changes the rendered content without a redeploy.
