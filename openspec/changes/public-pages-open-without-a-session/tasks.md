# Tasks: public-pages-open-without-a-session

## 1. The resolver

- [x] 1.1 `PublicPageResolver` reads the leaf app's bundled `src/manifest.json` (D-2).
- [x] 1.2 A page counts only with `config.mode: "public"` AND a route under `/public/` (D-2).
- [x] 1.3 A missing, unreadable or invalid manifest declares nothing (D-3).
- [x] 1.4 A declared page gets the public shell; an undeclared path gets the login, or the ordinary shell when signed in (D-3).

## 2. The route

- [x] 2.1 `Routes::standard($extra, publicPages: true)` adds `dashboard#publicPage` on `/public/{path}` (D-1).
- [x] 2.2 The route sits after `$extra` and before the catch-all (D-1).
- [x] 2.3 The catch-all stays authenticated in every app (D-1).

## 3. The shell

- [x] 3.1 `GenericDashboardController::publicPage()` answers `#[PublicPage]`, rate limited for an anonymous caller (D-4).
- [x] 3.2 The leaf app is told through initial state `public_page` that it booted a public page (D-4).
- [x] 3.3 No resolver wired means nothing is public (D-3).

## 4. What an anonymous caller may read

- [x] 4.1 `AccessLinkReader::publish()` projects one object for any anonymous surface (D-5).
- [x] 4.2 `ObjectShareLinkController::show()` uses it instead of `jsonSerialize()` (openregister#3818, D-5).
- [x] 4.3 Timeline entries are projected onto an allow-list that names no account (D-5).

## 5. Tests

- [x] 5.1 `tests/Unit/AppHost/PublicPageResolverTest.php`: both conditions, the fail-closed manifest, the anonymous redirect, the signed-in fall-through.
- [x] 5.2 `tests/Unit/AppHost/RoutesTest.php`: the route is opt-in and precedes the catch-all.
- [x] 5.3 `tests/Unit/Service/Sharing/AccessLinkReaderTest.php`: the published projection and the timeline allow-list.
- [x] 5.4 `tests/e2e/ci/public-pages.spec.ts`: an anonymous request for an undeclared page, and a share token that publishes no bookkeeping.
- [x] 5.5 `openspec validate public-pages-open-without-a-session --strict`.

## 6. The leaf half

- [ ] 6.1 dossiq declares its status page public and reads the access link instead of a share token. Not in this change: it is the leaf's own PR, and this one is the engine it needs.
