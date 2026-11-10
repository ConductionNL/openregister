# Credential broker — organisation scope

## Why

The OpenRegister credential broker today is strictly **per-user owner-scoped**: a
`credential` object carries an `owner` (a single user), the paired secret lives in the
Nextcloud vault keyed by the credential UUID, and the broker's IDOR guard denies any
call whose acting user is not that owner. This is correct for *personal* credentials —
"an app acts on **my** behalf against GitHub with **my** token".

It cannot express an **organisation** credential — "an app acts on behalf of **the
organisation** with a shared token that any member's session may drive". Organisations
in OpenRegister are first-class (a UUID identity; users belong to one or more, and have
an active organisation — `UserService::getUserActiveOrganisations()` /
`getActiveOrganisation()`). A tender-office GitHub token, a shared APIM subscription
key, or a per-department GitLab token should be provisioned once by an organisation
administrator and usable by every member's app calls — without the secret being copied
into each user's personal vault, and without weakening the personal per-user guard.

The frontend already anticipates this: `CnCredentials` renders a `scope="organisation"`
mode (full allowed-app management on an app's admin surface) that has no backend to talk
to. This change adds that backend.

## What

- Extend the `credential` schema with `scope` (`personal` | `organisation`, default
  `personal`) and `organisation` (an OR organisation UUID, required when
  `scope = organisation`).
- Store organisation secrets in the Nextcloud vault under a **fixed system identity**
  (system credentials), still keyed by the credential UUID — so no user "owns" the
  secret and any authorised member's session can drive a broker call that reads it.
  Personal secrets continue to live under the owning user, unchanged.
- Extend the broker guard with an **organisation branch** that is *additive*: the
  personal path (owner must equal the acting user) is untouched; the organisation path
  admits a call only when the acting user is a **member** of the credential's
  organisation AND the calling app is in `allowedApps`.
- Add admin-scoped CRUD: creating, updating, or deleting an organisation credential
  requires the caller to be an **administrator of that organisation** (or a Nextcloud
  admin); listing organisation credentials returns those of the caller's active
  organisation. `GET /api/credentials?scope=organisation` and a `scope` body field on
  `POST` select the organisation set.

## Non-goals

- No change to the personal path's storage, guard, or API shape (strict backward
  compatibility — a request with no `scope` behaves exactly as today).
- No cross-organisation sharing (a credential belongs to exactly one organisation).
- No change to the constrained-proxy allow-rules or the provider catalogue.
