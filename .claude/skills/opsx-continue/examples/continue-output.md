<!-- Example output — opsx-continue for change: add-webhook-support (creating design.md) -->

Using change: **add-webhook-support** (Recommended — most recently modified)

Checking status...

```
add-webhook-support (spec-driven)
  ✓ proposal    — done
  ✓ specs       — done
  → design      — ready (proposal + specs complete)
  ○ tasks       — blocked (needs design)
```

**Schema:** spec-driven
**Progress:** 2/4 artifacts complete
**Next artifact:** design

---

Getting instructions for design...

```bash
openspec instructions design --change "add-webhook-support" --json
```

Reading context: proposal.md, specs/webhooks/spec.md

---

Creating design.md...

**Key decisions captured:**

**Architecture**: WebhookDispatcherService dispatches via Nextcloud background jobs (`\OC\BackgroundJob\QueuedJob`) to avoid blocking user requests. Guzzle HTTP client with retry middleware (3 attempts, exponential backoff).

**Data model**: `oc_openregister_webhook_subscriptions` table — id, url, secret (bcrypt hashed), events (JSON array), register (nullable), schema (nullable), organisation, created_at, updated_at.

**Signing**: HMAC-SHA256 of full JSON payload, delivered in `X-OpenRegister-Signature: sha256=<digest>` header.

**Seed Data (ADR-001)**:
- Gemeente Amsterdam: subscribed to `object.created` + `object.updated` on `bevolkingsregister/persoon`, URL: `https://middleware.amsterdam.nl/webhooks/openregister`
- Conduction BV: subscribed to all events on all registers, URL: `https://api.conduction.nl/incoming/openregister`
- Fictief Adviesbureau: subscribed to `object.deleted` on `archief/document`, URL: `https://archief.fictief.nl/hooks`

✓ Created design

---

```
add-webhook-support (spec-driven)
  ✓ proposal    — done
  ✓ specs       — done
  ✓ design      — done
  → tasks       — ready (design complete)
```

**Progress:** 3/4 artifacts complete

**Next step:**

**Recommended:** `/opsx-continue add-webhook-support` — create tasks.md (last artifact before implementation)

**Alternative (skip ahead):** `/opsx-ff add-webhook-support` — would generate all remaining artifacts in one go (just tasks.md in this case)
