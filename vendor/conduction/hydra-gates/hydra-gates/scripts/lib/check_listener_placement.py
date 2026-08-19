#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate 61 — listener-work-placement (ADR-078).

An object-lifecycle listener registered on a POST event (``ObjectCreatedEvent``,
``ObjectUpdatedEvent``, ``ObjectDeletedEvent``) runs INSIDE the object write. The
``*ing`` / ``*ed`` suffix already encodes the sync/async line — ``*ing`` listeners
may veto or mutate and therefore MUST stay synchronous; ``*ed`` listeners cannot
influence the write at all, so any real work they do is pure latency charged to
the user's request.

Nothing enforced that. Fleet-wide there are 149 registrations across 84 distinct
listeners in 15 apps, 134 of them on post events, and only three of them route
through the actor-forwarded deferral contract.

This gate fails when a post-event listener does real work synchronously without
saying why:

  FAIL  outbound I/O       an HTTP client (``IClient``), a mailer (``IMailer``)
                           or a raw ``curl_*`` call on the write path. A remote
                           endpoint's latency becomes the user's latency, and its
                           outage becomes the user's failed write.
  FAIL  write              ``saveObject()``, ``->insert(``, ``->update(``. A
                           second write inside the first one's request.
  FAIL  unbounded query    ``findAll(`` with no ``limit`` in the call. The
                           measured worked example is OpenRegister's
                           ``OpenRegisterFlowResolver``, which listed and
                           rendered EVERY flow object on EVERY object write just
                           to ask "is any flow wired to this event?".

Two things satisfy the gate:

  1. **Deferral.** The handler routes the work onto
     ``ListenerDeferralService::defer()``, which carries the acting user through
     to the background job. This is the preferred answer.
  2. **A reason-bearing annotation** on the handler method::

         /** @listener-placement inline sapi-memory — SubscriptionService::pushEvent
          *  stores via apcu_store, and APCu is per-SAPI; a cron worker writes a
          *  different segment than the SSE reader. */

     The category MUST be one of the four CLOSED ADR-078 exception categories
     (``realtime``, ``sapi-memory``, ``cheap-bounded``, ``correctness``) and
     MUST be followed by free text. A BARE ``@listener-placement inline`` with no
     category, or a category with no reason, FAILS — the escape hatch is
     auditable, not silent. Same shape as ``@spec exclude <reason>`` (gate 16)
     and ``@e2e exclude <reason>`` (gate 19).

Diff-scoped per ADR-020: a registration is only checked when the PR touched the
listener's own file, or when the PR added/modified that registration's lines in
``lib/AppInfo/Application.php``. The 149-registration backlog therefore never
blocks an unrelated PR — the gate pulls the fleet along one listener at a time.

Registration parsing handles BOTH call shapes, because the fleet uses both::

    $context->registerEventListener(ObjectCreatedEvent::class, FooListener::class);
    $context->registerEventListener(
        event: ObjectCreatedEvent::class,
        listener: FooListener::class
    );

A positional-only regex silently returns ZERO registrations for procest, shillinq
and pipelinq, which is how a naive count lands at 69 instead of 149.

Exit 0 when there are no FAIL findings.

Usage:
    check_listener_placement.py <repo-root> [--base REF] [--all]

    --base REF   diff base for scoping (default: $HYDRA_GATE_BASE_REF or
                 origin/development)
    --all        audit mode — ignore the diff and report on every registration.
                 Never used by the gate; useful for the fleet work-list.
"""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

GATE_NUM = 61

# The three POST events. A listener on one of these cannot influence the write,
# so its work does not belong on the request path.
# Status codes, same contract as check_store_and_settings_surface.py so the
# runner can tell "clean" from "I inspected nothing" (.github#240/#242/#276).
EXIT_EMPTY_SCOPE = 3      # scope resolved, selected nothing -> runner _skip na
EXIT_NOT_APPLICABLE = 4   # no post-event registration exists -> runner _skip na

POST_EVENTS = {
    'ObjectCreatedEvent',
    'ObjectUpdatedEvent',
    'ObjectDeletedEvent',
}

# The four CLOSED exception categories from ADR-078 D2. Closed on purpose: each
# was earned from a measured case, and a fifth needs an ADR amendment, not a new
# string in a docblock.
VALID_CATEGORIES = {
    'realtime',
    'sapi-memory',
    'cheap-bounded',
    'correctness',
}

# `registerEventListener` with either argument style. The class token allows a
# leading backslash and namespace separators so fully-qualified inline FQCNs
# (`\OCA\Foo\Listener\Bar::class`) parse as well as imported short names.
_CLASS_TOKEN = r'\\?[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*'

# Four registration shapes are live in the fleet and ALL of them must be seen:
#
#   registerEventListener(...)          the IBootstrap contract
#   addServiceListener(...)             the raw dispatcher, used by the
#                                       "OpenRegister absent" fallback branch
#   ObjectEventSubscription::subscribe  filtered subscription (openregister#2223)
#   register*Listener(...)              an app-local wrapper around the above —
#                                       procest's registerFilteredObjectListener
#                                       is the pattern the fleet copies
#
# Missing the last two would mean converting a listener to FILTERED subscription
# made it invisible to this gate, which is precisely the wrong incentive: the
# work is still synchronous when the filter matches.
_REGISTER_RE = re.compile(
    r'(?<![A-Za-z0-9_])(?<!function )'
    r'(?:registerEventListener|addServiceListener|register[A-Za-z]*Listener|subscribe)\s*\(',
)

# `Foo::class` or a quoted FQCN — procest registers OpenRegister's optional event
# classes by string so it carries no compile-time dependency on that app.
_REF = r'(?:(' + _CLASS_TOKEN + r')\s*::\s*class|[\'"](' + _CLASS_TOKEN + r')[\'"])'
_NAMED_EVENT_RE = re.compile(r'\bevent\s*:\s*' + _REF)
_NAMED_LISTENER_RE = re.compile(r'\blistener\s*:\s*' + _REF)
_POSITIONAL_RE = re.compile(_REF)
_EVENT_VAR_RE = re.compile(r'\bevent\s*:\s*\$[A-Za-z_][A-Za-z0-9_]*')

_USE_RE = re.compile(r'^\s*use\s+(' + _CLASS_TOKEN + r')\s*;', re.MULTILINE)

_HANDLER_RE = re.compile(
    r'^\s*(?:public|protected|private)?\s*function\s+(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(',
)

# The annotation. Everything after `inline` is optional at the REGEX level so a
# bare form is PARSED and then REJECTED with a specific message, rather than
# simply not matching and producing the generic "no justification" error.
_ANNOTATION_RE = re.compile(r'@listener-placement\s+inline\b')

# --- violation signals ------------------------------------------------------
#
# Word boundaries everywhere. A prior gate in this repo matched `dd(` inside
# `->add(` and produced a wave of phantom findings; `\b` on the left of each
# bare identifier is what prevents the same class of mistake here.
_SIGNALS: list[tuple[str, re.Pattern[str], str]] = [
    (
        'outbound-io',
        # `->newClient(` is the match that matters, not the `IClient` type name:
        # a listener injects IClientService in its CONSTRUCTOR (which this gate
        # deliberately skips) and reaches the network from the body via
        # `$this->clients->newClient()`. Matching only the type name would miss
        # every real caller in the fleet.
        re.compile(r'->\s*newClient\s*\(|(?<![A-Za-z0-9_])IClient(?:Service)?(?![A-Za-z0-9_])'),
        'uses an HTTP client (IClientService::newClient) on the write path — the '
        'remote\'s latency becomes the user\'s latency and its outage becomes a '
        'failed write',
    ),
    (
        'outbound-io',
        re.compile(r'->\s*createMessage\s*\(|(?<![A-Za-z0-9_])IMailer(?![A-Za-z0-9_])'),
        'sends mail (IMailer) on the write path',
    ),
    (
        'outbound-io',
        re.compile(r'(?<![A-Za-z0-9_>$])curl_[a-z_]+\s*\('),
        'makes a raw curl call on the write path',
    ),
    (
        'outbound-io',
        re.compile(r'file_get_contents\s*\(\s*[\'"]https?:'),
        'fetches a remote URL with file_get_contents on the write path',
    ),
    (
        'write',
        re.compile(r'->\s*saveObject\s*\('),
        'performs a write (saveObject) inside another object\'s write',
    ),
    (
        'write',
        re.compile(r'->\s*insert\s*\('),
        'performs a database insert inside the write',
    ),
    (
        'write',
        re.compile(r'->\s*update\s*\('),
        'performs a database update inside the write',
    ),
]

_FINDALL_RE = re.compile(r'->\s*findAll\s*\(')


def _git(args: list[str], cwd: Path) -> str:
    """Run git and return stdout, or '' when git is unavailable."""
    try:
        out = subprocess.run(
            ['git', '-c', 'safe.directory=*', *args],
            cwd=str(cwd),
            capture_output=True,
            text=True,
            check=False,
        )
        return out.stdout
    except OSError:
        return ''


def changed_lines(base_ref: str, cwd: Path) -> dict[str, set[int]]:
    """Return {relative_path: {added_line_numbers}} from ``git diff -U0``.

    Falls back from ``BASE...HEAD`` to ``BASE`` (working-tree diff) so the gate
    works both on a PR branch and on an uncommitted local checkout — the same
    fallback gates 16 and 19 use.
    """
    diff = _git(['diff', '-U0', '--diff-filter=ACMR', f'{base_ref}...HEAD'], cwd)
    if not diff.strip():
        diff = _git(['diff', '-U0', '--diff-filter=ACMR', base_ref], cwd)
    result: dict[str, set[int]] = {}
    current: str | None = None
    hunk_re = re.compile(r'^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@')
    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            current = line[6:]
            result.setdefault(current, set())
        elif line.startswith('+++ /dev/null'):
            current = None
        elif line.startswith('@@') and current is not None:
            m = hunk_re.match(line)
            if m:
                start = int(m.group(1))
                count = int(m.group(2)) if m.group(2) is not None else 1
                for n in range(start, start + count):
                    result[current].add(n)
    return result


def _balanced_span(text: str, open_idx: int) -> tuple[str, int]:
    """Return (inner_text, index_after_close) for the '(' at ``open_idx``.

    Quote-aware, so a ')' inside a PHP string literal does not close the call.
    """
    depth = 0
    i = open_idx
    quote: str | None = None
    n = len(text)
    while i < n:
        ch = text[i]
        if quote is not None:
            if ch == '\\':
                i += 2
                continue
            if ch == quote:
                quote = None
        elif ch in ("'", '"'):
            quote = ch
        elif ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1
            if depth == 0:
                return text[open_idx + 1:i], i + 1
        i += 1
    return text[open_idx + 1:], n


def _short(class_name: str) -> str:
    """Short class name for an imported or fully-qualified reference."""
    return class_name.lstrip('\\').rsplit('\\', 1)[-1]


def appinfo_files(repo: Path) -> list[Path]:
    """Every file that can carry an event-listener registration."""
    base = repo / 'lib' / 'AppInfo'
    if not base.is_dir():
        return []
    return sorted(p for p in base.rglob('*.php'))


def parse_registrations(path: Path) -> list[dict]:
    """Every ``registerEventListener`` call in ``path``, both argument styles.

    Each entry: {'event', 'listener', 'start_line', 'end_line'}.
    """
    try:
        text = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return []

    # Post events named anywhere in the file — used to attribute a registration
    # whose event argument is a variable (see the foreach case below).
    post_tokens = {e for e in POST_EVENTS
                   if re.search(r'(?<![A-Za-z0-9_])' + e + r'(?![A-Za-z0-9_])', text)}

    found: list[dict] = []
    for m in _REGISTER_RE.finditer(text):
        open_idx = text.index('(', m.start())
        inner, after = _balanced_span(text, open_idx)

        named_event = _NAMED_EVENT_RE.search(inner)
        named_listener = _NAMED_LISTENER_RE.search(inner)
        start_line = text.count('\n', 0, m.start()) + 1
        end_line = text.count('\n', 0, after) + 1

        if named_listener:
            listener = named_listener.group(1) or named_listener.group(2)
            if named_event:
                found.append({
                    'event': _short(named_event.group(1) or named_event.group(2)),
                    'listener': _short(listener),
                    'start_line': start_line,
                    'end_line': end_line,
                })
                continue
            # The event arrives through a VARIABLE. zaakafhandelapp registers
            # one listener for six events by looping over a class constant:
            #
            #   foreach (self::OBJECT_EVENTS as $event) {
            #       $this->registerFilteredObjectListener(event: $event, ...);
            #
            # Reading only the literal argument scores that app ZERO — the same
            # class of blind spot as a positional-only regex. Attribute it to
            # every post event the file names statically.
            if _EVENT_VAR_RE.search(inner):
                for post in sorted(post_tokens):
                    found.append({
                        'event': post,
                        'listener': _short(listener),
                        'start_line': start_line,
                        'end_line': end_line,
                    })
            continue

        # Positional: registerEventListener(Event::class, Listener::class).
        positional = [(a or b) for a, b in _POSITIONAL_RE.findall(inner)]
        if len(positional) < 2:
            continue
        found.append({
            'event': _short(positional[0]),
            'listener': _short(positional[1]),
            'start_line': start_line,
            'end_line': end_line,
        })
    return found


def resolve_listener_file(repo: Path, appinfo: Path, short_name: str) -> Path | None:
    """Map a listener short name to its file, via the `use` imports first.

    Import-first matters: two apps in the fleet have same-named listeners in
    different namespaces, and a filename-only search would pick whichever the
    glob happened to return.
    """
    try:
        text = appinfo.read_text(encoding='utf-8', errors='replace')
    except OSError:
        text = ''

    for fqcn in _USE_RE.findall(text):
        if _short(fqcn) != short_name:
            continue
        parts = fqcn.lstrip('\\').split('\\')
        # OCA\<App>\Listener\Foo  ->  lib/Listener/Foo.php
        if len(parts) >= 3 and parts[0] == 'OCA':
            candidate = repo / 'lib' / Path(*parts[2:]).with_suffix('.php')
            if candidate.is_file():
                return candidate

    matches = sorted((repo / 'lib').rglob(f'{short_name}.php')) if (repo / 'lib').is_dir() else []
    return matches[0] if matches else None


def _method_spans(lines: list[str]) -> list[tuple[str, int, int]]:
    """(method_name, decl_index, end_index_exclusive) for each method, by braces."""
    spans: list[tuple[str, int, int]] = []
    for idx, line in enumerate(lines):
        m = _HANDLER_RE.match(line)
        if not m:
            continue
        depth = 0
        started = False
        end = idx
        for j in range(idx, len(lines)):
            depth += lines[j].count('{') - lines[j].count('}')
            if '{' in lines[j]:
                started = True
            if started and depth <= 0:
                end = j + 1
                break
        else:
            end = len(lines)
        spans.append((m.group('name'), idx, end))
    return spans


def _docblock_above(lines: list[str], decl_idx: int) -> list[str]:
    """The ``/** ... */`` block directly above ``decl_idx``, or []."""
    i = decl_idx - 1
    while i >= 0 and (lines[i].strip() == '' or lines[i].strip().startswith('#[')):
        i -= 1
    if i < 0 or not lines[i].strip().endswith('*/'):
        return []
    end = i
    while i >= 0 and not lines[i].strip().startswith('/**'):
        i -= 1
    if i < 0:
        return []
    return lines[i:end + 1]


def classify_annotation(block: list[str]) -> tuple[str, str | None]:
    """Classify a ``@listener-placement inline`` annotation in ``block``.

    Returns one of:
      ('none', None)                 — no annotation present
      ('ok', category)               — category valid AND a reason follows
      ('no-category', None)          — bare `@listener-placement inline`
      ('bad-category', category)     — outside the four closed ADR-078 categories
      ('no-reason', category)        — valid category, no free text after it
    """
    # Strip the docblock furniture first (leading `*`, the `/**` opener and the
    # `*/` terminator), so the annotation and its reason read as plain prose.
    # A reason that wraps onto the next docblock line is normal and MUST count —
    # requiring it to fit on one line would push authors towards a terse
    # unhelpful reason, which is the opposite of the point.
    cleaned: list[str] = []
    for raw in block:
        line = raw.strip()
        line = re.sub(r'^/\*\*', '', line)
        line = re.sub(r'\*/\s*$', '', line)
        line = re.sub(r'^\*\s?', '', line)
        cleaned.append(line.strip())

    idx = next((i for i, line in enumerate(cleaned) if _ANNOTATION_RE.search(line)), None)
    if idx is None:
        return ('none', None)

    head = _ANNOTATION_RE.split(cleaned[idx], maxsplit=1)[-1]
    tail: list[str] = [head]
    # Continuation lines, up to the next `@tag` or a blank line.
    for line in cleaned[idx + 1:]:
        if not line or line.startswith('@'):
            break
        tail.append(line)
    rest = ' '.join(t for t in tail if t).strip()
    if not rest:
        return ('no-category', None)

    parts = rest.split(None, 1)
    category = parts[0].strip().rstrip(':').rstrip('—').rstrip('-')
    if category not in VALID_CATEGORIES:
        return ('bad-category', category)

    # Drop the separator (em dash, en dash, hyphen or colon) and require actual
    # words to remain — `inline sapi-memory` alone is not a justification.
    reason = re.sub(r'^[\s—–:-]+', '', parts[1] if len(parts) > 1 else '').strip()
    if len(reason) < 3:
        return ('no-reason', category)
    return ('ok', category)


def _strip_comments(text: str) -> str:
    """Blank out comments so a signal named only in prose is not a finding."""
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.DOTALL)
    text = re.sub(r'(?m)//.*$', '', text)
    text = re.sub(r'(?m)^\s*#(?!\[).*$', '', text)
    return text


def scan_body(text: str) -> list[tuple[str, str]]:
    """Violation signals present in ``text``. Returns [(kind, message)]."""
    code = _strip_comments(text)
    findings: list[tuple[str, str]] = []
    seen: set[str] = set()

    for kind, pattern, message in _SIGNALS:
        if pattern.search(code) and message not in seen:
            seen.add(message)
            findings.append((kind, message))

    for m in _FINDALL_RE.finditer(code):
        open_idx = code.index('(', m.start())
        inner, _ = _balanced_span(code, open_idx)
        if 'limit' not in inner:
            msg = ('runs an UNBOUNDED findAll() on the write path — this is the '
                   'OpenRegisterFlowResolver failure mode: every object listed and '
                   'rendered on every write')
            if msg not in seen:
                seen.add(msg)
                findings.append(('unbounded-query', msg))
            break
    return findings


def analyse_listener(path: Path) -> dict:
    """Analyse one listener file.

    Returns {'handlers': [...], 'defers': bool}. ``handlers`` carries one entry
    per ``handle*`` method with its annotation status; ``defers`` is whether the
    class routes anything through ListenerDeferralService.
    """
    try:
        raw = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return {'handlers': [], 'defers': False, 'findings': []}

    lines = raw.splitlines()
    spans = _method_spans(lines)

    code = _strip_comments(raw)
    defers = bool(
        re.search(r'(?<![A-Za-z0-9_])ListenerDeferralService(?![A-Za-z0-9_])', code)
        and re.search(r'->\s*defer\s*\(', code)
    )

    handlers = []
    findings: list[tuple[str, str]] = []
    for name, decl_idx, end_idx in spans:
        # __construct is skipped deliberately: its signature names the injected
        # types (IClient, IMailer, ...) without performing any of that work, so
        # scanning it would flag every listener that merely holds a dependency.
        if name == '__construct':
            continue
        body = '\n'.join(lines[decl_idx:end_idx])
        found = scan_body(body)
        if found:
            findings.extend(found)
        if name.startswith('handle'):
            status, category = classify_annotation(_docblock_above(lines, decl_idx))
            handlers.append({'name': name, 'status': status, 'category': category})

    # De-duplicate while preserving order.
    unique: list[tuple[str, str]] = []
    seen: set[str] = set()
    for kind, msg in findings:
        if msg not in seen:
            seen.add(msg)
            unique.append((kind, msg))
    return {'handlers': handlers, 'defers': defers, 'findings': unique}


def main() -> int:
    parser = argparse.ArgumentParser(description='Gate 61 — listener-work-placement')
    parser.add_argument('repo', nargs='?', default='.')
    parser.add_argument('--base', default=os.environ.get('HYDRA_GATE_BASE_REF', 'origin/development'))
    parser.add_argument('--all', action='store_true',
                        help='audit every registration, ignoring the diff')
    args = parser.parse_args()

    repo = Path(args.repo).resolve()
    infos = appinfo_files(repo)
    if not infos:
        print('checked 0 registration(s) — no lib/AppInfo, nothing to place.')
        return EXIT_NOT_APPLICABLE

    if args.all:
        touched: dict[str, set[int]] = {}
    else:
        # FAIL CLOSED ON AN UNRESOLVABLE BASE (hydra#399's lesson, applied here).
        # An unresolvable base yields an EMPTY changed-line set, every
        # registration falls out of scope, and the gate reports PASS having
        # inspected nothing — indistinguishable from a genuinely clean PR.
        probe = _git(['rev-parse', '--verify', '--quiet', f'{args.base}^{{commit}}'], repo)
        if not probe.strip():
            # Most repos that trip this simply call their mainline something
            # else (design-system is on `main`). Try the remote's own default
            # before refusing, exactly as run-hydra-gates.sh does.
            auto = _git(['symbolic-ref', '--quiet', '--short',
                         'refs/remotes/origin/HEAD'], repo).strip()
            if auto and _git(['rev-parse', '--verify', '--quiet',
                              f'{auto}^{{commit}}'], repo).strip():
                print(f'NOTE: base "{args.base}" does not exist — using the remote '
                      f'default "{auto}" instead.')
                args.base = auto
            else:
                print(f'FAIL  diff base "{args.base}" does not resolve in this '
                      f'repository, and origin/HEAD gives no usable default. '
                      f'Scoping to it would put every registration out of scope '
                      f'and report PASS without inspecting anything. '
                      f'Set --base <ref> or HYDRA_GATE_BASE_REF.')
                print('\nchecked 0 post-event registration(s): 1 failure(s)')
                return 1
        touched = changed_lines(args.base, repo)

    fails: list[str] = []
    checked = 0
    skipped_out_of_scope = 0
    unresolved: list[str] = []

    for appinfo in infos:
        rel_appinfo = appinfo.relative_to(repo).as_posix()
        appinfo_touched = touched.get(rel_appinfo, set())

        for reg in parse_registrations(appinfo):
            if reg['event'] not in POST_EVENTS:
                continue

            listener_path = resolve_listener_file(repo, appinfo, reg['listener'])
            rel_listener = listener_path.relative_to(repo).as_posix() if listener_path else None

            if not args.all:
                # In scope when the PR touched the listener's own file, or when
                # it added/modified THESE registration lines. Touching
                # Application.php for an unrelated reason must not re-open the
                # whole backlog — that is the ADR-020 rule.
                reg_touched = any(
                    n in appinfo_touched
                    for n in range(reg['start_line'], reg['end_line'] + 1)
                )
                file_touched = rel_listener is not None and rel_listener in touched
                if not reg_touched and not file_touched:
                    skipped_out_of_scope += 1
                    continue

            checked += 1

            if listener_path is None:
                # Do NOT pass silently on an unresolvable listener: a name that
                # resolves to no file is either a broken registration or a gate
                # blind spot, and both deserve to be seen.
                unresolved.append(
                    f'{rel_appinfo}:{reg["start_line"]}: {reg["event"]} -> '
                    f'{reg["listener"]} — no class file found under lib/')
                continue

            info = analyse_listener(listener_path)
            if not info['findings']:
                continue
            if info['defers']:
                continue

            statuses = {h['status'] for h in info['handlers']}
            if 'ok' in statuses:
                continue

            where = f'{rel_listener} ({reg["event"]})'
            detail = '; '.join(msg for _, msg in info['findings'])

            if 'no-category' in statuses:
                fails.append(
                    f'{where}: bare `@listener-placement inline` with no category. '
                    f'Name one of {sorted(VALID_CATEGORIES)} and give a reason '
                    f'(ADR-078 D5). It {detail}.')
            elif 'bad-category' in statuses:
                bad = next(h['category'] for h in info['handlers']
                           if h['status'] == 'bad-category')
                fails.append(
                    f'{where}: `@listener-placement inline {bad}` is not one of the '
                    f'four closed ADR-078 categories {sorted(VALID_CATEGORIES)}. '
                    f'A fifth category needs an ADR amendment, not a new string.')
            elif 'no-reason' in statuses:
                cat = next(h['category'] for h in info['handlers']
                           if h['status'] == 'no-reason')
                fails.append(
                    f'{where}: `@listener-placement inline {cat}` carries no reason. '
                    f'The escape hatch is auditable, not silent (ADR-078 D5).')
            else:
                fails.append(
                    f'{where}: {detail}, and it neither defers via '
                    f'ListenerDeferralService nor carries '
                    f'`@listener-placement inline <category> — <reason>` on its '
                    f'handler (ADR-078: post-`*ed` work is async by default).')

    for u in unresolved:
        print(f'FAIL  {u}')
    for f in fails:
        print(f'FAIL  {f}')

    total = len(fails) + len(unresolved)
    scope = 'all' if args.all else f'diff vs {args.base}'
    print(f'\nchecked {checked} post-event registration(s) [{scope}], '
          f'{skipped_out_of_scope} out of scope: {total} failure(s)')
    if total:
        return 1
    # AN EXIT CODE IS A STATUS (.github#276, borrowing #240/#242's convention
    # from check_store_and_settings_surface.py verbatim).
    #
    # "I checked N registrations and they were fine" and "the diff put all N
    # out of scope, so I checked none" were the same 0, and the runner printed
    # PASS for both. This gate is ALWAYS diff-scoped by design, so the second
    # is the common case on any PR that does not touch a listener — a PASS
    # asserted about work placement no run inspected.
    if checked == 0 and skipped_out_of_scope > 0:
        return EXIT_EMPTY_SCOPE
    if checked == 0:
        return EXIT_NOT_APPLICABLE
    return 0


if __name__ == '__main__':
    sys.exit(main())
