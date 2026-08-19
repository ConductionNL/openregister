# UNCOVERED.md — declared gates with no planted/clean acceptance bundle

This file is the **reasoned exception list** for the coverage ratchet in
`scripts/lib/test_gate_acceptance_matrix.sh`.

Every gate declared by `scripts/run-hydra-gates.sh` must EITHER have a
planted/clean fixture bundle under `scripts/test-fixtures/gate-acceptance/<bundle>/`
(with an `expect.conf` naming it), OR appear as a row below with a reason.
The driver reads this file by grepping `^\| *gate-[0-9]+` and extracting the
number, so the first column cell of every row must literally start with
`gate-<number>`.

**The list may only SHRINK.** Two conditions are hard CI failures:

* a gate listed here that has since gained a planted/clean bundle — delete its
  row, so CI enforces it from that point on;
* a declared gate that appears in neither place — a gate cannot be added to the
  runner and left untested in silence.

Gates already covered by a repo-shaped bundle are deliberately absent from this
table: **23, 24, 26, 27, 29, 30, 31, 32, 33** (`scripts/test-fixtures/gates-23-33/`
via `scripts/lib/test_gates_23_33_never_green_over_nothing.sh`), **61**
(`scripts/test-fixtures/scope-matrix/` via `scripts/lib/test_gate_scope_matrix.sh`)
and **19** (`scripts/test-fixtures/e2e-credibility/` via
`scripts/lib/test_gate19_coverage_credibility.sh`).

## What the categories claim

A reason is a testable claim, so each row states which of four kinds it is.

* `no-fixture-yet` — nothing blocks it; a bundle simply has not been authored.
  The reason names roughly what the planted subject would be.
* `needs-diff` — the gate is intrinsically diff-relative and needs a purpose-built
  git history, not just a directory of files.
* `needs-external` — the gate depends on something a fixture directory cannot
  cheaply supply. The exact dependency is named.
* `advisory-only` — the gate is WARN-only by design, so a planted arm cannot go
  red. **No gate currently qualifies**; the category is documented so that a
  future WARN-only gate is classified rather than mis-filed as `no-fixture-yet`.

Two notes on authoring, both measured rather than assumed:

1. `scripts/test-fixtures/gate-acceptance/auth-guards/` exists but carries no
   `expect.conf` and no source files beyond `appinfo/info.xml`, so it currently
   contributes **zero** covered gates. It is a stub, not coverage.
2. `_enum_tracked` prefers `git ls-files`, and a fixture directory sits inside
   this repository's own work tree — so a planted file must be **committed** to
   be enumerated at all. An untracked plant reproduces the very silence these
   bundles exist to expose.

## The list

| gate | name | category | reason |
|---|---|---|---|
| gate-1 | spdx-headers | no-fixture-yet | A planted `lib/Service/Unlicensed.php` with no `@license`/`@copyright` docblock tags would trip it; the clean arm is the same file carrying both. Nothing blocks authoring this. |
| gate-2 | forbidden-patterns | no-fixture-yet | A planted `lib/Service/Debug.php` containing `var_dump($x);` (and the `die;` language-construct form, which the pre-`check_forbidden_patterns.py` grep missed) would trip it; the clean arm keeps the file and drops the calls. |
| gate-3 | stub-scan | no-fixture-yet | A planted `lib/BackgroundJob/EmptyJob.php` whose `run()` body does no non-logger call, plus a `lib/Service/` method taking `$userId` and never referencing it, would trip both arms of the checker. Nothing blocks it. |
| gate-4 | composer-audit | needs-external | Requires the `composer` binary on PATH **and** a network round-trip to the Packagist security-advisories API, since `composer audit --locked` resolves advisories remotely. A planted arm would additionally need a lock pinning a package with a live published CVE, which rots as advisories are superseded. |
| gate-5 | route-auth | no-fixture-yet | `scripts/test-fixtures/route-auth/` and `fq-route-names/` already exercise this gate, but through `scripts/lib/test_gate_route_auth.sh`, which drives the checker's helper level rather than a planted/clean pair through the real wrapper. A planted `appinfo/routes.php` entry whose controller method carries no auth attribute would give it wrapper-level coverage. |
| gate-6 | orphan-auth | no-fixture-yet | A planted `lib/Service/AccessService.php::isTransitionAllowed()` carrying an authorisation-domain signal and called from nowhere in `lib/` or `src/` would trip it; the clean arm adds one production caller. Nothing blocks it. |
| gate-8 | unsafe-auth-resolver | no-fixture-yet | A planted `lib/Service/DecisionService.php::getAuthorizationService()` ending `catch (\Throwable) { return null; }` would trip it; the clean arm rethrows. Nothing blocks it. |
| gate-9 | semantic-auth | no-fixture-yet | A planted controller method annotated `#[NoAdminRequired]` whose body calls `requireAdmin()` would trip it; the clean arm swaps the attribute for `#[AuthorizedAdminSetting(...)]`. Nothing blocks it. |
| gate-10 | initial-state | no-fixture-yet | A planted `src/AdminRoot.vue` reading `document.getElementById('x').dataset.version` (and the two-step form, which the old single-line regex could not see) would trip it; the clean arm uses `loadState()`. Nothing blocks it. |
| gate-11 | admin-router | no-fixture-yet | A planted `src/main.js` calling `createRouter()` with a `{ path: '/settings', component: AdminRoot }` entry would trip it; the clean arm registers the component via `AdminSettings.php` only. Nothing blocks it. |
| gate-12 | nc-input-labels | no-fixture-yet | A planted `.vue` component with an `<NcSelect>` carrying neither `inputLabel` nor `ariaLabelCombobox` — written multi-line and after a `:reduce="(o) => o.id"` prop, which is what defeated the old `[^>]*` extractor — would trip it. Nothing blocks it. |
| gate-13 | modal-isolation | no-fixture-yet | A planted `src/components/Thing.vue` opening a multi-line `<NcDialog` tag inline (the shape that made 0 of pipelinq's 9 real violations visible) would trip it; the clean arm moves it to `src/dialogs/`. Nothing blocks it. |
| gate-14 | route-reachability | no-fixture-yet | `scripts/test-fixtures/route-registration/` and `fq-route-names/` already exercise this gate, but through `scripts/lib/test_gate_route_registration.sh` at helper level, not as a planted/clean pair through the wrapper. A planted `Response`-returning controller method with no route entry, plus a route naming a method that does not exist, would cover both invariants at wrapper level. |
| gate-15 | dashboard-antipattern | no-fixture-yet | A planted `src/manifest.json` with a `type:"dashboard"` page whose widget body slot template renders `<CnDashboardPage>` would trip it; the clean arm renders an ordinary widget component. Nothing blocks it. |
| gate-17 | redundant-controller | no-fixture-yet | A planted `lib/Controller/MeetingController.php::index()` whose body is a literal pass-through to OpenRegister's `ObjectService` would trip it; the clean arm deletes the wrapper. Runs full-tree when unscoped, so no diff is required. |
| gate-18 | notification-dialect | no-fixture-yet | A planted `lib/Settings/register.json` whose `x-openregister-notifications` block uses the obsolete singular `channel`/`recipient` + `lifecycleEnter` dialect would trip the blocking half (a); the clean arm uses plural `channels`/`recipients` + `trigger.type`. Half (b) is advisory and never decides the verdict. |
| gate-20 | or-objectservice-api | no-fixture-yet | A planted `lib/Service/Thing.php` calling `$this->objectService->findObjects(...)` would trip it; the clean arm calls `findAll()`. A second clean file with the same call **commented out** would pin the `source_scope.py --mask php` behaviour. Nothing blocks it. |
| gate-21 | conflict-markers | no-fixture-yet | A planted tracked file under `lib/` carrying git's canonical `<<<<<<< `/`=======`/`>>>>>>> ` marker shapes at start-of-line would trip it; the clean arm is the resolved file. Nothing blocks it. |
| gate-22 | manifest-validation | needs-external | `scripts/test-fixtures/manifest-validation/` exists but feeds `check_manifest.js` directly via `test_check_manifest.sh` rather than driving the wrapper. Wrapper-level coverage additionally needs `node` on PATH **and** `ajv/dist/2020` resolvable: no `node_modules` ships anywhere in this package, so today the validator exits 3 and the clean arm cannot go green. |
| gate-25 | contract-coverage | no-fixture-yet | A planted `appinfo/routes.php` entry plus a `#[PublicPage]` controller method with no Postman collection assertion, no PHPUnit controller test and no `@contract exclude <reason>` would trip it. The helper audits the full tree when unscoped, so no diff is required. |
| gate-28 | license-triangle | no-fixture-yet | A planted `composer.json` declaring `"license": "EUPL-1.2"` alongside a `lib/` PHP file whose docblock says `@license MIT` would trip it; the clean arm makes the two agree. Nothing blocks it. |
| gate-34 | window-confirm | no-fixture-yet | A planted `src/components/Thing.vue` calling `window.confirm(...)` — and the bracket form `window['confirm'](...)`, which the old regex missed — would trip it; the clean arm uses `NcDialog`. Nothing blocks it. |
| gate-35 | img-alt-empty-only | no-fixture-yet | A planted `<img :src="user.avatarUrl" alt="">` (and the single-quoted `alt=''` spelling, which was invisible until recently) would trip it; the clean arm gives the image a real text alternative. Nothing blocks it. |
| gate-36 | tabindex-positive | no-fixture-yet | A planted markup file with `tabindex="5"` — plus the single-quoted `tabindex='5'` form, for which the fleet has zero occurrences and therefore no live regression pressure — would trip it; the clean arm uses `"0"`. Nothing blocks it. |
| gate-37 | aria-hidden-focusable | no-fixture-yet | A planted element with `aria-hidden="true"` and `tabindex="0"` would trip it; the clean arm must include the canonical `aria-hidden` + `tabindex="-1"` hidden file input, which is correct code this gate previously reported. Nothing blocks it. |
| gate-38 | skip-link | no-fixture-yet | `scripts/test-fixtures/monitoring-skiplink/` exists but is driven by `scripts/lib/test_gate_monitoring_and_skiplink.sh` at helper level, not as a planted/clean pair through the wrapper. A planted `src/App.vue` that is neither an `<NcContent>` nor a `<CnAppRoot>` and carries no skip-link anchor would give it wrapper-level coverage. |
| gate-39 | button-name | no-fixture-yet | A planted `<button><CloseIcon /></button>` with no accessible name would trip it; the clean arm must include a `:title="t('app', 'Remove tab')"` bound name, which is the shape that produced all 22 of openbuild's false positives. Nothing blocks it. |
| gate-40 | form-label-association | no-fixture-yet | A planted bare `<input type="text">` with no label association would trip it; the clean arm must include an `<NcCheckboxRadioSwitch>` labelled by default-slot content only, which is the relaxation this gate depends on. Nothing blocks it. |
| gate-41 | html-lang | no-fixture-yet | A planted `templates/standalone.php` that emits `<html>` without a `lang=` attribute would trip it; the clean arm adds `lang`. A second clean template whose PHP comment merely mentions `<html>` would pin the `php_template_scope.emitted_markup` behaviour. |
| gate-42 | link-text-quality | no-fixture-yet | A planted `<a href="/docs">Click here</a>` would trip it; the clean arm gives the anchor descriptive text. Nothing blocks it. |
| gate-43 | table-headers | no-fixture-yet | A planted `<table>` in which one `<th>` carries `scope="col"` and another carries none would trip it — the exact case a single `scope=` used to green — and the clean arm scopes every header. Nothing blocks it. |
| gate-44 | autocomplete-attr | no-fixture-yet | A planted `<input id="e" type="text" name="email">` with no `autocomplete=` would trip it, and it doubles as the regression case for "first name-like attribute wins". The clean arm adds `autocomplete="email"`. Nothing blocks it. |
| gate-45 | prefers-reduced-motion | no-fixture-yet | A planted `css/main.css` declaring `transition: all .3s` with no `@media (prefers-reduced-motion: reduce)` block would trip it; the clean arm adds the guard. The clean arm must NOT ship a universal `*` reset, which globally suppresses every finding. |
| gate-46 | spec-anchor-existence | no-fixture-yet | A planted `@spec openspec/specs/nope/spec.md#no-such-anchor` in a `lib/` file (and one under `tests/`, the directory the enumerator only recently gained) would trip it; the clean arm points at a spec file and heading that exist. Nothing blocks it. |
| gate-47 | security-change-has-tests | needs-diff | The gate reports `na` outright unless `--scope-to-diff` is active with a resolvable base, because its whole subject is "which hunks did this change touch, and did it also touch a test". It needs a purpose-built commit pair, which a static fixture directory cannot express. |
| gate-48 | csrf-cochange | needs-diff | Same shape as gate-47: "was `@NoCSRFRequired` REMOVED" is a property of a change set, not of a checkout, and the gate reports `na` on any non-diff-scoped run. It needs a two-commit history where the attribute is present in the base and absent in the head. |
| gate-49 | controller-exception-translation | no-fixture-yet | A planted `lib/Controller/ThingController.php::destroy()` calling `$this->objectService->deleteObject(...)` with neither a `catch` nor a `@throws` would trip it; the clean arm catches `\Throwable`, which must be accepted (it was rejected on hermiq#162). Nothing blocks it. |
| gate-50 | security-config-fail-mode | no-fixture-yet | A planted `$this->appConfig->getValueString(Application::APP_ID, 'listing_register', '')` with no empty-value guard within the window would trip it — using the class-constant app id, which was the measured blind spot. The clean arm adds `if ($reg === '' \|\| $sch === '')`. |
| gate-51 | schema-property-titles | no-fixture-yet | A planted `lib/Settings/register.json` with a schema property carrying neither `title` nor `description` would trip it; the clean arm supplies both. Unscoped runs ratchet every property, so no diff is required. |
| gate-52 | custom-widget-ratchet | no-fixture-yet | A planted `kind:"widget"` component-registry entry with no `_note` field would trip the justification half, which is what runs unscoped; the clean arm adds the `_note`. The count-ratchet half additionally needs a base ref and is therefore not exercised by a fixture pair. |
| gate-53 | effective-manifest-crossref | needs-external | `scripts/test-fixtures/effective-manifest/` exists but is driven at helper level by `test_check_manifest_crossref.js` / `test_build_effective_manifest.js`, not through the wrapper. Wrapper-level coverage needs `node` plus `ajv/dist/2020` resolvable from `scripts/lib`; no `node_modules` ships in this package, so the gate currently fails closed with "ajv not resolvable" on both arms. |
| gate-54 | relation-dialect | no-fixture-yet | A planted `lib/Settings/register.json` carrying the banned per-schema `x-openregister-relations` block, or a `format:uuid` relation property with no `$ref`, would trip it; the clean arm uses the ADR-062 canonical shape. Nothing blocks it. |
| gate-55 | detail-page-discipline | no-fixture-yet | A planted `type:"detail"` page declaring both page-level `widgets[]` and `config.widgets` (render-path shadowing) would trip it; the clean arm keeps one render path. Unscoped runs check every detail page in the manifest, so no diff is required. |
| gate-56 | register-handler-resolution | no-fixture-yet | A planted `lib/Settings/register.d/10-thing.json` naming a `guard` class that does not exist in the tree, plus a real class with a `::method` that was never written, would trip both rules; the clean arm ships both. Nothing blocks it. |
| gate-57 | orphaned-write-capability | no-fixture-yet | A planted `lib/Service/JournalEmitter.php::emit()` with no non-test production caller and no register.d/listener/background-job seam would trip it; the clean arm wires one of the recognised seams. Nothing blocks it. |
| gate-58 | e2e-networkidle | no-fixture-yet | A planted `tests/e2e/nav.spec.ts` calling `page.waitForLoadState('networkidle')` would trip it; the clean arm uses `waitUntil: 'domcontentloaded'`, and should also carry a comment merely *mentioning* networkidle, which used to be counted as a live call. Unscoped runs put every e2e file in scope. |
| gate-59 | unclosable-gate | no-fixture-yet | A planted `lib/Service/SettingsInitializer.php` reading a `configuration_version` config key that nothing anywhere writes would trip it; the clean arm writes it after the guarded setup. Unscoped runs walk all of `lib/`, so no diff is required. |
| gate-60 | icon-vocabulary | needs-external | The planted arm can go red without help (an invented MDI name returns 1), but the clean arm **cannot go green**: with `node_modules/vue-material-design-icons` absent the helper returns `EXIT_TOOLING_MISSING` (5) and the runner reports SKIPPED (wiring), never PASS. A bundle needs that package's `*.vue` files present under the fixture. |
| gate-62 | store-plane | no-fixture-yet | A planted `src/manifest.json` whose store/templates/catalogue naming or discovery breaks ADR-080 would trip it; the clean arm conforms. The runner omits `--base` on unscoped runs, so a fixture directory is enough. |
| gate-63 | settings-surface | no-fixture-yet | A planted `src/manifest.json` placing a settings surface against ADR-079 would trip it; the clean arm conforms. Same helper and same unscoped behaviour as gate-62, so a fixture directory is enough. |
| gate-64 | apphost-autoload-prelude | no-fixture-yet | A planted `lib/AppInfo/Application.php` whose `register()` resolves an `OCA\OpenRegister\AppHost\` class without first registering OpenRegister's PSR-4 prefix would trip it; the clean arm adds the autoload prelude. The clean arm should also carry a lazy service closure merely *mentioning* an AppHost class, which must not be flagged. |
