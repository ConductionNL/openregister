---
kind: config
depends_on:
  - credential-broker
---

## Why

OpenBuild is being redesigned so its app shop reads apps from GitHub (topic
search) and app owners can publish or pull their virtual apps to/from their own
GitHub repositories, with the personal-access token held in Doriath custody and
every call routed through the credential broker — never a token pasted into
OpenBuild itself. The broker's provider catalogue
(`lib/Settings/credential-providers.json`, runtime-immutable per credential-broker
design D2) currently gives the `github` provider only four allow-rules:
`GET /repos/*`, `GET /user/repos`, `PUT /repos/*/contents/*`, and
`POST /repos/*/git/*`. Those cover repo reads, contents writes and git-data
writes, but the shop flow needs three capabilities the current rules deny:

- **Repository search** — the shop discovers apps by topic; when a credential is
  used (rate-limit upgrade / private discovery) the call is `GET /search/repositories`.
- **Caller identity** — to target `{owner}/{repo}` correctly the flow must first
  learn the PAT's own login via `GET /user`. `GET /user/repos` is already allowed,
  but `GET /user` (a distinct path under the broker's exact-match glob) is not.
- **Repository creation** — publishing an unlinked app creates a repo under the
  user (`POST /user/repos`) or under an org (`POST /orgs/*/repos`).

Allow-rules are a security control and are NOT runtime-widenable by design
(credential-broker D2, ADR-031 exception) — widening them requires a reviewed
release of the catalogue file. This change IS that reviewed release for `github`.

## What Changes

- Widen the `github` provider's `allowRules[]` in the runtime-immutable catalogue
  `lib/Settings/credential-providers.json` with the minimal set the shop needs:
  - `GET /search/repositories` — topic/keyword shop search (query parameters are
    stripped by the broker's path normalisation before rule matching, so the exact
    pattern covers every search call).
  - `GET /user` — read the PAT's own login (exact match; distinct from the
    already-allowed `GET /user/repos`).
  - `POST /user/repos` — create a repository under the authenticated user.
  - `POST /orgs/*/repos` — create a repository under an organisation.
- Bump the catalogue's top-level `version`.
- The existing four `github` rules stay byte-identical, and `gitlab`/`doffin`
  are untouched.

**No new GET rule for contents/git reads is needed.** The broker matches rules
with `fnmatch(pathPattern, path)` and no `FNM_PATHNAME` flag, so `*` spans path
separators: the existing `GET /repos/*` already matches
`GET /repos/{owner}/{repo}/contents/{path}` and
`GET /repos/{owner}/{repo}/git/{...}` (verified against
`CredentialBrokerService::assertRuleAllowed()` at HEAD). Adding explicit GET
contents/git rules would be redundant surface.

## Non-Goals

- **No issues, pull-requests, workflows, or webhooks.** The shop flow is search +
  identity + repo-create + the already-permitted contents/git writes. Any of those
  broader surfaces would be a separate reviewed release with its own justification.
- **No widening of `gitlab` or `doffin`.** Their entries stay byte-identical.
- **No runtime/API mutation path for the catalogue** — the file stays
  runtime-immutable (credential-broker D2); this change is itself the
  reviewed-release mechanism D2 prescribes.
- **No OpenBuild code.** This change only widens the OpenRegister catalogue so the
  broker will permit the calls; wiring the OpenBuild shop to the broker is separate
  work in the OpenBuild repo.
- Obtaining the GitHub PAT and storing it in Doriath custody stays a manual
  operator step; the token is pasted once into the OR credential settings UI, never
  into this file or OpenBuild.

## Capabilities

### Modified Capabilities

- `credential-broker`: the `github` provider's allow-rule set is widened with six
  additional minimal rules (repository search, caller identity, user-repo create,
  org-repo create, branch-ref update, discovery-topic write). The catalogue
  requirement itself (runtime-immutable `lib/`
  file, no mutation API, entries change only via reviewed release) is unchanged;
  this is an additive rule set shipped through the designed reviewed-release path.

## Impact

- **Changed file**: `lib/Settings/credential-providers.json` (six additional
  `github` allow-rules + version bump). The existing `github` rules and the
  `gitlab`/`doffin` entries stay byte-identical.
- **No PHP/Vue changes**: `ProviderCatalogue`, `CredentialBrokerService` (four
  ordered guards incl. `fnmatch` rule matching and host-lock), and
  `CredentialController::providers()` consume catalogue entries generically.
- **Downstream**: once merged, OpenBuild (with the PAT held in Doriath custody and
  allow-listed on the credential) can, through the broker, search repositories,
  resolve the token's login, create repos (and tag them with the discovery topic)
  when publishing an unlinked app, and advance an existing branch ref when
  re-publishing — in addition to the repo reads and contents/git writes already
  permitted.
- **Fail-closed unchanged**: any GitHub path outside the (now-widened) rule set
  still denies with the static 403; the surface grows only by the six named rules.
