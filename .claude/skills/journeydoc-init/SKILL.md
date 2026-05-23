---
name: journeydoc-init
description: Bootstrap journeydoc — capture-driven user documentation — into a Conduction app. Drops the 8-artifact scaffold (tutorials/, capture spec, Playwright project, Docusaurus config, domain wiring, screenshot output dir) and opens a PR. See ADR-030.
metadata:
  category: Workflow
  tags: [workflow, documentation, scaffolding, journeydoc, playwright, docusaurus]
---

# Journeydoc Init — bootstrap capture-driven user documentation

Drops the journeydoc pattern (ADR-030) into a Conduction app repo:
the `tutorials/{user,admin}/` markdown structure, the
`docs-screenshots.spec.ts` capture spec, the Playwright `docs-capture`
project flag, the Docusaurus config snippet, the screenshot output
dir, and the domain wiring across `CNAME` + Docusaurus `url` + the
documentation deploy workflow.

After running this skill, the app has:
- A `tutorials/` section ready for content (skeleton pages per user
  story you name)
- A capture spec stub that can drive the UI
- A docs site URL on `<app-slug>.conduction.nl` (or the override you
  pick)
- A PR open against `development` with everything ready to review

**Input**: optional argument — the target app slug
(e.g. `/journeydoc-init opencatalogi`). If omitted, the skill asks.

**Reference**: the canonical pattern lives in
[ADR-030: journeydoc](../../../openspec/architecture/adr-030-journeydoc-pattern.md).
Templates live at [`templates/journeydoc/`](../../../templates/journeydoc/README.md).

---

## Step 1: Confirm the target app

Use **AskUserQuestion** if no slug was provided:

> "Which Conduction app are we journeydoc-ing? (slug only — e.g. `opencatalogi`, `docudesk`, `mydash`)"

Verify the path exists:

```bash
ls /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/<slug>
```

If not present, list the available apps and ask the user to pick.

Store the choice as `{APP_SLUG}` and set `{APP_DIR}` to the absolute
path.

## Step 2: Resolve placeholders from the target repo

Read these from `{APP_DIR}`:

- `{APP_TITLE}` — from `appinfo/info.xml` `<name>` field, OR
  `composer.json` `description` field, OR `package.json` `name` field
  (in that order). Title-case it.
- `{APP_BASE_PATH}` — `/apps/{APP_SLUG}/` (Nextcloud convention).
- `{APP_DOMAIN}` — default `{APP_SLUG}.conduction.nl`. Confirm with
  AskUserQuestion:

  > "Docs domain — `{APP_SLUG}.conduction.nl` is the default. Override?"

  Options: **Yes, use the default** / **Override** (if override, ask
  for the FQDN).

## Step 3: Discover existing docs structure

Check what already exists in `{APP_DIR}/docs/`:

- `docs/` doesn't exist → **green-field**, create from scratch.
- `docs/` exists with Docusaurus config → **brown-field**, integrate
  the journeydoc pattern alongside existing content.

Use AskUserQuestion if brown-field:

> "An existing Docusaurus site is in `docs/`. Should I add the
> journeydoc tutorials section, or merge into existing tutorials?"

For green-field, run the upstream `nextcloud-app-template` Docusaurus
bootstrap first (out of scope — link to `app-create` skill if
needed). For brown-field, proceed.

## Step 4: Collect the user-story list

Present the canonical mydash example as a starting point, then ask
AskUserQuestion (open-ended):

> "Paste your user-track stories — one per line. These become
> tutorial pages under `docs/tutorials/user/`. Aim for 5-10. Examples:
> 'Open the app for the first time', 'Create a record', 'Edit a
> record's fields', 'Share with a colleague', 'Delete a record'."

Collect, parse one-per-line. Repeat for the admin track:

> "Same for admin-track stories. Skip if the app has no admin UI."

## Step 4.5: Brown-field audit + auto-migrations

Before dropping the scaffold, audit the target repo for the four
canonical brown-field anti-patterns and apply the mechanical fixes.
Each pattern is unambiguous; report what was found, fix mechanically,
include the diff in the eventual PR description.

### 4.5a: Screenshots in `docs/screenshots/` (not `static/`)

Docusaurus only copies `docs/static/*` into the build root. Apps that
followed the pre-ADR-030 convention have screenshots at
`docs/screenshots/` — those silently 404 on the deployed site.

**Detect**:
```bash
test -d "{APP_DIR}/docs/screenshots" && ! test -d "{APP_DIR}/docs/static/screenshots"
```

**Fix** (mechanical — always safe):
```bash
cd {APP_DIR}
mkdir -p docs/static
git mv docs/screenshots docs/static/screenshots
```

### 4.5b: Relative image refs (`../screenshots/…` or `../../screenshots/…`)

After the screenshots move, every markdown image reference using a
relative path is broken. The canonical convention is root-absolute:
`/screenshots/<rest>` (Docusaurus serves `static/*` from build root).

**Detect**:
```bash
grep -rE "(\.\./)+screenshots/" {APP_DIR}/docs --include="*.md" -l | head -1
```

**Fix** (mechanical — bulk sed):
```bash
find {APP_DIR}/docs -name "*.md" -not -path "*/node_modules/*" \
  -exec sed -i -E 's|\.\./\.\./screenshots/|/screenshots/|g; s|\.\./screenshots/|/screenshots/|g' {} +
```

### 4.5c: Stale Dutch locale config

Apps that shipped `i18n.locales: ['en', 'nl']` without actual
translated markdown trigger SSR rendering errors (`Cannot read
properties of undefined (reading 'id')`) on a handful of doc pages
when the docs source moves faster than the locale's `current.json`.

**Detect**:
- `docs/docusaurus.config.js` mentions `locales: ['en', 'nl']` (or
  any locale array containing `nl` other than `en`)
- `docs/i18n/nl/` exists but `docs/i18n/nl/docusaurus-plugin-content-docs/current/` does NOT (no translated markdown)

**Fix** (mechanical — keep `en`, drop other locales until proper
translation backfill happens):
- Open `docs/docusaurus.config.js`
- Replace the `locales: [...]` array with `locales: ['en']`
- Trim `localeConfigs` to only the `en` entry
- Add a comment block explaining why (cite ADR-030)

### 4.5d: Old docs domain (canonical: `<APP_SLUG>.conduction.nl`)

ADR-030 standardises the docs domain across apps. Pre-ADR apps used
ad-hoc domains (`{slug}.app`, `docs.{slug}.com`, etc.) which need
swapping in three places to land cleanly on GitHub Pages.

**Detect**:
- `docs/static/CNAME` reads anything other than `{APP_DOMAIN}`
- `docs/docusaurus.config.js` `url:` reads anything other than
  `https://{APP_DOMAIN}`
- `.github/workflows/documentation.yml` `cname:` reads anything other
  than `{APP_DOMAIN}`

**Fix** (mechanical — three string replacements):
```bash
echo "{APP_DOMAIN}" > {APP_DIR}/docs/static/CNAME
# in docs/docusaurus.config.js:
sed -i "s|url: 'https://[^']*'|url: 'https://{APP_DOMAIN}'|" {APP_DIR}/docs/docusaurus.config.js
# in .github/workflows/documentation.yml:
sed -i "s|cname: .*|cname: {APP_DOMAIN}|" {APP_DIR}/.github/workflows/documentation.yml
```

**Always confirm with the user before applying 4.5d** — domain change
is the most consequential migration, and the user might have an
existing live `<old-domain>` they need to keep serving for a
deprecation window. Use AskUserQuestion:

> "Existing docs domain is `<old-domain>`. Switch to `{APP_DOMAIN}`,
> or keep the existing one?"

For 4.5a / 4.5b / 4.5c, apply without asking — they're either pure
fixes (a, b) or backwards-compatible until the team adds proper
translation files (c).

### Reporting

After each migration that fired, log a one-line entry to a
`migrations` array. The eventual PR description includes the array as
a "Brown-field migrations applied" section so the reviewer sees what
got fixed mechanically vs what's the new journeydoc scaffold.

Example PR-description fragment:

```markdown
## Brown-field migrations applied

- ✅ Moved `docs/screenshots/` → `docs/static/screenshots/`
- ✅ Rewrote 23 image refs from `../screenshots/...` to `/screenshots/...`
- ✅ Trimmed `i18n.locales` from `['en', 'nl']` to `['en']` (no translated markdown shipped)
- ✅ Switched docs domain from `pipelinq.app` to `pipelinq.conduction.nl` (CNAME + Docusaurus url + deploy workflow)
```

## Step 5: Generate filenames + slugs

For each story, derive:
- `<position>` — its 1-based index in the track (zero-padded to 2
  digits, e.g. `01`, `02`)
- `<slug>` — kebab-case of the title, with stop words and articles
  removed
- Filename: `<position>-<slug>.md`
- Page URL (after Docusaurus prefix-strip): `<slug>`

Example: "Open the app for the first time" → `01-first-launch.md`,
URL slug `first-launch`.

## Step 6: Drop the scaffold

For each file in [`templates/journeydoc/`](../../../templates/journeydoc/),
copy to the target with placeholders resolved:

| Template | Target |
|---|---|
| `tutorials/_category_.json.template` | `{APP_DIR}/docs/tutorials/_category_.json` |
| `tutorials/user/_category_.json.template` | `{APP_DIR}/docs/tutorials/user/_category_.json` (if user stories ≥1) |
| `tutorials/admin/_category_.json.template` | `{APP_DIR}/docs/tutorials/admin/_category_.json` (if admin stories ≥1) |
| `tutorial-page.md.template` × N | `{APP_DIR}/docs/tutorials/<track>/<position>-<slug>.md` per story |
| `docs-screenshots.spec.ts.template` | `{APP_DIR}/tests/e2e/docs-screenshots.spec.ts` |
| `cname.template` | `{APP_DIR}/docs/static/CNAME` |

**Splice** (don't replace) into existing files:
- `playwright.config.snippet.ts` → `{APP_DIR}/playwright.config.ts`
- `docusaurus.config.snippet.js` → `{APP_DIR}/docs/docusaurus.config.js`
- `documentation-workflow.snippet.yml` → `{APP_DIR}/.github/workflows/documentation.yml`

Splicing rules:
- If the target file doesn't exist, CREATE it with the snippet
  contents (filling placeholders).
- If the target file exists, OPEN the file, locate the relevant
  section, and merge the snippet's keys/values. Use the comments at
  the top of each snippet for guidance. Ask AskUserQuestion for
  conflicts:

  > "`docs/docusaurus.config.js` already sets `url` to
  > `https://example.com`. Override to `https://{APP_DOMAIN}`?"

## Step 7: Fill the per-page markdown skeletons

Each generated `<position>-<slug>.md` gets the
`tutorial-page.md.template` shape with these placeholders pre-filled:

- `{{POSITION}}` — sidebar position
- `{{TITLE}}` — story title
- `{{DESCRIPTION}}` — one-line, derived from the title (you can
  pre-fill a stock phrase like *"Step-by-step guide to <title>"*)
- `{{GOAL}}` / `{{PREREQS}}` / `{{STEPS}}` / `{{VERIFICATION}}` /
  `{{COMMON_ISSUES}}` / `{{REFERENCE}}` — leave as TODO comments
  (`{{TODO: write the goal}}`) for the human author. The skeleton is
  ready, the prose is for the human or for `journeydoc-add-story`.

## Step 8: Generate the capture spec stub

Edit `tests/e2e/docs-screenshots.spec.ts` so each story has an empty
`test('UN <slug>', async ({ page }) => { … })` block matching the
markdown filename. The block includes a `// TODO` comment pointing
the author at `journeydoc-add-story` for filling in the actual flow.

## Step 9: Branch + commit + PR

Per ConductionNL branch-policy (CLAUDE.md memory): branch name MUST
be `feature/<descriptor>`, NOT `feat/...`.

```bash
cd {APP_DIR}
git checkout -b feature/journeydoc-init
git add -A   # but NOT node_modules — use explicit paths if dirs are scoped
git commit -m "docs(journeydoc): bootstrap capture-driven user documentation"
git push -u origin feature/journeydoc-init
```

Open a PR using AskUserQuestion to confirm the title + body, then:

```bash
gh pr create \
  --repo ConductionNL/{APP_SLUG} \
  --base development \
  --head feature/journeydoc-init \
  --title "docs(journeydoc): bootstrap capture-driven user documentation" \
  --body "<…template summarising what landed…>"
```

## Step 10: Print next-steps to the user

```
✅ Journeydoc bootstrapped on ConductionNL/{APP_SLUG}.

Next:
  1. Fill the TODOs in `docs/tutorials/{user,admin}/*.md`.
     Use `/journeydoc-add-story <track> "<title>"` for incremental
     additions.
  2. Run `/journeydoc-instrument <vue-file>` on each capture-relevant
     component (modals, primary buttons, list-row actions, context
     menus) to add stable `data-testid`s.
  3. Run the capture spec to populate screenshots:
       npx playwright test --project docs-capture
  4. After merge, configure DNS for {APP_DOMAIN} → conductionnl.github.io
     and enable HTTPS in GitHub Pages settings.

PR: https://github.com/ConductionNL/{APP_SLUG}/pull/<N>
```

---

## Failure modes to watch for

- **Branch policy rejection** — every Conduction repo has a Branch
  Policy CI workflow that requires `feature/*` or `hotfix/*`. If the
  user typed `feat/journeydoc-init` somewhere, fix at branch-creation
  time, not after the PR fails CI.
- **GitHub Pages CNAME conflict** — if a domain is already used by
  another repo's Pages, the cert provision will fail. The skill
  should NOT enable HTTPS programmatically; let the human do it after
  DNS is verified.
- **Pre-existing CI red on `development`** — if the target app's
  development branch is already failing on quality gates unrelated to
  docs (PHPCS, ESLint, Stylelint, npm-audit), the journeydoc PR
  inherits the red. Surface this to the user as a blocker — admin
  merge through the inherited red is acceptable for a docs-only PR
  but the underlying issue needs its own fix-CI ticket.
- **Brown-field anti-patterns** — the four canonical migrations
  (screenshots dir, relative image refs, stale Dutch locale, old
  docs domain) are auto-applied in Step 4.5 above. Don't try to
  re-apply them post-bootstrap — they're idempotent but the human
  expects a clean diff per migration, not a re-run.
