## 1. Catalogue rules

- [ ] 1.1 In `lib/Settings/credential-providers.json`, append six allow-rules to the `github` provider's `allowRules[]`: `{method:"GET", pathPattern:"/search/repositories"}`, `{method:"GET", pathPattern:"/user"}`, `{method:"POST", pathPattern:"/user/repos"}`, `{method:"POST", pathPattern:"/orgs/*/repos"}`, `{method:"PATCH", pathPattern:"/repos/*/git/refs/*"}`, `{method:"PUT", pathPattern:"/repos/*/topics"}`. Leave the four existing `github` rules and the whole `gitlab`/`doffin` entries byte-identical. Do NOT add any GET contents/git rule — `GET /repos/*` already covers those (fnmatch spans slashes).
- [ ] 1.2 Bump the catalogue's top-level `version` (1.1.0 → 1.2.0) and extend the file's `$comment` only if the GitHub-shop rationale needs recording there.

## 2. Verification

- [ ] 2.1 JSON-validate the catalogue file; run `composer check:strict`.
- [ ] 2.2 Add/extend unit coverage on `ProviderCatalogue` / broker rule matching: `GET /search/repositories` (with query string) matches; `GET /user` matches and is distinct from `GET /user/repos`; `POST /user/repos` and `POST /orgs/conduction/repos` match; `GET /repos/owner/repo/contents/x` and `GET /repos/owner/repo/git/trees/main` still match `GET /repos/*`; `PATCH /repos/owner/repo/git/refs/heads/main` matches; `PUT /repos/owner/repo/topics` matches and `PUT /repos/owner/repo` (repo settings, wrong method anyway) denies; `POST /repos/owner/repo/issues`, `DELETE /repos/owner/repo`, `PATCH /repos/owner/repo` (repo settings), and `GET /user` used as `POST /user` all deny (static 403); host-lock resolves to `api.github.com`.
- [ ] 2.3 Confirm no new mutation surface: no route, controller method, or service writes the catalogue (unchanged invariant; grep-level check is enough).

## Acceptance criteria

- The `github` entry gains exactly six rules: `GET /search/repositories`, `GET /user`, `POST /user/repos`, `POST /orgs/*/repos`, `PATCH /repos/*/git/refs/*`, `PUT /repos/*/topics` — and no others.
- The four pre-existing `github` rules and the `gitlab`/`doffin` entries are unchanged (byte-identical).
- No explicit GET contents/git rule is added; `GET /repos/*` is confirmed to already cover contents and git-data reads under the broker's glob semantics.
- Issues, pull-requests, workflows, webhooks, and deletes remain denied (fail-closed static 403).
- No secret, PAT, or realistic-looking token appears anywhere in the diff — placeholders only (`YOUR_API_KEY_HERE` in tests/spec examples).
- The catalogue remains runtime-immutable: no API can create, update, or delete the `github` rules; the `authScheme` template stays exactly `token {secret}`.

## Quality checklist

- ADR-031: catalogue stays the reviewed-release, runtime-immutable exception (credential-broker D2); this change is additive config only.
- Kind stays `config`: no PHP/Vue surface ships beyond test coverage of existing generic code paths.
- Scope is search + identity + repo-create + branch-ref update + discovery-topic write only — no issues/PRs/workflows/webhooks, per the proposal Non-Goals.
