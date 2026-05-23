<!-- Example output — opsx-ff for change: add-webhook-support -->

I'll create a change called `add-webhook-support` to: add a webhook subscription API with HMAC-signed event dispatching on object.created/updated/deleted. Ready to generate all artifacts?

> User: Yes, generate all artifacts

Which model should I use for artifact generation?
> User: Sonnet

Delegating to Sonnet subagent for artifact generation...

Loading app design context:
- Found `openspec/architecture/` — loading ADRs (ADR-001, ADR-013, ADR-015)
- Found `docs/ARCHITECTURE.md` — loading service architecture context

---

### Creating change directory...

```bash
openspec new change "add-webhook-support"
```
✓ Created `openspec/changes/add-webhook-support/`

Schema: **spec-driven** | Apply requires: tasks

---

### ✓ Created proposal

**add-webhook-support / proposal.md**

Why: MKB software vendors and integration developers need to receive real-time notifications when OpenRegister objects change. Currently they must poll the API every minute — inefficient and unreliable.

What: CRUD API for webhook subscriptions per organisation. Dispatcher hooks into ObjectService lifecycle methods, signs payloads with HMAC-SHA256, delivers via Nextcloud background jobs with 3-attempt retry.

Capabilities:
- **New:** `webhooks` — subscription management and event delivery

---

### ✓ Created specs

**add-webhook-support / specs/webhooks/spec.md**

3 requirements:
- Webhook Subscription Management
- Webhook Event Dispatching
- Delivery Logging and Retry

---

### ✓ Created design

**add-webhook-support / design.md**

Architecture: `WebhookSubscriptionMapper` + `WebhookDispatcherService` + `WebhookDeliveryJob` (QueuedJob).

Signing: HMAC-SHA256(secret, JSON payload) in `X-OpenRegister-Signature: sha256=<digest>`.

Seed Data (ADR-001): 3 sample subscriptions — Gemeente Amsterdam, Conduction BV, Fictief Adviesbureau.

---

### ✓ Created tasks

**add-webhook-support / tasks.md**

6 implementation tasks:
1. Create WebhookSubscription entity and mapper
2. Create WebhookSubscriptionController with CRUD routes
3. Create WebhookDispatcherService with background job
4. Hook dispatcher into ObjectService lifecycle events
5. Write PHPUnit tests
6. Document webhook API in README

---

### Final Status

```
add-webhook-support (spec-driven)
  ✓ proposal    — done
  ✓ specs       — done (1 capability: webhooks)
  ✓ design      — done
  ✓ tasks       — done (6 tasks)

All required artifacts complete. Ready for implementation!
```

No deferred questions — all decisions were clear from context.

---

## All Artifacts Created

**Change:** add-webhook-support
**Location:** openspec/changes/add-webhook-support/

| Artifact | Description |
|----------|-------------|
| proposal.md | Webhook subscriptions for event-driven MKB integrations |
| specs/webhooks/spec.md | 3 requirements covering subscription, dispatching, delivery |
| design.md | PHP service + background job + HMAC-SHA256 + seed data |
| tasks.md | 6 implementation tasks with acceptance criteria |

All artifacts created! Ready for implementation.

**What's Next**

**Recommended:** `/opsx-apply` — start implementing the tasks

**Optional before that:**
- `/opsx-plan-to-issues` — create GitHub Issues for progress tracking
