#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for gate-28 helper check_schema_property_meta.py.

Covers the position-tracking JSON parser, the full-repo ratchet mode, and the
property-level diff-scoping (ADR-020) that keeps legacy title debt in a touched
register from blocking an unrelated PR while still catching genuinely-new
untitled properties.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_schema_property_meta as g  # noqa: E402


def _register(extra_props=None):
    """A minimal OpenAPI-style register with one titled + one untitled prop."""
    props = {
        "titledProp": {
            "type": "string",
            "title": "Titled prop",
            "description": "A property that already carries both fields.",
        },
        "legacyUntitled": {"type": "string"},
    }
    if extra_props:
        props.update(extra_props)
    return {
        "components": {
            "schemas": {
                "thing": {
                    "title": "Thing",
                    "description": "A thing.",
                    "properties": props,
                }
            }
        }
    }


class ParserTest(unittest.TestCase):
    def test_records_key_lines(self):
        text = json.dumps(_register(), indent=2, ensure_ascii=False)
        doc = g._Parser(text).parse()
        props = doc["components"]["schemas"]["thing"]["properties"]
        # Both keys present with plausible (distinct, positive) line numbers.
        self.assertIn("titledProp", props.key_lines)
        self.assertIn("legacyUntitled", props.key_lines)
        self.assertGreater(props.key_lines["legacyUntitled"], 0)
        self.assertNotEqual(
            props.key_lines["titledProp"], props.key_lines["legacyUntitled"]
        )

    def test_parser_matches_json_load(self):
        text = json.dumps(_register({"x": {"type": "number"}}), indent=2)
        self.assertEqual(g._Parser(text).parse(), json.loads(text))


class FullScanTest(unittest.TestCase):
    def test_flags_untitled_ignores_titled(self):
        with tempfile.TemporaryDirectory() as d:
            f = os.path.join(d, "thing_register.json")
            Path(f).write_text(json.dumps(_register(), indent=2))
            findings = []
            g.check_file(f, findings)
            msgs = [m for _, m in findings]
            self.assertTrue(any("legacyUntitled" in m for m in msgs))
            self.assertFalse(any("titledProp" in m for m in msgs))


class OverlayFragmentTest(unittest.TestCase):
    """An ADR-037 fragment deep-merges a flag onto a property the BASE register
    already declares — and already titles and describes.

    Real shape, from openconnector
    `lib/Settings/register.d/99-source-secrets-writeonly.json`:

        "apikey": { "writeOnly": true }

    Demanding a title there forces every fragment to restate the base's prose,
    and duplicated prose drifts: the fragment's copy goes stale the moment the
    base is edited, and neither copy is then trustworthy.

    The controls are what keep this from becoming "properties may skip their
    metadata": anything that DECLARES (a type, a shape, members) or that has
    already started DOCUMENTING (a title, a description) is still checked.
    """

    def _scan(self, props):
        with tempfile.TemporaryDirectory() as d:
            f = os.path.join(d, "fragment.json")
            doc = {"components": {"schemas": {"source": {"properties": props}}}}
            Path(f).write_text(json.dumps(doc, indent=2))
            findings = []
            g.check_file(f, findings)
            return [m for _, m in findings]

    def test_a_pure_overlay_property_is_not_flagged(self):
        self.assertEqual(self._scan({"apikey": {"writeOnly": True}}), [])

    def test_several_overlay_properties_are_not_flagged(self):
        self.assertEqual(
            self._scan({
                "apikey": {"writeOnly": True},
                "secret": {"writeOnly": True},
                "password": {"writeOnly": True},
            }),
            [],
        )

    def test_a_property_that_declares_a_type_is_still_flagged(self):
        # THE CONTROL. Add one declaring key and the exemption must not apply:
        # this is a declaration site, so it owns its own metadata.
        msgs = self._scan({"apikey": {"writeOnly": True, "type": "string"}})
        self.assertEqual(len(msgs), 1, msgs)
        self.assertIn("missing title + description", msgs[0])

    def test_a_half_documented_overlay_is_still_flagged(self):
        # THE OTHER CONTROL. A property that has started documenting is not an
        # overlay — the missing half is genuinely missing.
        msgs = self._scan({"apikey": {"writeOnly": True, "title": "API key"}})
        self.assertEqual(len(msgs), 1, msgs)
        self.assertIn("missing description", msgs[0])
        self.assertNotIn("title", msgs[0].split("missing", 1)[1])

    def test_an_overlay_with_nested_members_is_still_flagged(self):
        # A fragment that introduces structure is declaring, not overlaying.
        msgs = self._scan({
            "authenticationConfig": {
                "writeOnly": True,
                "properties": {"scheme": {"type": "string"}},
            },
        })
        self.assertTrue(any("authenticationConfig" in m for m in msgs), msgs)
        self.assertTrue(any("scheme" in m for m in msgs), msgs)


class DiffScopeTest(unittest.TestCase):
    """Property-level diff-scoping: only properties on changed lines flag."""

    def _git(self, *args):
        subprocess.run(["git", *args], cwd=self.repo, check=True,
                       capture_output=True, text=True)

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.repo = self._tmp.name
        self.reg = os.path.join(self.repo, "thing_register.json")
        self._git("init", "-q")
        self._git("config", "user.email", "t@t.tld")
        self._git("config", "user.name", "t")
        Path(self.reg).write_text(json.dumps(_register(), indent=2))
        self._git("add", ".")
        self._git("commit", "-qm", "base")

    def tearDown(self):
        self._tmp.cleanup()

    def _run_scoped(self, base):
        env = {**os.environ, "HYDRA_GATE_BASE_REF": base}
        out = subprocess.run(
            [sys.executable, g.__file__, "thing_register.json"],
            cwd=self.repo, env=env, capture_output=True, text=True,
        )
        return out.stdout.splitlines()

    def test_new_untitled_prop_is_flagged_legacy_ignored(self):
        # Add a brand-new untitled property; legacy debt stays untouched.
        doc = _register({"brandNewUntitled": {"type": "string"}})
        Path(self.reg).write_text(json.dumps(doc, indent=2))
        lines = self._run_scoped("HEAD")
        self.assertTrue(any("brandNewUntitled" in m for m in lines),
                        f"new untitled prop not flagged: {lines}")
        self.assertFalse(any("legacyUntitled" in m for m in lines),
                         f"legacy debt should be diff-scoped out: {lines}")

    def test_no_change_flags_nothing(self):
        # Touch an unrelated value line only — no property keys change.
        lines = self._run_scoped("HEAD")
        self.assertEqual(lines, [],
                         f"clean tree must flag nothing, got {lines}")


if __name__ == "__main__":
    unittest.main()
