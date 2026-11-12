#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for the pattern-components reading of tools/sig/check_sig_maintainability.py (ADR-012 Rule 1).

    python3 -m unittest discover -s tools/sig -p 'test_*.py' -v

MatchingRulesTest pins the rules Sigrid applies to a `components:` block, on a block written here:
the full path is matched with a leading slash, exclude wins over include, the first matching
component owns a file, and an unmatched file lands in Remainder.

DefinitionAgreementTest reads the repository's own block (SIG_COMPONENTS_YAML, else
sigrid.components.yaml, else a `components:` block in sigrid.yaml; skipped when there is none) and
asserts what tests/Unit/Architecture/ComponentDefinitionTest asserts, but through the gate's
reader: every PHP file under lib/ that Sigrid measures matches exactly one component, and that
component is the one the tool assigns. A file the definition test and the tool would place
differently, or that the tool leaves in Remainder, fails here.
"""
import os
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
import check_sig_maintainability as tool  # noqa: E402

BLOCK = """\
# a comment before the block
component_base_dirs: ["lib"]
components:
  - name: Kernel
    include:
      - ".*/lib/Kernel/.*"
      - ".*/lib/Db/(ObjectEntity|Schema)[.]php"
  - name: Core
    include:
      - ".*/lib/Db/[^/]*"
      - ".*/lib/Service/Object/.*"
    exclude:
      - ".*/lib/Service/Object/Export/.*"
  - name: Configuration
    include:
      - '.*/lib/Service/.*'
exclude:
  - ".*/lib/Migration/.*"
"""


def block_file(text):
    fh = tempfile.NamedTemporaryFile("w", suffix=".yaml", delete=False, encoding="utf-8")
    fh.write(text)
    fh.close()
    return fh.name


class MatchingRulesTest(unittest.TestCase):
    def setUp(self):
        self.path = block_file(BLOCK)
        self.old = os.environ.get("SIG_COMPONENTS_YAML")
        os.environ["SIG_COMPONENTS_YAML"] = self.path
        self.comps = tool.pattern_components(APP)
        self.component = tool.pattern_component_of_factory(self.comps)

    def tearDown(self):
        os.unlink(self.path)
        if self.old is None:
            os.environ.pop("SIG_COMPONENTS_YAML", None)
        else:
            os.environ["SIG_COMPONENTS_YAML"] = self.old

    def test_parses_names_includes_and_excludes_of_the_block_only(self):
        self.assertEqual([c[0] for c in self.comps], ["Kernel", "Core", "Configuration"])
        self.assertEqual([len(c[1]) for c in self.comps], [2, 2, 1])
        self.assertEqual([len(c[2]) for c in self.comps], [0, 1, 0])

    def test_matches_the_full_path_with_a_leading_slash(self):
        self.assertEqual(self.component("lib/Kernel/Contracts/AppIdentity.php"), "Kernel")
        self.assertEqual(self.component("lib/Db/Register/Thing.php"), "Remainder")

    def test_first_matching_component_owns_the_file(self):
        self.assertEqual(self.component("lib/Db/ObjectEntity.php"), "Kernel")
        self.assertEqual(self.component("lib/Db/ObjectEntityMapper.php"), "Core")
        self.assertEqual(self.component("lib/Service/Object/SaveObject.php"), "Core")

    def test_exclude_wins_and_hands_the_file_to_a_later_component(self):
        self.assertEqual(self.component("lib/Service/Object/Export/Csv.php"), "Configuration")

    def test_unmatched_file_lands_in_remainder(self):
        self.assertEqual(self.component("lib/Controller/ObjectsController.php"), "Remainder")

    def test_a_file_without_a_block_yields_no_components(self):
        path = block_file("component_base_dirs: [\"lib\"]\nexclude:\n  - \".*/tools/.*\"\n")
        try:
            os.environ["SIG_COMPONENTS_YAML"] = path
            self.assertEqual(tool.pattern_components(APP), [])
        finally:
            os.unlink(path)

    def test_component_of_factory_uses_the_block(self):
        root, component = tool.component_of_factory(["lib/Db/Schema.php", "lib/Service/X.php"], APP)
        self.assertEqual(component("lib/Db/Schema.php"), "Kernel")
        self.assertEqual(component("lib/Service/X.php"), "Configuration")


def repository_block():
    """The block the repository defines, as the definition test reads it."""
    env = os.environ.get("SIG_COMPONENTS_YAML")
    if env:
        return env
    for name in ("sigrid.components.yaml", "sigrid.yaml"):
        path = os.path.join(APP, name)
        if os.path.isfile(path):
            os.environ["SIG_COMPONENTS_YAML"] = path
            try:
                if tool.pattern_components(APP):
                    return path
            finally:
                os.environ.pop("SIG_COMPONENTS_YAML", None)
    return None


class DefinitionAgreementTest(unittest.TestCase):
    def setUp(self):
        self.path = repository_block()
        if self.path is None:
            self.skipTest("no components: block in SIG_COMPONENTS_YAML, sigrid.components.yaml or sigrid.yaml")
        self.old = os.environ.get("SIG_COMPONENTS_YAML")
        os.environ["SIG_COMPONENTS_YAML"] = self.path
        self.comps = tool.pattern_components(APP)
        self.component = tool.pattern_component_of_factory(self.comps)
        scope = tool.Scope(APP)
        self.files = sorted(rel for rel in tool.tracked_files(APP)
                            if rel.startswith("lib/") and scope.language(rel) == "php")

    def tearDown(self):
        if self.old is None:
            os.environ.pop("SIG_COMPONENTS_YAML", None)
        else:
            os.environ["SIG_COMPONENTS_YAML"] = self.old

    def matches(self, rel):
        probe = "/" + rel
        return [name for name, inc, exc in self.comps
                if any(p.fullmatch(probe) for p in inc) and not any(p.fullmatch(probe) for p in exc)]

    def test_every_measured_lib_file_matches_exactly_one_component(self):
        self.assertTrue(self.files, "no measured PHP file under lib/")
        none = [rel for rel in self.files if not self.matches(rel)]
        several = [f"{rel} -> {', '.join(self.matches(rel))}" for rel in self.files if len(self.matches(rel)) > 1]
        self.assertEqual([], none, f"{len(none)} file(s) in no component of {self.path}")
        self.assertEqual([], several, f"{len(several)} file(s) in more than one component of {self.path}")

    def test_the_tool_assigns_the_component_the_definition_names(self):
        differ = []
        for rel in self.files:
            found = self.matches(rel)
            if len(found) == 1 and self.component(rel) != found[0]:
                differ.append(f"{rel}: definition {found[0]}, tool {self.component(rel)}")
        self.assertEqual([], differ)


if __name__ == "__main__":
    unittest.main()
