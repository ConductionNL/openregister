- Routes: `appinfo/routes.php` is the ONLY registration path. NO runtime-registered routes, NO route
  fragments in `info.xml`, NO bootstrapped route providers added from `Application::register()`.
- `info.xml` is app metadata only (name, version, dependencies, categories, screenshots). It must
  never carry `<route>` / `<navigation>` entries that map URLs to controllers.
- Every route entry names `controller#method` explicitly — no wildcard auto-discovery, no regex
  generators. Snake_case controller maps to CamelCase class: `meeting#public_state` →
  `MeetingController::publicState()`. Lowering discoverability is the point: grepping `routes.php`
  returns the full URL surface area of the app.
- Admin settings pages: register the settings section via `\OCP\Settings\ISection` in
  `Application::register()`, but the settings URL itself is a standard `appinfo/routes.php` entry
  pointing at a controller method marked with `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- Public (unauthenticated) endpoints: declare `#[PublicPage]` + `#[NoCSRFRequired]` on the method,
  and keep the route in `appinfo/routes.php` — do not invent a separate public-routes file.
- Rationale: the mechanical gates (`hydra-gate-route-auth`) scan `appinfo/routes.php` only. Every
  endpoint living there gets its auth attribute verified; an endpoint registered elsewhere
  bypasses the gate and can ship to production without its middleware posture checked. One file,
  one gate, no drift.
- Gate layering: `hydra-gate-route-auth` (gate-5) is **syntactic** — it verifies the method
  carries any of the four valid auth attributes (`#[PublicPage]` / `#[NoAdminRequired]` /
  `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]`). It does NOT check that the chosen attribute
  matches the method's actual requirement. The **semantic** layer is `hydra-gate-semantic-auth`
  (gate-9) which enforces attribute-to-body consistency per ADR-005. Both gates must pass —
  syntactic alone produces the "minimum-to-clear-the-gate" anti-pattern where a builder adds
  the cheapest attribute (`#[NoAdminRequired]`) to a method whose body calls `requireAdmin()`
  just to pass gate-5. See ADR-005 for the full attribute-to-body mapping.
- Migration: any app with routes declared in `info.xml` or injected via `Application::boot()` must
  move them to `appinfo/routes.php` before the next build — the gate treats such endpoints as
  absent, and any related controller method without an auth attribute will surface as a FAIL.
