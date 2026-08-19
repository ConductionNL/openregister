#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate 31 helper — relation-dialect (ADR-062 rules 6/7/10).

Enforces the ONE canonical OpenRegister relation dialect across changed
register files (``lib/Settings/*register*.json`` + ``lib/Settings/register.d/
*.json``). A relation is a schema PROPERTY carrying ``type: string`` (or array
``items``), ``format: uuid`` and ``$ref: <schemaKey>`` (same register set);
``x-relation-filter`` rides on the same property. Bespoke per-schema dialects
are banned. Observed 2026-07-08 across the fleet detail-page redesign:
decidesk shipped per-schema ``x-openregister-relations`` blocks nothing
consumed (retired 2026-07-08), scholiq used bare-string FKs-by-convention
(85 converted), and procest's ``case.status`` proved the rule-10 lifecycle
carve-out (an FK-scoped editable picker, NOT a frozen ``readOnly`` field).

NESTING (fixed 2026-08-08, issue #231)
--------------------------------------
Properties are collected RECURSIVELY through ``items.properties.*`` (arrays of
objects) and ``properties.*`` (inline objects), because a relation is a relation
at any depth. Before the fix the collector was a single non-recursive loop over
``schema.properties.*`` while ``_raw_walk`` recursed into everything, so the gate
was wrong in BOTH directions at once:

  * FALSE POSITIVE on check (c) — a correctly shaped nested relation
    (``character.requirementOverrides.items.properties.skill`` on larpingapp:
    ``type`` + ``format:uuid`` + ``$ref`` + the filter on the same property) was
    reported as "placed off a property" UNCONDITIONALLY, because the collector
    could never contain it. The finding was unfixable in the app — the three
    ways to clear it (move the filter off the property, flatten the array, drop
    the filter) are all wrong.
  * FALSE NEGATIVE on checks (b)/(d)/(f) — a nested relation missing its
    ``$ref``, carrying a dangling ``$ref`` or an invalid filter token was never
    inspected at all.

Two invariants survive the recursion and are covered by regression tests:
  * ``items`` itself is NOT a property. A filter riding on the ``items`` node
    (rather than on one of ``items.properties.*``) is still a real rule-6
    violation and is still reported.
  * ``@object.<field>`` in check (d) resolves against the ROOT schema's
    properties at every depth — ``@object`` is the object under edit, not the
    nested array element. larpingapp's nested ``@object.setting`` points at
    ``character.setting``, a top-level property.

Checks (each offending location prints one finding to stdout; WARN-prefixed
lines are advisory and never fail the gate):
  a. BANNED DIALECT   — any ``x-openregister-relations`` key anywhere in a
                        changed register file.
  b. RELATION SHAPE   — a property (or its items) ADDED/MODIFIED in the diff
                        with ``format: uuid`` + a relation-shaped description
                        but NO ``$ref`` (conservative: format:uuid alone is
                        NOT enough — NC-user-id fields legitimately lack $ref).
                        Two identifiers are exempt because no local schema key
                        can express them: ``x-external-register`` (the target
                        lives in another app's register) and
                        ``x-relation-schema-field`` (POLYMORPHIC — the target is
                        named per row by a sibling discriminator). Neither is a
                        waiver: the discriminator must be a real sibling
                        property, and both forbid a ``$ref``.
  c. FILTER PLACEMENT — ``x-relation-filter`` anywhere except directly on a
                        property; or on a property that is not a relation
                        (filter on non-relation is inert).
  d. FILTER TOKENS    — every ``@``-prefixed filter value must be ``@objectId``
                        or ``@object.<existingPropertyOfSameSchema>``; unknown
                        tokens, nonexistent fields and two-hop ``@object.a.b``
                        all fail.
  e. FROZEN LIFECYCLE — a ``$ref`` + ``readOnly:true`` property on a schema
                        whose ``x-openregister-lifecycle`` has no ``transitions``
                        block and whose lifecycle ``field`` equals that property
                        (rule 10: readOnly with no expressible transitions =
                        permanently frozen).
  f. $REF TARGETS     — a string ``$ref`` must resolve to a schema key in the
                        same register file set (case-exact). A numeric ``$ref``
                        is allowed (live-schema form) but WARNs.

Diff-scoping (ADR-020): only the changed register files passed on argv are
inspected, so legacy debt in an untouched register never blocks an unrelated
PR. Check (b) is refined further to the PROPERTY level — when
``HYDRA_GATE_BASE_REF`` is set it only flags properties whose declaration line
is ADDED or MODIFIED vs the base ref (going-forward enforcement, exactly like
gate 28). Checks a/c/d/e/f apply to the whole changed file per the gate
contract (a banned dialect or a dangling $ref anywhere in a file the PR
touched is a structural defect).

FINDING COUNT vs DEFECT COUNT
----------------------------
A finding count is not a defect count. Gate 53 turned ~132 defects into "240
violations" because one missing ``_note`` emitted a triplet. This helper was
audited for the same shape on 2026-08-08 and has exactly ONE overlap: a property
carrying ``x-relation-filter`` but no ``$ref`` matches check (b) ("lacks
canonical $ref") AND check (c) ("filter on a non-relation is inert") — one
defect, one fix, two messages. Check (c)'s duplicate is now suppressed when (b)
already reported that property, so the ratio here is 1:1. Checks (a), (d), (e)
and (f) each emit once per offending location and do not overlap each other:
(d) is intentionally per-TOKEN, so a filter with three bad tokens is three
independent defects, not one.

Usage:
    check_relation_dialect.py <log-path> <register.json> [<register.json> ...]
"""

import glob
import json
import os
import re
import subprocess
import sys


# --------------------------------------------------------------------------
# Position-tracking JSON parse (shared shape with check_schema_property_meta.py)
# — each object dict remembers the source line of its direct keys so findings
# can be mapped back to the diff for property-level scoping.
# --------------------------------------------------------------------------
class _LineDict(dict):
    __slots__ = ("key_lines",)

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.key_lines = {}


_TOKEN_RE = re.compile(
    r"""
      (?P<ws>[ \t\r\n]+)
    | (?P<str>"(?:[^"\\]|\\.)*")
    | (?P<punct>[{}\[\]:,])
    | (?P<lit>true|false|null)
    | (?P<num>-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)
    """,
    re.VERBOSE,
)


def _tokenize(text):
    line = 1
    pos = 0
    length = len(text)
    while pos < length:
        m = _TOKEN_RE.match(text, pos)
        if not m:
            raise ValueError(f"Unexpected character at offset {pos}: {text[pos]!r}")
        kind = m.lastgroup
        value = m.group()
        tok_line = line
        line += value.count("\n")
        pos = m.end()
        if kind == "ws":
            continue
        yield kind, value, tok_line


class _Parser:
    def __init__(self, text):
        self._tokens = list(_tokenize(text))
        self._i = 0

    def _peek(self):
        return self._tokens[self._i] if self._i < len(self._tokens) else (None, None, None)

    def _next(self):
        tok = self._tokens[self._i]
        self._i += 1
        return tok

    def parse(self):
        return self._parse_value()

    def _parse_value(self):
        kind, value, line = self._peek()
        if kind == "punct" and value == "{":
            return self._parse_object()
        if kind == "punct" and value == "[":
            return self._parse_array()
        if kind == "str":
            self._next()
            return json.loads(value)
        if kind == "num":
            self._next()
            return json.loads(value)
        if kind == "lit":
            self._next()
            return {"true": True, "false": False, "null": None}[value]
        raise ValueError(f"Unexpected token {value!r} at line {line}")

    def _parse_object(self):
        obj = _LineDict()
        self._next()
        kind, value, _ = self._peek()
        if kind == "punct" and value == "}":
            self._next()
            return obj
        while True:
            kkind, kval, kline = self._next()
            if kkind != "str":
                raise ValueError(f"Expected object key, got {kval!r} at line {kline}")
            key = json.loads(kval)
            ckind, cval, cline = self._next()
            if not (ckind == "punct" and cval == ":"):
                raise ValueError(f"Expected ':' at line {cline}")
            obj[key] = self._parse_value()
            obj.key_lines[key] = kline
            nkind, nval, nline = self._next()
            if nkind == "punct" and nval == ",":
                continue
            if nkind == "punct" and nval == "}":
                break
            raise ValueError(f"Expected ',' or '}}' at line {nline}")
        return obj

    def _parse_array(self):
        arr = []
        self._next()
        kind, value, _ = self._peek()
        if kind == "punct" and value == "]":
            self._next()
            return arr
        while True:
            arr.append(self._parse_value())
            nkind, nval, nline = self._next()
            if nkind == "punct" and nval == ",":
                continue
            if nkind == "punct" and nval == "]":
                break
            raise ValueError(f"Expected ',' or ']' at line {nline}")
        return arr


# --------------------------------------------------------------------------
# Diff-scope helpers (shared shape with check_schema_property_meta.py).
# --------------------------------------------------------------------------
def _changed_lines(file_path, base_ref):
    try:
        proc = subprocess.run(
            ["git", "diff", "-U0", "--no-color", base_ref, "--", file_path],
            capture_output=True, text=True, check=False,
        )
    except (OSError, ValueError):
        return None
    if proc.returncode != 0:
        return None
    changed = set()
    saw_hunk = False
    for line in proc.stdout.splitlines():
        if not line.startswith("@@"):
            continue
        saw_hunk = True
        m = re.search(r"\+(\d+)(?:,(\d+))?", line)
        if not m:
            continue
        start = int(m.group(1))
        count = int(m.group(2)) if m.group(2) is not None else 1
        for ln in range(start, start + count):
            changed.add(ln)
    if not saw_hunk:
        if _is_tracked_at(file_path, base_ref):
            return set()
        return None
    return changed


def _is_tracked_at(file_path, base_ref):
    try:
        proc = subprocess.run(
            ["git", "cat-file", "-e", f"{base_ref}:{file_path}"],
            capture_output=True, text=True, check=False,
        )
        return proc.returncode == 0
    except (OSError, ValueError):
        return False


# --------------------------------------------------------------------------
# Register-set discovery — the global schema-key set is needed so a string
# $ref in a changed file resolves against EVERY register (base + fragments),
# not only the changed file.
# --------------------------------------------------------------------------
def _settings_dir(path):
    marker = os.path.join("lib", "Settings")
    norm = os.path.normpath(path)
    idx = norm.find(marker)
    if idx == -1:
        return None
    return norm[: idx + len(marker)]


def _schemas_of(doc):
    if not isinstance(doc, dict):
        return {}
    schemas = (doc.get("components") or {}).get("schemas")
    if isinstance(schemas, dict) and schemas:
        return schemas
    # Root-level single-schema fragment.
    if isinstance(doc.get("properties"), dict):
        name = doc.get("title") or doc.get("slug") or "root"
        return {name: doc}
    return {}


def _global_schema_keys(paths):
    """Every name a `$ref` may legitimately point at.

    This is the JSON object key AND each schema's declared ``slug``, because the
    slug is what OpenRegister actually stores and serves — a `$ref` is resolved
    against the slug, not against the key the schema happens to sit under in the
    register document.

    Collecting only the keys produced false positives fleet-wide (hermiq
    2026-08-02: 26 findings, every one of them a correct reference). The two are
    NOT merely a casing difference and cannot be normalised into each other:

        "Skill":    { "slug": "agentskill", ... }   ->  $ref: "agentskill"

    Demanding the key there would have required `$ref: "Skill"`, which resolves
    to nothing at runtime — so "fixing" the findings would have broken every
    relation the gate was written to protect.
    """
    keys = set()
    seen = set()
    for p in paths:
        sd = _settings_dir(p)
        if not sd or sd in seen:
            continue
        seen.add(sd)
        candidates = glob.glob(os.path.join(sd, "*register*.json"))
        candidates += glob.glob(os.path.join(sd, "register.d", "*.json"))
        for c in candidates:
            try:
                with open(c, encoding="utf-8") as fh:
                    doc = json.load(fh)
            except (OSError, ValueError):
                continue
            for name, schema in _schemas_of(doc).items():
                keys.add(name)
                if isinstance(schema, dict):
                    slug = schema.get("slug")
                    if isinstance(slug, str) and slug != "":
                        keys.add(slug)
    return keys


# --------------------------------------------------------------------------
# Relation helpers.
# --------------------------------------------------------------------------
_RELATION_DESC_RE = re.compile(r"\b(reference to|verwijzing naar|uuid of the|fk to)\b", re.I)


def _is_external_ref(prop):
    """True when a property points at a schema in ANOTHER app's register.

    OpenRegister resolves ``$ref`` inside ONE register set, so a schema owned by
    a different app is not expressible as a relation at all. Registers mark such
    properties with ``x-external-register: <app>`` and carry the bare identifier
    (``type: string`` + ``format: uuid``).

    Without this notion the gate was UNCLOSABLE for a genuine cross-app
    reference — measured 2026-08-09 on docudesk, whose `correspondence.
    caseReference` and `generatedDocument.zaakId` point at a Zaak in Procest:

      WITH    ``"$ref": "case"``  -> check (f) "does not resolve to a schema key
                                     in the register set (case-exact)"
      WITHOUT ``$ref``            -> check (b) "relation-shaped property ...
                                     lacks canonical $ref (ADR-062 rule 7)"

    Both arms failed, so the only route to green was rewording the description
    until ``_RELATION_DESC_RE`` stopped matching — degrading documentation to
    dodge a regex. The exemption is deliberately narrow: it suppresses ONLY the
    two ``$ref`` rules, and a property that declares ``x-external-register``
    *and* a ``$ref`` is still reported, because that ``$ref`` is one
    OpenRegister can never resolve.
    """
    return isinstance(prop, dict) and bool(str(prop.get("x-external-register") or "").strip())


def _discriminator_of(prop):
    """The sibling property that names this identifier's target schema, or ``''``.

    A POLYMORPHIC reference points at a different schema per ROW: the target is
    chosen by a sibling discriminator field, not fixed at author time. A single
    ``$ref`` cannot express it, and picking one of the possible targets would
    make the register state something false — OpenRegister would then resolve
    every row against that one schema.

    Measured 2026-08-10 on scholiq (gate package 94c855b). Two report rows
    carry exactly this shape:

      ``CoursePackageImportReport.entries[].targetId`` — "UUID of the created
      object named by targetType", where the sibling ``targetType`` is one of
      Course / Lesson / Material / Item / LtiToolPlacement / Assignment / Rubric.
      ``LearningRecordExport.coverageReport[].sourceId`` — "UUID of the source
      object this entry reports on", discriminated by the sibling
      ``sourceSchema``.

    Both arms failed, exactly as the cross-app case did before
    ``x-external-register`` existed:

      WITH    a ``$ref``  -> the register asserts ONE target for a field whose
                             target varies per row; check (f) passes only
                             because the lie resolves.
      WITHOUT a ``$ref``  -> check (b) "relation-shaped property ... lacks
                             canonical $ref (ADR-062 rule 7)".

    So the only route to green was rewording the description until
    ``_RELATION_DESC_RE`` stopped matching — degrading documentation to dodge a
    regex, the one thing a gate must never reward.

    The marker is ``x-relation-schema-field: <siblingPropertyName>``, and it is
    deliberately NOT a waiver: the named sibling must EXIST on the same object
    (checked below), so declaring it is a claim the gate verifies rather than a
    string that silences a rule. Like ``x-external-register`` it suppresses ONLY
    the two ``$ref`` rules; filter placement and token validation still apply.

    Read from the property, or from its ``items`` for the array form — the same
    two places ``_ref_of`` looks for a ``$ref``.
    """
    if not isinstance(prop, dict):
        return ""
    val = str(prop.get("x-relation-schema-field") or "").strip()
    if val:
        return val
    items = prop.get("items")
    if isinstance(items, dict):
        return str(items.get("x-relation-schema-field") or "").strip()
    return ""


def _ref_of(prop):
    """Return (raw_ref_value, is_array) for a relation property, or (None,_).

    An EMPTY-STRING $ref is treated as "no ref": OpenRegister's schema editor
    writes `"$ref": ""` boilerplate on every property regardless of type
    (observed fleet-wide on softwarecatalog 2026-07-08) — flagging those as
    dangling produced 61 false positives on plain scalar/enum fields.
    """
    if not isinstance(prop, dict):
        return None, False
    if "$ref" in prop and prop["$ref"] not in ("", None):
        return prop["$ref"], False
    items = prop.get("items")
    if isinstance(items, dict) and items.get("$ref") not in ("", None) and "$ref" in items:
        return items["$ref"], True
    return None, False


def _is_relation_prop(prop):
    if not isinstance(prop, dict):
        return False
    if "$ref" in prop:
        return True
    if "x-openregister-relation" in prop:
        return True
    items = prop.get("items")
    if isinstance(items, dict) and "$ref" in items:
        return True
    return False


def _has_uuid_format(prop):
    if prop.get("format") == "uuid":
        return True
    items = prop.get("items")
    if isinstance(items, dict) and items.get("format") == "uuid":
        return True
    return False


def _resolve_ref(ref, keys):
    """Return ('ok'|'warn'|'fail', normalized). Numeric → warn (live-schema
    form). Hash form resolves to its last path segment."""
    if isinstance(ref, (int, float)):
        return "warn", str(ref)
    if not isinstance(ref, str):
        return "fail", repr(ref)
    r = ref.strip()
    if r.lstrip("-").isdigit():
        return "warn", r
    if r.startswith("#"):
        r = r.rstrip("/").rsplit("/", 1)[-1]
    return ("ok", r) if r in keys else ("fail", r)


# Depth cap for the recursive property collector. Register documents in the
# fleet nest two levels at most; 12 is far above anything real and exists only
# so a hand-authored or generated pathological document cannot blow the Python
# recursion limit and turn a gate into a crash (a crashed gate reports nothing,
# which reads exactly like a pass).
_MAX_PROPERTY_DEPTH = 12


def _collect_properties(props, prefix, depth, seen, out, parents=None):
    """Recursively collect every schema property into ``out``.

    Appends ``(qualified_name, prop_dict, declaration_line, depth)`` for each
    property found under ``props``, then descends into:

      * ``prop.items.properties.*``  — an array of objects (``items`` may also
        be a LIST for tuple validation; both forms are walked);
      * ``prop.properties.*``        — an inline object property.

    ``items`` itself is deliberately NOT appended: it is a subschema, not a
    property, so ``x-relation-filter`` riding on it stays a rule-6 violation.

    ``seen`` holds ``id()`` of every dict already visited. A JSON document
    parsed from a file is a tree and cannot contain a cycle, but this collector
    is also called on dicts assembled in tests and by future callers, so the
    guard is unconditional; ``_MAX_PROPERTY_DEPTH`` bounds depth as well. Both
    guards terminate quietly — they are protection against a crash, not checks.

    ``parents`` is an OPTIONAL out-dict mapping ``id(prop)`` to the ``properties``
    map the property was declared in — i.e. its SIBLINGS. Only the polymorphic
    discriminator check needs it, and it is optional rather than a sixth tuple
    element so that every existing caller (and every existing test that unpacks
    the 4-tuple) keeps working unchanged.
    """
    if not isinstance(props, dict) or depth > _MAX_PROPERTY_DEPTH:
        return
    plines = getattr(props, "key_lines", {})
    for pname, prop in props.items():
        if not isinstance(prop, dict) or pname.startswith("@"):
            continue
        if id(prop) in seen:
            continue
        seen.add(id(prop))
        qname = pname if prefix == "" else f"{prefix}.{pname}"
        out.append((qname, prop, plines.get(pname, 0), depth))
        if parents is not None:
            parents[id(prop)] = props

        items = prop.get("items")
        item_nodes = [items] if isinstance(items, dict) else (
            [i for i in items if isinstance(i, dict)] if isinstance(items, list) else []
        )
        for node in item_nodes:
            _collect_properties(
                node.get("properties"), f"{qname}.items", depth + 1, seen, out, parents
            )
        _collect_properties(
            prop.get("properties"), qname, depth + 1, seen, out, parents
        )


# --------------------------------------------------------------------------
# Per-file checks.
# --------------------------------------------------------------------------
def check_file(path, keys, findings, base_ref):
    try:
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
        doc = _Parser(text).parse()
    except (OSError, ValueError) as exc:
        findings.append((path, f"{path}: PARSE ERROR — {exc}"))
        return

    changed = _changed_lines(path, base_ref) if base_ref else None
    schemas = _schemas_of(doc)

    # Set of property dicts (by identity) that are legitimate schema
    # properties AT ANY DEPTH — used to detect misplaced x-relation-filter
    # (check c). Populated by the recursive collector: before issue #231 this
    # was a flat one-level loop while _raw_walk recursed, so every nested
    # relation was reported as misplaced and could not be represented at all.
    property_ids = set()
    # Properties already reported by check (b) as missing a $ref. Check (c)'s
    # "inert filter" message states the SAME defect and the SAME fix (add a
    # $ref), so emitting both would count one defect twice — see the
    # finding-vs-defect note in the module docstring.
    missing_ref_ids = set()

    for sname, schema in schemas.items():
        if not isinstance(schema, dict):
            continue
        props = schema.get("properties")
        if not isinstance(props, dict):
            continue
        lc = schema.get("x-openregister-lifecycle")
        lc_field = lc.get("field") if isinstance(lc, dict) else None
        lc_has_transitions = isinstance(lc, dict) and bool(lc.get("transitions"))

        collected = []
        parents = {}
        _collect_properties(props, "", 0, set(), collected, parents)
        property_ids.update(id(prop) for _q, prop, _l, _d in collected)

        for pname, prop, pline, depth in collected:
            in_diff = changed is None or pline in changed

            # A polymorphic identifier names its discriminator instead of a
            # target. The claim is VERIFIED here, not taken on trust: a
            # discriminator that is not a sibling property cannot name a schema
            # at runtime, so the marker would be decoration. Reported whether or
            # not the description happens to trip _RELATION_DESC_RE — an
            # unresolvable discriminator is wrong on its own terms.
            disc = _discriminator_of(prop)
            if disc and disc not in (parents.get(id(prop)) or {}):
                findings.append((path, (
                    f"{path}: {sname}.{pname} — x-relation-schema-field names "
                    f"'{disc}', which is not a property of the same object; a "
                    f"discriminator that does not exist cannot name a target "
                    f"schema (ADR-062 rule 7)"
                )))
                # Deliberately NOT falling through to (b). One defect, one fix
                # (name a real discriminator) — emitting "lacks canonical $ref"
                # as well would count it twice, which is the exact ratio defect
                # the module docstring pins for gate 53.

            # (b) relation-shape heuristic — property-level diff scoped.
            # Two identifiers are exempt because no local schema key can express
            # them: a cross-app reference (see _is_external_ref) and a
            # polymorphic one whose target varies per row (_discriminator_of).
            if (in_diff and _has_uuid_format(prop) and not _is_relation_prop(prop)
                    and not _is_external_ref(prop) and not disc):
                desc = prop.get("description") or ""
                items = prop.get("items")
                if isinstance(items, dict):
                    desc = f"{desc} {items.get('description') or ''}"
                if _RELATION_DESC_RE.search(desc):
                    missing_ref_ids.add(id(prop))
                    findings.append((path, (
                        f"{path}: {sname}.{pname} — relation-shaped property "
                        f"(format:uuid + relation description) lacks canonical "
                        f"$ref (ADR-062 rule 7)"
                    )))

            # (d) filter tokens — validate every @-prefixed value.
            filt = prop.get("x-relation-filter")
            if isinstance(filt, dict):
                for fk, token in filt.items():
                    if not isinstance(token, str) or not token.startswith("@"):
                        continue
                    if token == "@objectId":
                        continue
                    if token.startswith("@object."):
                        field = token[len("@object."):]
                        if "." in field:
                            findings.append((path, (
                                f"{path}: {sname}.{pname} x-relation-filter "
                                f"[{fk}]={token} — two-hop tokens unsupported "
                                f"(ADR-062 rule 6)"
                            )))
                        elif field not in props:
                            findings.append((path, (
                                f"{path}: {sname}.{pname} x-relation-filter "
                                f"[{fk}]={token} — references nonexistent field "
                                f"'{field}' on schema {sname}"
                            )))
                    else:
                        findings.append((path, (
                            f"{path}: {sname}.{pname} x-relation-filter "
                            f"[{fk}]={token} — unknown token (expected @objectId "
                            f"or @object.<field>)"
                        )))

            # (e) frozen lifecycle — rule 10. Top-level only: a lifecycle
            # 'field' names a property of the schema itself, never an element
            # of a nested array, so a qualified name can never be the subject.
            if (
                depth == 0
                and "$ref" in prop
                and prop.get("readOnly") is True
                and lc_field == pname
                and isinstance(lc, dict)
                and not lc_has_transitions
            ):
                findings.append((path, (
                    f"{path}: {sname}.{pname} — readOnly status property with no "
                    f"expressible transitions (x-openregister-lifecycle has no "
                    f"'transitions' block) = permanently frozen (ADR-062 rule 10) "
                    f"— drop readOnly and scope an editable picker with "
                    f"x-relation-filter instead"
                )))

            # (f) $ref target resolution.
            ref, _is_arr = _ref_of(prop)
            if ref is not None and _is_external_ref(prop):
                # Still a defect — but the actionable fix is the opposite of the
                # generic message. OpenRegister cannot resolve a schema in
                # another app's register, so the $ref is dead weight and the
                # bare identifier is the correct dialect.
                findings.append((path, (
                    f"{path}: {sname}.{pname} — carries x-external-register "
                    f"'{prop.get('x-external-register')}' AND $ref '{ref}'; "
                    f"OpenRegister resolves $ref within one register set and "
                    f"cannot reach another app's schema — drop the $ref and keep "
                    f"the bare identifier (ADR-062 rule 7)"
                )))
            elif ref is not None and _discriminator_of(prop):
                # Same shape, opposite direction: the register declares that the
                # target is chosen per row AND pins one target. One of the two
                # is false, and the $ref is the one OpenRegister would act on.
                findings.append((path, (
                    f"{path}: {sname}.{pname} — carries x-relation-schema-field "
                    f"'{_discriminator_of(prop)}' AND $ref '{ref}'; a target "
                    f"chosen per row cannot also be fixed at author time — drop "
                    f"the $ref and keep the bare identifier (ADR-062 rule 7)"
                )))
            elif ref is not None:
                verdict, norm = _resolve_ref(ref, keys)
                if verdict == "fail":
                    findings.append((path, (
                        f"{path}: {sname}.{pname} — $ref '{ref}' does not resolve "
                        f"to a schema key in the register set (case-exact)"
                    )))
                elif verdict == "warn":
                    findings.append((path, "WARN:" + (
                        f"{path}: {sname}.{pname} — numeric $ref '{ref}' "
                        f"(live-schema id form); registers should author the "
                        f"schema slug"
                    )))

    # (a) banned dialect + (c) misplaced/inert x-relation-filter — raw walk.
    _raw_walk(doc, path, property_ids, findings, missing_ref_ids)


def _raw_walk(node, path, property_ids, findings, missing_ref_ids=frozenset(), loc=""):
    """Walk every node of the document for checks (a) and (c).

    ``loc`` is a slash-joined JSON pointer to ``node``. Both findings used to
    name only the FILE, which in a 2000-line register left the reader with no
    way to tell WHICH node was flagged — three identical lines, three different
    nodes (that ambiguity is exactly what made issue #231 hard to triage).
    """
    if isinstance(node, dict):
        where = f" at {loc}" if loc else ""
        if "x-openregister-relations" in node:
            findings.append((path, (
                f"{path}: banned dialect — 'x-openregister-relations' block"
                f"{where} (canonical dialect is a property-level $ref; the "
                f"bespoke per-schema block was retired 2026-07-08, "
                f"ADR-062 rule 7)"
            )))
        if "x-relation-filter" in node:
            if id(node) not in property_ids:
                findings.append((path, (
                    f"{path}: x-relation-filter{where} is placed off a property "
                    f"(inside items / an x-* block / non-property node) — it "
                    f"rides only on the relation property itself "
                    f"(ADR-062 rule 6)"
                )))
            elif not (
                ("$ref" in node)
                or ("x-openregister-relation" in node)
                # Array relation form (ADR-062 rule 7): the $ref sits on
                # items while the filter rides the property — both valid.
                or (isinstance(node.get("items"), dict) and (
                    "$ref" in node["items"]
                    or "x-openregister-relation" in node["items"]
                ))
            ) and id(node) not in missing_ref_ids:
                findings.append((path, (
                    f"{path}: x-relation-filter{where} on a property with no "
                    f"$ref — filter on a non-relation is inert (ADR-062 rule 6)"
                )))
        for k, v in node.items():
            _raw_walk(v, path, property_ids, findings, missing_ref_ids, f"{loc}/{k}")
    elif isinstance(node, list):
        for i, v in enumerate(node):
            _raw_walk(v, path, property_ids, findings, missing_ref_ids, f"{loc}/{i}")


def main(argv):
    if len(argv) < 3:
        return 0
    log_path = argv[1]
    paths = argv[2:]
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "").strip()
    keys = _global_schema_keys(paths)
    findings = []
    for p in paths:
        check_file(p, keys, findings, base_ref)
    with open(log_path, "a", encoding="utf-8") as g:
        for _p, msg in findings:
            g.write(msg + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
