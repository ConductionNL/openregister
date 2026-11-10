#!/usr/bin/env python3
"""Which UNMARKED schemas are reachable from a #[PublicPage] endpoint?

Task 2 of rbac-default-authenticated needs the intended-public set. Keyword
guessing produced four candidates out of 504 and missed shillinq's public
widget entirely, so this asks a different question: which schema does a
PUBLIC endpoint actually name?

READ-ONLY. It modifies nothing; it prints a candidate list for review.

Two deliberate properties:

  * It iterates EVERY lib/Settings/*register*.json. An earlier survey used
    `head -1` and reported 321/368 across 6 apps instead of 504/571 across 15.
  * It matches schema names CASE-INSENSITIVELY. Controllers say 'Appointment'
    where the register says 'appointment'; an exact match silently drops them,
    and a silent drop here reads as "no public schema in this app".

It is a FLOOR, not a census: a controller that reaches a schema through a
service two frames down, or names it via a variable, is invisible here.
"""

import json
import pathlib
import re
import sys

ROOT = pathlib.Path('/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra')

APPS = [
    'scholiq', 'shillinq', 'procest', 'openconnector', 'decidesk', 'hermiq',
    'pipelinq', 'docudesk', 'larpingapp', 'portaliq', 'openregister',
    'petstore', 'doriath', 'launchpad', 'opencatalogi', 'softwarecatalog',
    'openbuild', 'zaakafhandelapp', 'nldesign',
]

PUBLIC_RE = re.compile(r'#\[PublicPage\]|@PublicPage')
# Any single-quoted or double-quoted bare identifier. Over-collects on
# purpose; the intersection with real schema names does the filtering.
IDENT_RE = re.compile(r"""['"]([A-Za-z][A-Za-z0-9_]{2,})['"]""")


def schemas_of(app_dir):
    """Map lowercased schema name -> (name, has_authorization, register file)."""
    out = {}
    settings = app_dir / 'lib' / 'Settings'
    if not settings.is_dir():
        return out
    for reg in sorted(settings.glob('*register*.json')):
        try:
            doc = json.loads(reg.read_text(encoding='utf-8'))
        except Exception as exc:                       # noqa: BLE001
            print(f'  !! {reg.name}: unreadable ({exc})', file=sys.stderr)
            continue
        schemas = (doc.get('components') or {}).get('schemas') or {}
        for name, body in schemas.items():
            if not isinstance(body, dict):
                continue
            marked = isinstance(body.get('authorization'), dict)
            out[name.lower()] = (name, marked, reg.name)
    return out


def public_identifiers(app_dir):
    """Identifiers quoted inside files that declare a public endpoint."""
    idents = set()
    ctrl = app_dir / 'lib' / 'Controller'
    if not ctrl.is_dir():
        return idents
    for php in ctrl.rglob('*.php'):
        try:
            text = php.read_text(encoding='utf-8', errors='replace')
        except OSError:
            continue
        if not PUBLIC_RE.search(text):
            continue
        idents |= {m.group(1).lower() for m in IDENT_RE.finditer(text)}
    return idents


total_hits = 0
for app in APPS:
    app_dir = ROOT / app
    if not app_dir.is_dir():
        continue
    schemas = schemas_of(app_dir)
    if not schemas:
        continue
    idents = public_identifiers(app_dir)
    hits = []
    for key, (name, marked, reg) in sorted(schemas.items()):
        if key in idents:
            hits.append((name, marked, reg))
    unmarked_hits = [h for h in hits if not h[1]]
    if not hits:
        continue
    total_hits += len(unmarked_hits)
    print(f'\n== {app} — {len(schemas)} schemas, '
          f'{len(hits)} named by a public endpoint, '
          f'{len(unmarked_hits)} of those UNMARKED')
    for name, marked, reg in hits:
        flag = 'marked  ' if marked else 'UNMARKED'
        print(f'   {flag}  {name}   ({reg})')

print(f'\nTOTAL unmarked schemas named by a public endpoint: {total_hits}')
