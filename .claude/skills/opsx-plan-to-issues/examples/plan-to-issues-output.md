<!-- Example output — opsx-plan-to-issues for change: add-webhook-support -->

Found 1 active change with tasks.md: **add-webhook-support**

Proceeding with: add-webhook-support

---

### Determining GitHub repo...

From `project.md` Projects table: **ConductionNL/openregister**
App name: **openregister**

---

### Parsing tasks.md...

Found 6 tasks in 2 sections.

---

### Preview

About to create 1 GitHub issue in `ConductionNL/openregister`:

**📌 [OpenSpec] [openregister] add-webhook-support**

Labels: `openspec`, `openregister`, `webhooks`

Tasks (as checkboxes in the issue body):
```
### 1. Setup

- [ ] **1.1 Create WebhookSubscription entity and mapper**
  - [ ] WebhookSubscription entity has all required fields (url, secret, events, register, schema, organisation)
  - [ ] Mapper has findByEventAndTarget() for dispatcher use

- [ ] **1.2 Create WebhookSubscriptionController with CRUD routes**
  - [ ] 5 routes registered in routes.php
  - [ ] Index scoped to current user's organisation
  - [ ] @NoAdminRequired and @NoCSRFRequired annotations present

### 2. Implementation

- [ ] **2.1 Create WebhookDispatcherService with background job**
  - [ ] Dispatches via QueuedJob (not inline)
  - [ ] HMAC-SHA256 signature in X-OpenRegister-Signature header
  - [ ] Delivery logged to webhook_delivery_log table

- [ ] **2.2 Hook dispatcher into ObjectService lifecycle events**
  - [ ] object.created dispatched on new object save
  - [ ] object.updated dispatched on update
  - [ ] object.deleted dispatched before deletion

- [ ] **2.3 Write PHPUnit tests**
  - [ ] At least 3 test methods covering: dispatch, no-op, signature
  - [ ] All tests pass

- [ ] **2.4 Document webhook API in README**
  - [ ] Endpoint documented with request/response examples
  - [ ] Signature verification example included
```

> User confirmed: Yes, create this issue

---

### Ensuring labels exist...

Checking `ConductionNL/openregister` labels...
- `openspec` — exists ✓
- `openregister` — missing → created (color: 7057ff) ✓
- `webhooks` — missing → created (color: d93f0b) ✓

---

### Creating issue...

```
gh issue create --repo ConductionNL/openregister \
  --title "[OpenSpec] [openregister] add-webhook-support" \
  --body "..." \
  --label "openspec,openregister,webhooks"
```

✓ Created issue **#142** — https://github.com/ConductionNL/openregister/issues/142

---

### Saving plan.json...

✓ Saved `openspec/changes/add-webhook-support/plan.json`

```json
{
  "change": "add-webhook-support",
  "project": "openregister",
  "repo": "ConductionNL/openregister",
  "created": "2026-04-10",
  "tracking_issue": 142,
  "tasks": [...]
}
```

---

**Issue created!**

- Issue: [#142](https://github.com/ConductionNL/openregister/issues/142)
- Tasks: 6 checkboxes (with acceptance criteria sub-items)
- Labels: openspec, openregister, webhooks
- plan.json: `openspec/changes/add-webhook-support/plan.json`

**What would you like to do next?**

- **Start implementing** (`/opsx-apply`) — begin working through the tasks
- **Review the issue first** — end the session to check GitHub before starting
- **Done for now** — end the session
