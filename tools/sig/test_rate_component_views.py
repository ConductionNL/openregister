#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for tools/sig/rate_component_views.py.

    python3 -m unittest discover -s tools/sig -p 'test_*.py' -v

They pin the two transformations the script applies before it rates: merging components into the
foundation, and dropping the foundation's references to other components.
"""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import rate_component_views as views  # noqa: E402

COMPONENTS = """components:
  - name: Kernel
    include:
      - ".*/lib/Contract/[^/]*"
  - name: Core
    include:
      - ".*/lib/Db/MagicMapper[.]php"
  - name: Processes
    include:
      - ".*/lib/Service/Flow/[^/]*"
"""


class FoundationYamlTest(unittest.TestCase):
    def test_core_is_renamed_to_the_foundation(self):
        out = views.foundation_yaml(COMPONENTS)
        self.assertEqual(out.count("- name: Kernel"), 2)
        self.assertNotIn("- name: Core", out)
        self.assertIn("- name: Processes", out)

    def test_patterns_are_kept(self):
        out = views.foundation_yaml(COMPONENTS)
        self.assertIn('".*/lib/Db/MagicMapper[.]php"', out)


class BackrefsTest(unittest.TestCase):
    def test_only_foundation_to_other_edges_are_dropped(self):
        comp = {"lib/Db/MagicMapper.php": "Kernel", "lib/Contract/X.php": "Kernel",
                "lib/Service/Flow/FlowService.php": "Processes"}.get
        deps = {
            "lib/Db/MagicMapper.php": {"lib/Contract/X.php", "lib/Service/Flow/FlowService.php"},
            "lib/Service/Flow/FlowService.php": {"lib/Db/MagicMapper.php"},
            "lib/Contract/X.php": set(),
        }
        out = views.without_foundation_backrefs(deps, comp)
        self.assertEqual(out["lib/Db/MagicMapper.php"], {"lib/Contract/X.php"})
        self.assertEqual(out["lib/Service/Flow/FlowService.php"], {"lib/Db/MagicMapper.php"})

    def test_foundation_backrefs_are_counted_per_component(self):
        comp = {"a": "Kernel", "b": "Kernel", "c": "Processes", "d": "Compliance"}.get
        deps = {"a": {"b", "c", "d"}, "b": {"c"}, "c": {"a"}, "d": set()}
        self.assertEqual(views.foundation_backrefs(deps, comp), {"Processes": 2, "Compliance": 1})

    def test_one_component_covers_lib(self):
        self.assertIn('".*/lib/.*"', views.one_component_yaml())


class KindTest(unittest.TestCase):
    def test_declarations_decide_the_kind(self):
        cases = {
            ("lib/Contract/X.php", "<?php\ninterface X {}"): "interface",
            ("lib/Db/T.php", "<?php\ntrait T {}"): "trait",
            ("lib/Exception/NopeException.php", "<?php\nclass NopeException {}"): "exception",
            ("lib/Event/DoneEvent.php", "<?php\nclass DoneEvent {}"): "event",
            ("lib/Db/Task.php", "<?php\nclass Task extends Entity {}"): "entity",
            ("lib/Db/TaskMapper.php", "<?php\nclass TaskMapper extends QBMapper {}"): "mapper",
            ("lib/Service/TaskService.php", "<?php\nclass TaskService {}"): "concrete class",
        }
        for (rel, text), kind in cases.items():
            self.assertEqual(views.kind_of(rel, text), kind, rel)


class TargetsTest(unittest.TestCase):
    def test_top_of_the_scale_needs_nothing_exposed(self):
        self.assertEqual(views.targets()["5.5"], {"exposed_share": 0.0, "violation_degree": 0.0})

    def test_half_a_star_above_four_is_the_five_star_band_limit(self):
        self.assertAlmostEqual(views.targets()["4.5"]["exposed_share"], 0.0275, places=4)
        self.assertAlmostEqual(views.targets()["4.5"]["violation_degree"], 0.0375, places=4)


if __name__ == "__main__":
    unittest.main()
