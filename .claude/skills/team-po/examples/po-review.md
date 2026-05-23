<!-- Example output — team-po review for change: add-webhook-support -->

## Product Owner Review: add-webhook-support

### Verdict: APPROVE — with 2 minor action items

---

### Business Value Assessment

- **Problem clarity**: CLEAR — "MKB software vendors need real-time notifications to avoid API polling every minute"
- **User impact**: HIGH — affects all MKB integrators (Mark Visser persona) and integration developers (Priya persona); appears in most municipal middleware RFPs
- **Scope appropriateness**: RIGHT-SIZED — CRUD subscription API + async dispatch; no UI in this change (correctly deferred)

---

### Acceptance Criteria Review

- **Total criteria**: 18 (across 6 tasks)
- **Well-formed (GIVEN/WHEN/THEN)**: 16/18
- **Missing edge cases**:
  - Task 3 (dispatcher): no criterion for "GIVEN subscription URL is unreachable after 3 retries WHEN delivery fails THEN failure is logged and marked as failed" — the retry behavior is described in the design but not testable as an AC
- **Missing negative cases**:
  - No AC for: "GIVEN user tries to create subscription with `organisation` field set to a different org THEN the org is silently overridden to their own" — important for multi-tenancy security

---

### Cross-App Impact

- **Affected apps**: openconnector (may consume webhook events), softwarecatalog (may receive object lifecycle notifications)
- **Breaking changes**: NO — purely additive; new endpoints, no existing endpoints modified
- **Migration needed**: YES — new DB table `oc_openregister_webhook_subscriptions` and `oc_openregister_webhook_delivery_log` will be created by migration

---

### Dutch Government Context

**GEMMA Architecture Fit**: ALIGNED — webhooks are part of the integration layer (layer 3 in Common Ground 5-layer model); events flow down from services layer (layer 4, OpenRegister) to integration layer (layer 3, middleware). Data stays at source — webhooks notify, they don't copy.

**Municipal User Personas**: Addressed — Mark Visser (MKB vendor) and Priya Ganpat (integrator) are the primary beneficiaries. Multi-municipality: each organisation gets isolated subscriptions (confirmed in design.md).

**Legal & Compliance**:
- AVG/GDPR: The webhook payload will include object data — ACTION ITEM: proposal should note that subscribers must handle personal data according to their own AVG obligations; consider adding a note to the API documentation
- No BSN stored in subscriptions; payload content is determined by the object data, not this change

**Standards Alignment**:
- NLGov API: new endpoints must follow NLGov Design Rules v2 — ACTION ITEM: ensure subscription API pagination uses correct format (total/page/pages/pageSize) — this is a new endpoint, so it should be done right from the start
- WCAG 2.1 AA: not applicable (no UI changes in this change)

**Reusability**: YES — webhook subscriptions are per-organisation; all 342 municipalities can use the same feature with independent subscription management.

---

### Action Items

1. Add AC to Task 3: "GIVEN webhook delivery fails 3 times WHEN all retry attempts exhausted THEN delivery is logged with status 'failed' and no further attempts are made" — currently covered in design but missing from testable criteria
2. Add multi-tenancy negative AC to Task 1 or 2: "GIVEN user tries to create a subscription with organisation field set to a different org's UUID WHEN the API processes the request THEN the subscription is saved with the user's own organisation UUID, not the provided value"
3. Add AVG/GDPR notice to API documentation task (Task 6): subscribers must handle webhook payloads containing personal data per their own AVG obligations

---

### Notes

Solid proposal. The async background job approach (QueuedJob) is the right architecture choice for Nextcloud — this avoids blocking user requests when webhook endpoints are slow. The 15-minute cron delay is an acceptable trade-off given the use cases. The Seed Data section in design.md is well-done with realistic examples.

Consider a follow-up change for a `/test` endpoint that lets developers verify their subscription URL works (noted in design.md as a future consideration — good).
