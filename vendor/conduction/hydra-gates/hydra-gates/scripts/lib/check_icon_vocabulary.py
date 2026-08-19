#!/usr/bin/env python3
"""Gate 60 — icon-vocabulary (ADR-077).

Validates the `icon` field of every menu entry in an app's manifests against the
canonical semantic icon vocabulary, so a glyph means the same thing in every
Conduction app.

Checked, in severity order:

  FAIL  unresolvable   an MDI-style name that exists in neither the vocabulary
                       nor the installed vue-material-design-icons package.
                       This is what shipped as `LedgerOutline` / `FileSignOutline`
                       in shillinq: names that do not exist upstream at all and
                       render blank anywhere they are copied.
  FAIL  unregistered   a name the manifests use that the app never registers via
                       registerIcons(). CnAppNav resolves MDI names ONLY through
                       that registry, with no fallback, so the entry renders with
                       NO icon — 51 entries fleet-wide before ADR-077.
  FAIL  no-registry    src/main.js never calls registerIcons(), or calls it with
                       NO arguments. Eleven apps did the latter and looked fine
                       only because every icon was still a bridged `icon-*`.
  FAIL  tier-a-drift   a Tier A concept using a non-canonical icon. Tier A is the
                       universal chrome (Dashboard, Store, Settings, ...) whose
                       whole point is cross-app recognisability.
  FAIL  unbridged-css  a legacy `icon-*` name with no CSS_ICON_TO_MDI entry. It
                       falls through to the raw Nextcloud CSS class, which on
                       NC34+ light themes can render an invisible white glyph.
  FAIL  legacy-css     any remaining (bridged) `icon-*` name. This was a warning
                       while the fleet carried ~350 of them; every app is now at
                       zero, so one reappearing is a regression, not debt.
  FAIL  bad-lowercase  a lowercase value outside the declared ContentBlocks set.
                       Blanket-skipping lowercase values hid shillinq's
                       `calendar-sync` — a kebab-cased MDI name resolving to
                       nothing.
  WARN  tier-b-drift   a Tier B concept using a non-canonical icon.

Two dialects are legal, and both are governed:

  * MDI PascalCase — the ADR-077 vocabulary, rendered by CnIcon / CnAppNav.
  * The ContentBlocks set — 13 lowercase names owned by opencatalogi's page
    editor, stored in page/register data and drawn by the PUBLIC Softwarecatalogus
    website with its own glyphs. They are documented for end users, so they are a
    published contract and are NOT migrated to MDI; they are validated against the
    declared list instead.

Scanned: src/manifest.json, src/manifest.d/*.json, and the OpenRegister register
files under lib/Settings — a schema `icon` is drawn by CnIcon in index/detail
headers exactly like a manifest one.

Scope: per ADR-020, only manifests touched by the PR when --scope-to-diff is
given. Concept detection is label-driven and deliberately conservative: an entry
is only held to a concept when its label unambiguously names that concept, so an
app's domain-specific entries are never guessed at.

Exit 0 when there are no FAIL findings (warnings do not fail the gate).

Usage:
    check_icon_vocabulary.py <repo-root> [--changed-file PATH ...]
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
VOCAB_PATH = os.path.join(HERE, '..', 'schemas', 'semantic-icons.json')

# The rules this gate can enforce without node_modules are not all of them. When
# vue-material-design-icons is absent the "does this icon name exist upstream?"
# rule cannot run, and neither a FAIL nor a PASS is honest about that — the gate
# has shipped both errors in turn (.github#233). This status says the third
# thing, and the runner maps it to SKIPPED (wiring).
EXIT_TOOLING_MISSING = 5

# A manifest icon value that is NOT an MDI name: an image URL, a data URI, or a
# raw SVG path. All three stay legal per ADR-077 rule 1.
_URLISH = ('/', 'http://', 'https://', 'data:')


def _is_svg_path(value: str) -> bool:
    """Whether the value looks like a raw SVG path payload rather than a name."""
    return bool(re.match(r'^[Mm]\s*[-\d.]', value))


def _load_vocab() -> dict:
    with open(VOCAB_PATH, encoding='utf-8') as fh:
        return json.load(fh)


def _available_mdi_names(repo: str) -> set[str] | None:
    """Names in the app's installed vue-material-design-icons, or None.

    Returns None when the package is not installed — the gate then cannot tell an
    invented name from a real one and says so rather than passing silently.
    """
    for base in (
        os.path.join(repo, 'node_modules', 'vue-material-design-icons'),
        os.path.join(repo, '..', 'node_modules', 'vue-material-design-icons'),
    ):
        if os.path.isdir(base):
            return {f[:-4] for f in os.listdir(base) if f.endswith('.vue')}
    return None


def _manifest_paths(repo: str) -> list[str]:
    """Every file that can carry a renderable `icon`.

    OpenRegister register files are included, not just manifests: a schema's
    `icon` is drawn by CnIcon in index/detail headers exactly like a manifest
    one. Leaving them out is how shillinq's `calendar-sync` survived — it sits
    on the AppointmentSeries schema in lib/Settings/register.d/, which the gate
    never opened.
    """
    paths = []
    base = os.path.join(repo, 'src', 'manifest.json')
    if os.path.isfile(base):
        paths.append(base)
    paths += sorted(glob.glob(os.path.join(repo, 'src', 'manifest.d', '*.json')))
    paths += sorted(glob.glob(os.path.join(repo, 'lib', 'Settings', '*register*.json')))
    paths += sorted(glob.glob(os.path.join(repo, 'lib', 'Settings', 'register.d', '*.json')))
    return paths


def _registered_icon_names(repo: str) -> tuple[set[str] | None, str | None]:
    """(names the app registers, problem) from src/icons.js + src/main.js.

    Returns (None, None) when the app has no bootstrap to inspect.

    This is what closes the defect the ADR exists for: CnAppNav resolves an MDI
    menu icon only through the registry `registerIcons()` populates, with no
    fallback, so a name the app never registered renders NOTHING — 51 entries
    fleet-wide, 29 of hrmq's 72 nav rows. Eleven apps were calling
    `registerIcons()` with no arguments at all and looked fine only because
    every icon was still a bridged `icon-*` class.
    """
    main_js = os.path.join(repo, 'src', 'main.js')
    icons_js = os.path.join(repo, 'src', 'icons.js')
    if not os.path.isfile(main_js):
        return None, None

    src_main = open(main_js, encoding='utf-8').read()
    # Ignore comments so a `registerIcons()` mentioned in prose is not read as a call.
    code = '\n'.join(l for l in src_main.splitlines()
                     if not l.lstrip().startswith(('//', '*', '/*')))

    calls = re.findall(r'registerIcons\(([^)]*)\)', code)
    if not calls:
        return set(), 'src/main.js never calls registerIcons()'
    if all(c.strip() == '' for c in calls):
        return set(), ('src/main.js calls registerIcons() with NO arguments — it '
                       'registers nothing, so every MDI icon name renders blank')

    names: set[str] = set()
    for f in (icons_js, main_js):
        if not os.path.isfile(f):
            continue
        text = open(f, encoding='utf-8').read()
        for m in re.finditer(r"import\s+(\w+)\s+from\s+'vue-material-design-icons/([\w-]+)\.vue'", text):
            names.add(m.group(1))
            names.add(m.group(2))
        # aliased or shorthand entries in the exported map / inline object
        for m in re.finditer(r'^\s*([A-Za-z][A-Za-z0-9_]*)\s*(?::\s*[A-Za-z][A-Za-z0-9_]*)?\s*,\s*$',
                             text, re.M):
            names.add(m.group(1))
    return names, None


def _menu_entries(path: str):
    """Yield (label, icon, entry_id) for EVERY icon field in the document.

    Originally menu-only. Menus are the cross-app chrome and were migrated
    first, but page/tab/widget icons and `actions[]` / `headerActions[]` entries
    render through the same CnIcon registry and hit exactly the same failure
    modes — an unbridged `icon-*` renders an invisible white glyph on NC34+
    light themes wherever it appears, not just in the nav. So the gate now walks
    the whole tree.
    """
    try:
        with open(path, encoding='utf-8') as fh:
            data = json.load(fh)
    except (OSError, ValueError):
        return

    def walk(node, label_ctx, in_widget=False):
        if isinstance(node, dict):
            if node.get('type') == 'caption':
                return
            label = (node.get('title') or node.get('label') or node.get('name')
                     or node.get('id') or label_ctx)
            icon = node.get('icon')
            if isinstance(icon, str) and icon:
                yield (str(label or ''), icon, str(node.get('id') or ''), in_widget)
            for key, value in node.items():
                if key != 'icon':
                    # Once inside a `widgets` array, everything below it is a
                    # widget icon and stays flagged as one. See the Tier A
                    # concept check for why that distinction matters.
                    yield from walk(value, label, in_widget or key == 'widgets')
        elif isinstance(node, list):
            for item in node:
                yield from walk(item, label_ctx, in_widget)

    yield from walk(data, '')


# Label -> concept. Only unambiguous namings; an app's own domain wording is
# never guessed at. Matched against the lowercased, stripped label.
CONCEPT_LABELS = {
    'dashboard': 'dashboard',
    'documentation': 'documentation',
    'docs': 'documentation',
    'features & roadmap': 'features-roadmap',
    'features and roadmap': 'features-roadmap',
    'settings': 'settings',
    'instellingen': 'settings',
    'configuratie': 'settings',
    'configuration': 'settings',
    'search': 'search',
    'zoeken': 'search',
    'store': 'store',
    'about': 'about',
    'notifications': 'notifications',
    'notificaties': 'notifications',
    'audit trail': 'audit-trail',
    'audit log': 'audit-trail',
    'my work': 'my-work',
    'mijn werk': 'my-work',
}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('repo')
    ap.add_argument('--changed-file', action='append', default=None,
                    help='restrict to these manifest paths (ADR-020 diff scope)')
    args = ap.parse_args()

    repo = os.path.abspath(args.repo)
    vocab = _load_vocab()
    tier_a: dict = vocab['tierA']
    tier_b: dict = vocab['tierB']
    all_concepts = {**tier_a, **tier_b}
    canonical_icons = set(all_concepts.values())
    bridged = set(vocab['bridgedCssIcons'])
    content_block_icons = set((vocab.get('contentBlockIcons') or {}).get('names') or [])

    available = _available_mdi_names(repo)

    paths = _manifest_paths(repo)
    if args.changed_file:
        wanted = {os.path.abspath(os.path.join(repo, p)) for p in args.changed_file}
        paths = [p for p in paths if os.path.abspath(p) in wanted]

    if not paths:
        print('no manifest in scope — nothing to check')
        return 0

    fails: list[str] = []
    warns: list[str] = []
    used_mdi: set[str] = set()

    for path in paths:
        rel = os.path.relpath(path, repo)
        for label, icon, entry_id, in_widget in _menu_entries(path):
            where = f'{rel}: {label or entry_id or "?"}'
            if not icon:
                continue
            if icon.startswith(_URLISH) or _is_svg_path(icon):
                continue

            # A bare lowercase value belongs to the ContentBlocks dialect — a
            # SECOND, deliberately separate set owned by opencatalogi's page
            # editor and rendered by the public Softwarecatalogus website.
            # It is GOVERNED, not exempted: an earlier revision skipped every
            # lowercase value and that blanket exemption hid a real defect —
            # shillinq's AppointmentSeries schema carried `calendar-sync`, a
            # kebab-cased MDI name that resolves to nothing. Anything lowercase
            # that is not in the declared set is now a failure.
            if not icon.startswith('icon-') and not icon[:1].isupper():
                if icon not in content_block_icons:
                    fails.append(
                        f'{where}: icon "{icon}" is neither an MDI PascalCase name '
                        f'nor one of the ContentBlocks icons '
                        f'({", ".join(sorted(content_block_icons))}). A kebab-case or '
                        f'lowercase spelling of an MDI name resolves to nothing '
                        f'(ADR-077 rule 1).')
                continue

            if icon.startswith('icon-'):
                stem = re.sub(r'-(dark|white)$', '', icon)
                if icon not in bridged and stem not in bridged:
                    fails.append(
                        f'{where}: unbridged legacy icon "{icon}" — falls through to '
                        f'the raw NC CSS class, which can render invisible on NC34+ '
                        f'light themes. Use the canonical MDI name (ADR-077 rule 1).')
                else:
                    # Was a warning while the fleet still carried ~350 of these.
                    # Every app is now at zero, so a bridged legacy name is a
                    # REGRESSION, not debt — fail it.
                    fails.append(
                        f'{where}: legacy icon "{icon}" is deprecated and the fleet '
                        f'is fully migrated — use the canonical MDI name '
                        f'(ADR-077 rule 1).')
                continue

            # An MDI-style name from here on.
            if available is not None and icon not in available and icon not in canonical_icons:
                fails.append(
                    f'{where}: icon "{icon}" does not exist in '
                    f'vue-material-design-icons and is not a vocabulary icon — '
                    f'it renders blank wherever it is not aliased locally.')
                continue

            used_mdi.add(icon)

            # WIDGET icons are exempt from the concept MUST, and only from that.
            #
            # A widget icon renders through CnWidgetGrid's own `widgetIcons.js`
            # registry, which is a strict SUBSET of the CnIcon vocabulary this
            # gate governs. Where the two disagree the concept rule becomes
            # unsatisfiable: ADR-077 Tier A requires "CogOutline" for the
            # `settings` concept, `widgetIcons.js` ships "Cog" and NOT
            # "CogOutline", and gate-55 fails any widget icon outside that
            # registry. Verified against the installed library, not inferred:
            #
            #   grep -c CogOutline widgetIcons.js -> 0
            #   grep -c '\bCog\b'   widgetIcons.js -> 2
            #
            # So obeying this rule on a widget renders the "?" fallback, and
            # obeying gate-55 fails this one. Hit on hermiq#162, blocking a PR
            # on a contradiction it could not resolve.
            #
            # Everything ABOVE still applies to widgets — a nonexistent icon or
            # an unbridged `icon-*` is still a failure wherever it appears. Only
            # the "this concept must use exactly that glyph" rule steps aside,
            # because gate-55 already governs widget icons against the registry
            # that actually draws them.
            #
            # The better end state is reconciling the two registries (add the
            # Tier A glyphs to widgetIcons.js), after which this exemption can
            # go. Until then it is the difference between a gate that is strict
            # and one that is impossible.
            concept = CONCEPT_LABELS.get(label.strip().lower())
            if concept and in_widget is True:
                concept = None

            if concept:
                expected = all_concepts.get(concept)
                if expected and icon != expected:
                    msg = (f'{where}: concept "{concept}" must use "{expected}", '
                           f'found "{icon}"')
                    if concept in tier_a:
                        fails.append(msg + ' (ADR-077 Tier A — MUST).')
                    else:
                        warns.append(msg + ' (ADR-077 Tier B — SHOULD).')

    # Deliberately NOT checked here: "one icon used for N labels". ADR-077 rule 5
    # explicitly allows the same concept at different scopes to share a glyph
    # ("Mijn uren" / "Uren" / "Urenregistratie" are all `hours`), and concept is
    # not recoverable from arbitrary labels — so a label-count heuristic flags
    # correct manifests. The one-icon-one-concept invariant is enforced where it
    # is actually decidable: the vocabulary itself is a bijection, asserted by
    # nextcloud-vue's tests/components/semanticIcons.spec.js.

    # --- registry completeness -------------------------------------------
    # The gate's whole reason for existing: a name the app never registered
    # renders NOTHING in the navigation — no fallback glyph, no console error.
    registered, bootstrap_problem = _registered_icon_names(repo)
    if bootstrap_problem:
        fails.append(f'src/main.js: {bootstrap_problem} (ADR-077 rule 3).')
    elif registered is not None and used_mdi:
        unregistered = sorted(n for n in used_mdi if n not in registered)
        if unregistered:
            shown = ', '.join(unregistered[:8])
            more = f' (+{len(unregistered) - 8} more)' if len(unregistered) > 8 else ''
            fails.append(
                f'src/icons.js: {len(unregistered)} icon name(s) used by the '
                f'manifests are NOT registered — they render with no icon at all, '
                f'not a fallback: {shown}{more} (ADR-077 rule 3).')

    if available is None:
        # No silent caps: say what could not be checked.
        print('NOTE: vue-material-design-icons is not installed — could not verify '
              'that icon names exist upstream. Install dependencies for full coverage.')
        # ...and do not let the caller read that NOTE as a clean bill of health.
        #
        # This gate once reported 43 confident FAILs when node_modules was
        # absent ("Calendar does not exist"), turning an environment failure
        # into findings. That was fixed by guarding the existence check on
        # `available is not None` — which swapped it for the OPPOSITE error: the
        # check silently stops happening and the gate returns 0, so the runner
        # prints PASS over an invented-icon-name rule that never ran.
        #
        # Neither reading is honest. A missing dependency is a THIRD state:
        # not a finding, and not a pass. (.github#233)

    for w in warns:
        print(f'WARN  {w}')
    for f in fails:
        print(f'FAIL  {f}')

    print(f'\nchecked {len(paths)} manifest(s): {len(fails)} failure(s), '
          f'{len(warns)} warning(s)')
    if fails:
        return 1
    if available is None:
        # Clean on every rule that COULD run, but the invented-name rule could
        # not. The runner reports this as SKIPPED (wiring), which is visible to
        # --require-full-coverage. Returning 0 here would claim the icon names
        # were verified against the library when the library was not there.
        return EXIT_TOOLING_MISSING
    return 0


if __name__ == '__main__':
    sys.exit(main())
