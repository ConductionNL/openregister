# quality-config — the fleet's quality configuration, in one place

Every Conduction Nextcloud app runs the same PHP and frontend quality tools. Until
now every app also carried its **own copy** of every tool's configuration. This
directory is the single source of truth those copies are replaced by.

## The drift this exists to end

Measured on 2026-08-12 across the 18 core apps, reading canonical
`ConductionNL/<app>@development` (not local checkouts). "Variants" counts distinct
file contents after normalising away the app's own name and the `<description>`:

| File | Present in | Distinct variants |
| --- | ---: | ---: |
| `psalm.xml` | 18 | **18** |
| `phpstan.neon` | 18 | **18** |
| `playwright.config.ts` | 18 | **18** |
| `.github/workflows/code-quality.yml` | 18 | **18** |
| `eslint.config.js` | 17 | **17** |
| `phpmd.xml` | 18 | 15 |
| `vitest.config.js` | 14 | 14 |
| `stylelint.config.js` | 18 | 6 |
| `phpcs.xml` | 18 | 6 |
| `.prettierrc` | 14 | 1 |

Two of those numbers matter more than the rest.

**`phpcs.xml` has 6 variants but 14 of the 18 differ by nothing except a typo.**
Group A (8 apps) and group B (6 apps) are byte-identical apart from one word in a
comment — `openbuild` vs `openbuilt`. The remaining four are real: `doriath` is
missing the `vendor-bin` exclude, `ignore_warnings_on_exit`, **and both the
`NoLegacyServerAccessors` and `SpecTag` sniffs**; `zaakafhandelapp` demotes ten
sniffs to warnings under a tracked debt issue; `decidesk` carries the SPDX
`InvalidEndChar` downgrade; `openbuild` has the typo comment emptied.

**`NamedParametersSniff.php` has 6 distinct versions.** The same *named* rule
enforces six different things across the fleet. `launchpad`'s is a strict superset
(it alone knows about `Entity` magic accessors — 525 lines); `opencatalogi`'s is a
231-line stub with none of the scoping logic that calls `addWarning()` where every
other copy calls `addError()`. `doriath` ships two of the three sniffs not at all.

A shared rule that is copied is not a shared rule.

## How an app consumes this

`conduction/hydra-gates` is already a published composer package built from this
repository, so `composer install` is the pull. No workflow change is required and
local `composer phpcs` behaves exactly like CI — which matters, because a config
that only CI sees becomes its own drift source.

```jsonc
// composer.json
"require-dev": {
    "conduction/hydra-gates": "^1.0"
}
```

Then the app's config files shrink to the stubs in [`stubs/`](stubs/):

```xml
<!-- phpcs.xml -->
<ruleset name="myapp">
    <file>lib</file>
    <rule ref="vendor/conduction/hydra-gates/quality-config/phpcs.xml"/>
</ruleset>
```

### Two mechanics that were measured, not assumed

Both were established with a positive control against phpcs 3.13.6 and 4.0.4 in a
`php:8.3-cli` container before this directory was written.

1. **Rules and custom sniffs DO inherit through `<rule ref>`, including sniff
   files referenced by a path relative to the central ruleset.** The first attempt
   at this fixture registered only 1 sniff instead of 2 and looked like proof that
   central sniffs cannot work. It was the fixture that was wrong — a custom sniff
   must live at `<Standard>/Sniffs/<Category>/<Name>Sniff.php` with a matching
   class name. With the real layout, `phpcs --standard=phpcs.xml -e` through the
   stub lists the custom sniff and it fires.

2. **`<file>` must stay in the app stub.** phpcs resolves `<file>` relative to the
   directory of the ruleset that *declares* it, and it propagates through
   `<rule ref>`. A `<file>lib</file>` in this directory resolves to
   `quality-config/lib` inside `vendor/` and the run dies with
   `ERROR: The file "lib" does not exist` (exit 3) in every consuming app.

## What is here

| Path | Replaces, per app |
| --- | --- |
| `phpcs.xml` | the whole ruleset |
| `phpcs-custom-sniffs/` | 3 sniffs, canonically `launchpad`'s NamedParameters + `openregister`'s SpecTag and NoLegacyServerAccessors |
| `phpmd.xml`, `phpmd-unusedparams.xml` | both PHPMD legs |
| `phpstan-base.neon` | everything except the app's own baseline and app-only ignores |
| `frontend/eslint.config.js` | the flat config (see caveat below) |
| `frontend/stylelint.config.js` | the whole config — 8 of 18 apps already match it byte-for-byte |
| `stubs/` | what an app's file becomes after adoption |

## Not yet centralised, and why

**`psalm.xml` — Psalm has no config inheritance.** There is no `include`,
`extends` or `ref` in Psalm's config format, so the 18-way drift cannot be fixed
by a stub the way phpcs and phpstan can. The two available routes are a generator
(`composer psalm:sync-config`) or a drift *gate* that diffs the app's file against
a canonical template and fails on undeclared deviation. The gate is the better
one: it makes divergence visible without making the file un-editable.

This matters more than the drift count suggests. Every app suppresses the same
~34 issue types, and the list includes `InvalidArgument`, `InvalidReturnType`,
`InvalidMethodCall`, `UndefinedInterfaceMethod`, `TypeDoesNotContainType` and
`InvalidArrayOffset` — the checks that find bugs. `UndefinedClass` is suppressed
for the entire `OCP\` namespace. At `errorLevel="4"` with those off, a green Psalm
leg is close to a statement that the file parses.

**`frontend/` is a template, not yet a live import.** ESLint flat config resolves
plugins relative to the config file, so a config shipped in `vendor/` cannot load
`@nextcloud/eslint-config` from the app's `node_modules`. The frontend needs an
**npm** channel, and the right home is `@conduction/nextcloud-vue`, which all 18
apps already depend on and which already exports `/eslint`. Until that lands,
these two files are what the rollout copies, and the drift gate is what keeps them
copied correctly.

## Where the tabs-versus-spaces question is answered

`phpcs.xml` sets `indent=4, tabIndent=false`. Nextcloud core sets `"\t"`. That is
a deliberate divergence with consequences a contributor needs to know about before
their first PR; it is written up in
[Way of Work → CI/CD](https://docs.conduction.nl/WayOfWork/ci-cd/) rather than
here, because it is a policy, not a setting.
