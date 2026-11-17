#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for tools/sig/deptrac_areas.py and tools/sig/deptrac_summary.py.

    python3 -m unittest discover -s tools/sig -p 'test_*.py' -v

AreasConfigTest fails when tools/sig/deptrac/areas.yaml no longer matches sigrid.components.yaml.
SummaryTest pins how a deptrac JSON report is counted: occurrences per layer pair, and distinct
class pairs.
"""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import deptrac_areas  # noqa: E402
import deptrac_summary  # noqa: E402


class AreasConfigTest(unittest.TestCase):
    def tearDown(self):
        os.environ.pop("SIG_COMPONENTS_YAML", None)

    def test_config_is_fresh(self):
        self.assertEqual(deptrac_areas.main(["--check"]), 0, "run python3 tools/sig/deptrac_areas.py")

    def test_every_area_may_use_only_the_shared_area(self):
        text = deptrac_areas.render()
        ruleset = text.split("  ruleset:\n", 1)[1]
        self.assertIn("    Kernel: ~\n", ruleset)
        self.assertEqual(ruleset.count("      - Kernel\n"), ruleset.count(":") - 1)

    def test_patterns_drop_the_leading_wildcard_and_anchor_the_end(self):
        self.assertIn("value: 'lib/Kernel/.*$'", deptrac_areas.render())


class SummaryTest(unittest.TestCase):
    REPORT = {
        "Report": {"Violations": 3, "Allowed": 10},
        "files": {
            "/tmp/x/lib/Db/A.php": {"messages": [
                {"message": "OCA\\X\\Db\\A must not depend on OCA\\X\\Service\\S (Persistence on Service)", "line": 3},
                {"message": "OCA\\X\\Db\\A must not depend on OCA\\X\\Service\\S (Persistence on Service)", "line": 9},
            ]},
            "/tmp/x/lib/Event/E.php": {"messages": [
                {"message": "OCA\\X\\Event\\E must not depend on OCA\\X\\Db\\A (SharedType on Persistence)", "line": 4},
            ]},
        },
    }

    def test_counts_occurrences_and_class_pairs(self):
        summary = deptrac_summary.summarise(self.REPORT)
        rows = {r["layers"]: (r["occurrences"], r["class_pairs"]) for r in summary["by_layers"]}
        self.assertEqual(rows, {"Persistence -> Service": (2, 1), "SharedType -> Persistence": (1, 1)})
        self.assertEqual(summary["class_pairs"], 2)
        self.assertNotIn("/tmp/", str(summary))


if __name__ == "__main__":
    unittest.main()
