#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate 28 helper — schema-property-titles.

Every property of every schema in an OpenRegister register MUST carry a
human-friendly English ``title`` and a ``description``. The form renderer
uses ``label: prop.title || key`` (nextcloud-vue ``fieldsFromSchema``), so a
property without a ``title`` shows its raw technical key (``governanceBody``,
``closedAt``) to end users. ADR-011 (schema standards).

Reference exemplars with 100%% coverage: docudesk, softwarecatalog.

Usage:
    check_schema_property_meta.py <register.json> [<register.json> ...]

Walks ``components.schemas.*`` (OpenAPI-style register) and/or a root-level
single schema, recursing into nested object ``properties`` and array
``items.properties``. Prints one finding per offending property to stdout.
Always exits 0 — the calling gate counts the printed lines.

Diff-scoping (ADR-020): when the ``HYDRA_GATE_BASE_REF`` env var is set, the
helper self-scopes to the PR diff at the PROPERTY level — only properties
whose declaration line is ADDED or MODIFIED vs the base ref are checked, so
pre-existing legacy title debt in a touched register never blocks an
unrelated PR (titles are enforced going forward only, exactly like the
gate-16 spec-coverage helper). When the env var is unset/empty the helper
scans every property (the builder's full-repo ratchet mode).
"""

import json
import os
import re
import subprocess
import sys


# --------------------------------------------------------------------------
# Position-tracking JSON parse — needed so each property can be mapped to the
# source line of its key declaration for diff-scoping. Python's stdlib json
# discards positions, so we tokenise + recursive-descent ourselves. Returns
# ``dict`` subclasses (``_LineDict``) that carry a ``key_lines`` map of
# {key: 1-based-line} for their direct keys.
# --------------------------------------------------------------------------
class _LineDict(dict):
    """A dict that remembers the source line of each of its direct keys."""

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
    """Yield (kind, value, line) tokens. Newlines only occur in ws (JSON
    string escapes \\n rather than embedding a literal newline), so we track
    the line by counting newlines in every consumed chunk."""
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
    """Minimal recursive-descent JSON parser preserving key line numbers."""

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
        value = self._parse_value()
        return value

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
        self._next()  # consume '{'
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
        self._next()  # consume '['
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
# Diff-scope helpers.
# --------------------------------------------------------------------------
def _changed_lines(file_path, base_ref):
    """Return the set of NEW-side line numbers changed vs base_ref for one
    file, parsed from ``git diff -U0``. Returns None to signal "scan all"
    (e.g. git unavailable / base bad / file untracked) — fail toward
    enforcement rather than silently disabling the gate."""
    try:
        proc = subprocess.run(
            ["git", "diff", "-U0", "--no-color", base_ref, "--", file_path],
            capture_output=True,
            text=True,
            check=False,
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
        # @@ -a,b +c,d @@  — c..c+d-1 are the new-side changed lines.
        m = re.search(r"\+(\d+)(?:,(\d+))?", line)
        if not m:
            continue
        start = int(m.group(1))
        count = int(m.group(2)) if m.group(2) is not None else 1
        for ln in range(start, start + count):
            changed.add(ln)
    if not saw_hunk:
        # No hunks: either identical to base (nothing to flag) or the file is
        # untracked/new. `git diff <ref> -- <path>` against an untracked file
        # yields no output AND returncode 0 — treat that as "scan all" so a
        # brand-new register is fully enforced.
        if _is_tracked_at(file_path, base_ref):
            return set()  # tracked + identical → flag nothing
        return None       # untracked/new → scan all
    return changed


def _is_tracked_at(file_path, base_ref):
    try:
        proc = subprocess.run(
            ["git", "cat-file", "-e", f"{base_ref}:{file_path}"],
            capture_output=True,
            text=True,
            check=False,
        )
        return proc.returncode == 0
    except (OSError, ValueError):
        return False


# --------------------------------------------------------------------------
# Property walk — emits (line, message) findings.
# --------------------------------------------------------------------------
def _is_schema_like(node):
    """A dict that looks like a JSON-schema object carrying properties."""
    return isinstance(node, dict) and isinstance(node.get("properties"), dict)


def _check_property(file_path, schema_name, prop_path, prop, line, findings):
    """Validate one property dict and recurse into nested structures."""
    if not isinstance(prop, dict):
        return

    # Skip meta keys and pure-$ref properties (no place for title/description).
    leaf = prop_path.split(".")[-1].rstrip("[]")
    if leaf.startswith("@"):
        return
    if set(prop.keys()) <= {"$ref"}:
        return

    # An ADR-037 overlay fragment property — same reasoning as the $ref case
    # above, one step further.
    #
    # `lib/Settings/register.d/99-source-secrets-writeonly.json` exists to
    # deep-merge ONE flag onto a property the base register already declares:
    #
    #     "apikey": { "writeOnly": true }
    #
    # It declares no type, no shape, no members — the base register owns all
    # of that, including the title and description. Demanding metadata here
    # forces every fragment to restate the base's prose, and duplicated prose
    # drifts: the fragment's copy goes stale the moment the base is edited,
    # and neither copy is then trustworthy.
    #
    # The test is structural, not path-based: a property is an overlay when it
    # DECLARES nothing (no type/shape/members) and DOCUMENTS nothing. A
    # property carrying a title but no description still fails — it is trying
    # to declare, and half its metadata is genuinely missing.
    _DECLARING = {
        "type", "properties", "items", "enum", "format",
        "$ref", "oneOf", "anyOf", "allOf", "const",
    }
    if not (_DECLARING & set(prop.keys())) and not (
        prop.get("title") or prop.get("description")
    ):
        return

    title = prop.get("title")
    desc = prop.get("description")
    missing = []
    if not (isinstance(title, str) and title.strip()):
        missing.append("title")
    if not (isinstance(desc, str) and desc.strip()):
        missing.append("description")
    if missing:
        findings.append(
            (
                line,
                f"{file_path}: {schema_name}.{prop_path} — missing "
                f"{' + '.join(missing)}",
            )
        )

    # Recurse: nested object properties.
    nested = prop.get("properties")
    if isinstance(nested, dict):
        nlines = getattr(nested, "key_lines", {})
        for k, v in nested.items():
            if k.startswith("@"):
                continue
            _check_property(
                file_path, schema_name, f"{prop_path}.{k}", v,
                nlines.get(k, line), findings,
            )

    # Recurse: array item properties.
    items = prop.get("items")
    if isinstance(items, dict) and isinstance(items.get("properties"), dict):
        iprops = items["properties"]
        ilines = getattr(iprops, "key_lines", {})
        for k, v in iprops.items():
            if k.startswith("@"):
                continue
            _check_property(
                file_path, schema_name, f"{prop_path}[].{k}", v,
                ilines.get(k, line), findings,
            )


def _check_schema(file_path, schema_name, schema, findings):
    props = schema.get("properties")
    if not isinstance(props, dict):
        return
    plines = getattr(props, "key_lines", {})
    for key, prop in props.items():
        if key in ("@self", "id") or key.startswith("@"):
            continue
        _check_property(
            file_path, schema_name, key, prop, plines.get(key, 0), findings
        )


def check_file(file_path, findings):
    try:
        with open(file_path, "r", encoding="utf-8") as fh:
            text = fh.read()
        doc = _Parser(text).parse()
    except (OSError, ValueError) as exc:
        findings.append((0, f"{file_path}: PARSE ERROR — {exc}"))
        return

    if not isinstance(doc, dict):
        return

    schemas = (doc.get("components") or {}).get("schemas")
    if isinstance(schemas, dict) and schemas:
        for name, schema in schemas.items():
            if _is_schema_like(schema):
                _check_schema(file_path, name, schema, findings)
        return

    # Root-level single-schema register fragment.
    if _is_schema_like(doc):
        name = doc.get("title") or doc.get("slug") or file_path.rsplit("/", 1)[-1]
        _check_schema(file_path, name, doc, findings)


def main(argv):
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "").strip()
    emitted = 0
    for path in argv[1:]:
        file_findings = []
        check_file(path, file_findings)
        if base_ref:
            changed = _changed_lines(path, base_ref)
            if changed is not None:
                file_findings = [
                    (ln, msg) for (ln, msg) in file_findings if ln in changed
                ]
        for _, msg in file_findings:
            print(msg)
            emitted += 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
