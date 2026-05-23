<!-- Example output — opsx-explore for change: add-webhook-support (exploring retry strategy) -->

Checking active changes...

1 active change: **add-webhook-support** (3/4 artifacts complete — tasks next)

Loading app design context...
- Found `openspec/architecture/` — loaded 3 ADRs (ADR-001 seed data, ADR-013 envelope pattern, ADR-015 background jobs)
- Found `docs/ARCHITECTURE.md` — loaded OpenRegister service architecture

> User: The webhook change is ready to start implementation but I'm not sure about the retry strategy. The current design says "Nextcloud background jobs" but I'm not sure that's right.

Good question to pause on before implementing. Let me map out the actual tradeoffs...

```
WEBHOOK DISPATCH: SYNCHRONOUS vs. ASYNCHRONOUS
═══════════════════════════════════════════════════════════════════

OPTION A: Synchronous (inline in request cycle)
  ┌──────────────────────────────────────────────────────┐
  │  User action → ObjectService.save() → dispatch()     │
  │                                         │            │
  │                                         ▼            │
  │                                   HTTP POST (now)    │
  │                                   → 3 retries        │
  │                                   → return to user   │
  └──────────────────────────────────────────────────────┘
  ✓ Simple to implement
  ✓ Delivery is immediate
  ✗ User response blocked if webhook endpoint is slow
  ✗ 3 retries × timeout = up to 30s added latency
  ✗ If Nextcloud times out, delivery lost silently

OPTION B: Background Job (QueuedJob)
  ┌──────────────────────────────────────────────────────┐
  │  User action → ObjectService.save() → enqueue job    │
  │                                         │            │
  │                                         ▼            │
  │                User sees response immediately         │
  │                                                      │
  │  Background (next cron run, ~5-15 min):              │
  │  → HTTP POST → retry → log delivery                  │
  └──────────────────────────────────────────────────────┘
  ✓ User never waits for webhook delivery
  ✓ Delivery retries survive app restarts
  ✗ Not real-time — up to 15 min delay if cron is hourly
  ✗ Nextcloud cron setup varies by deployment

OPTION C: Hybrid — try inline, fall back to queue
  ┌──────────────────────────────────────────────────────┐
  │  User action → try immediate POST (1 attempt)        │
  │                → if success: done                    │
  │                → if fail: enqueue for retry          │
  └──────────────────────────────────────────────────────┘
  ✓ Best-effort real-time + reliable retry
  ✗ More complex to implement
  ✗ One sync HTTP request still in request cycle
```

Looking at ADR-015 in your architecture folder... it says "prefer QueuedJob for all external HTTP calls" — so Option B is the project-aligned choice.

The cron delay is real but honestly: most webhook consumers don't need sub-second delivery. If they do, they should be using the API with polling. Is there a specific integration scenario you're designing for that needs faster delivery?

---

> User: No, 15 minutes is fine. But I'm worried about the cron not running. What's the failure mode?

Good to think through. The failure modes:

```
IF CRON IS NOT RUNNING:
  Jobs accumulate in oc_jobs table
  No delivery, no error to the user
  Discovery: user notices integrations are stale

IF CRON RUNS BUT ENDPOINT IS DOWN:
  Job retries 3 times, marks as failed
  Delivery logged with status "failed"
  No automatic alerting (unless you add it)

IF CRON RUNS, ENDPOINT RETURNS 200 BUT IGNORES PAYLOAD:
  No way to detect — delivery is "succeeded" from our side
  Out of scope for this change
```

The main gap: **silent failure**. If cron stops, integrators don't know until they notice staleness. You could add a `/api/webhook-subscriptions/{id}/test` endpoint that fires a test event synchronously — that gives integrators a way to verify delivery works. Not required for v1, but worth noting.

Should I capture that in the design.md as a future consideration?

---

> User: Yes, add it as a "future consideration" in design.md.

Updated `openspec/changes/add-webhook-support/design.md` — added under "Future Considerations":

> **Delivery health check endpoint**: `POST /api/webhook-subscriptions/{id}/test` — fires a synthetic test event synchronously to verify the subscription URL is reachable. Not in scope for this change.

✎ Updated design.md

---

Anything else to think through before you start tasks? The design looks solid now — background job dispatch, 3-retry with exponential backoff, HMAC-SHA256 signing, delivery logging.

**Ready to implement?** Start with `/opsx-apply add-webhook-support` or `/opsx-continue add-webhook-support` to generate tasks first if that artifact is still missing.
