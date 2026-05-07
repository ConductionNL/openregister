# Design: Integration — Email

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths. Leaf-specific choices only.

## Approach

Thin `EmailProvider` wrapping `EmailService`. Tab focused on list + link (no compose). Widget surfaces reuse one `CnEmailCard` component with internal `surface` branching.

## Architecture Decisions

### AD-1: Tab offers "Link existing", not "Send new"

**Decision**: The tab surfaces a picker (account → folder → message) for linking. No compose form.

**Why**: Mail app owns SMTP (AD-2 of `nextcloud-entity-relations`). Adding compose duplicates logic and diverges from Mail's UX. n8n handles automated email.

**Trade-off**: Users wanting to compose-and-link in one step open Mail, send, then return and link the sent message. Acceptable friction for a reference-only integration.

### AD-2: Cached subject/sender/date rendered without Mail API calls

**Decision**: `CnEmailCard` at dashboard surfaces uses the cached columns (`subject`, `sender`, `date`) from `openregister_email_links` — no per-render Mail API call. Detail-page and single-entity surfaces fetch the full message on demand for body preview.

**Why**: Dashboards render many cards; O(N) Mail API calls would be slow. The cached fields support the summary rendering; only expanded views need the live fetch.

**Trade-off**: Subject/sender can go stale if the message is modified in Mail. Rare in practice (messages are immutable in IMAP); refresh-on-link-time is sufficient.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/Integration/Providers/EmailProvider.php` | Wraps `EmailService` |
| `tests/Unit/Service/Integration/Providers/EmailProviderTest.php` | Unit test |

### Modified files — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | DI-tag `EmailProvider` |

### New files — Frontend

| File | Purpose |
|---|---|
| `nextcloud-vue/src/components/CnEmailTab/CnEmailTab.vue` | List + Link-existing picker |
| `nextcloud-vue/src/components/CnEmailCard/CnEmailCard.vue` | 4-surface widget |
| `nextcloud-vue/src/integrations/builtin/email.js` | Registration |
| Plus barrels + tests |

## Risks

| Risk | Mitigation |
|---|---|
| Mail account with thousands of messages makes the picker slow | Server-side pagination; search-as-you-type on subject/sender before list fetch |
| Mail app API schema drift | Adapter layer in `EmailService` already absorbs this; provider sits above adapter |
