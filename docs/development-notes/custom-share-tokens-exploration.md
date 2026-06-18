# Exploration: custom tokens for public share links

**Date:** 2026-06-17
**Status:** Exploration only — nothing implemented. Captures findings so the
approach is ready when we pick it up.

## Question

Nextcloud can let you set a **custom token** for a public file-share link (the
`…/s/<token>` part of the URL), gated by a Share-API admin setting. Can we
(a) detect from the PHP backend whether that capability is available/enabled,
and (b) set a custom token when creating a share?

**Answer: yes to both.** Detection is a one-line OCP call; setting the token
needs a two-step create→update because the share manager overwrites the token
at create time.

All findings verified against the Nextcloud server checkout in this workspace
(OCP ^31).

## Detection

`OCP\Share\IManager::allowCustomTokens(): bool` (public OCP method).

It reads the app-config value (app `core`):

| Key | `shareapi_allow_custom_tokens` |
|-----|--------------------------------|
| Store | `oc_appconfig`, app `core` (NOT system config / config.php) |
| Type | bool |
| Default | `false` (true only on the FAMILY / PRIVATE install presets) |
| Admin UI | Sharing settings → *"Allow users to customize share URL"* |
| occ | `occ config:app:set core shareapi_allow_custom_tokens --value yes` |

- It is also surfaced to clients via the `files_sharing` capability
  `public.custom_tokens` (`apps/files_sharing/lib/Capabilities.php` →
  `$shareManager->allowCustomTokens()`).
- For older-NC safety, guard with `method_exists($shareManager, 'allowCustomTokens')` —
  the method only exists on versions shipping the feature (we're on OCP ^31, so
  it's present).

## How setting a custom token works

### The gotcha: create-time tokens are overwritten

`OC\Share20\Manager::createShare()` **unconditionally** assigns a freshly
generated token for link/email shares:

```php
// lib/private/Share20/Manager.php  (~line 673, inside createShare)
$token = $this->generateToken();
$share->setToken($token);
```

So calling `$share->setToken($custom)` *before* `createShare()` does nothing —
it gets overwritten. `updateShare()` does NOT regenerate the token, so the
custom token has to be applied as a **second step after create**.

This mirrors core itself: the OCS `ShareAPIController` applies the custom token
on the **update** path, not create
(`apps/files_sharing/lib/Controller/ShareAPIController.php` ~line 1358).

### Validation (enforced by the OCS controller, NOT the manager)

Core's `ShareAPIController::validateToken()`:
- non-empty (`mb_strlen($token) > 0`)
- charset `^[a-z0-9-]+$/i` → letters, numbers, hyphen only

Plus it checks `allowCustomTokens()` first (403 if disabled). Uniqueness is
**not** auto-checked on the custom path — a collision would hit the DB token
index. The manager's `updateShare()` just persists whatever token is set, so a
backend calling the manager directly must do all of this itself.

### Recommended backend pattern

```php
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Constants;
use OCP\Share\Exceptions\ShareNotFound;

// 1. Detect (guard for older NC).
if (!method_exists($this->shareManager, 'allowCustomTokens')
    || !$this->shareManager->allowCustomTokens()) {
    // Feature off/unavailable — fall back to the default random token.
}

// 2. Validate the desired token (mirror core's rules).
if (preg_match('/^[A-Za-z0-9-]+$/', $custom) !== 1) {
    throw new \InvalidArgumentException('Invalid share token');
}

// 3. Uniqueness pre-check (core does NOT auto-check the custom path).
try {
    $this->shareManager->getShareByToken($custom);
    // token already taken
} catch (ShareNotFound $e) {
    // free
}

// 4. Create the link share — gets a random token.
$share = $this->shareManager->newShare();
$share->setNode($node)
      ->setShareType(IShare::TYPE_LINK)
      ->setSharedBy($uid)
      ->setPermissions(Constants::PERMISSION_READ);
$share = $this->shareManager->createShare($share);

// 5. Swap in the custom token.
$share->setToken($custom);
$share = $this->shareManager->updateShare($share);
```

## Relevant OCP surface (all public on `OCP\Share\IManager`)

- `allowCustomTokens(): bool` — detection
- `getShareByToken(string $token): IShare` — uniqueness check (throws `ShareNotFound`)
- `generateToken(): string` — the default random generator
- `OCP\Share\IShare::setToken($token)` / `getToken()`

## Source references (Nextcloud server, OCP ^31)

- `lib/public/Share/IManager.php` — `allowCustomTokens` (464), `getShareByToken`
  (179), `generateToken` (532)
- `lib/private/Share20/Manager.php` — `createShare` token overwrite (~673),
  `allowCustomTokens` impl (~1847)
- `core/AppInfo/ConfigLexicon.php` — `SHARE_CUSTOM_TOKEN = 'shareapi_allow_custom_tokens'`
- `apps/files_sharing/lib/Controller/ShareAPIController.php` — custom-token
  update gate + `validateToken()` (~1358, ~1401)
- `apps/files_sharing/lib/Capabilities.php` — `public.custom_tokens` (~147)

## Not decided / next steps

- Where it would live (OpenRegister `FileService` vs DocuDesk) and the use case
  (e.g. predictable links for anonymised documents) — not chosen yet.
- A small reusable helper (detect → validate → create-then-update) was offered
  but not built.
