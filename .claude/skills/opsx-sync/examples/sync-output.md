<!-- Example output — opsx-sync for change: add-webhook-support -->

Using change: **add-webhook-support**

---

### Finding delta specs...

Found delta spec: `openspec/changes/add-webhook-support/specs/webhooks/spec.md`

---

### Applying changes to main specs...

**Capability: webhooks**

Reading delta spec at `openspec/changes/add-webhook-support/specs/webhooks/spec.md`...

```
## ADDED Requirements

### Requirement: Webhook Subscription Management
...

### Requirement: Webhook Event Dispatching
...

### Requirement: Delivery Logging and Retry
...
```

Checking main spec at `openspec/specs/webhooks/spec.md`... does not exist yet.

**Creating new main spec** `openspec/specs/webhooks/spec.md`:
- Added Purpose section: "Defines webhook subscription management and event delivery for OpenRegister"
- Added 3 requirements from delta: Webhook Subscription Management, Webhook Event Dispatching, Delivery Logging and Retry

✓ Created `openspec/specs/webhooks/spec.md`

---

## Specs Synced: add-webhook-support

Updated main specs:

**webhooks**:
- Created new spec file at `openspec/specs/webhooks/spec.md`
- Added requirement: "Webhook Subscription Management"
- Added requirement: "Webhook Event Dispatching"
- Added requirement: "Delivery Logging and Retry"

Main specs are now updated. The change remains active — archive when implementation is complete.

**What's Next**

**Recommended:** `/opsx-archive add-webhook-support` — archive the change now that specs are synced
