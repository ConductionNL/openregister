#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for tools/sig/modularity_check.py.

    python3 -m unittest discover -s tools/sig -p 'test_*.py' -v

They pin the rank rule, the exposure count and every way the ratchet compares a measurement with
its baseline.
"""
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import modularity_check as check  # noqa: E402

ORDER = ["Kernel", "Core", "Processes"]
AREA = {"k.php": "Kernel", "c.php": "Core", "p.php": "Processes", "p2.php": "Processes"}.get


def base(**over):
    b = {"exposed_loc": {"Kernel": 10, "Core": 20, "Processes": 0}, "kernel_loc": 10,
         "violation_degree": 0.1, "against_order": ["c.php -> p.php"]}
    b.update(over)
    return b


class RankTest(unittest.TestCase):
    def test_only_references_to_a_higher_area_are_against_the_order(self):
        deps = {"c.php": {"k.php", "p.php"}, "p.php": {"c.php", "k.php", "p2.php"}, "k.php": set(), "p2.php": set()}
        self.assertEqual(check.against_order(deps, AREA, ORDER), ["c.php -> p.php"])

    def test_the_order_file_lists_every_area_once(self):
        order = check.read_order()
        self.assertEqual(len(order), len(set(order)))
        self.assertEqual(order[0], "Kernel")


class ExposureTest(unittest.TestCase):
    def test_a_file_referenced_from_another_area_is_exposed(self):
        deps = {"c.php": {"k.php"}, "k.php": set(), "p.php": {"p2.php"}, "p2.php": set()}
        loc = {"c.php": 5, "k.php": 3, "p.php": 7, "p2.php": 2}
        exposed, size = check.exposure(deps, AREA, loc)
        self.assertEqual(exposed, {"Kernel": 3})
        self.assertEqual(size["Processes"], 9)


class CompareTest(unittest.TestCase):
    def test_equal_numbers_pass(self):
        self.assertEqual(check.compare(base(), base()), [])

    def test_a_rise_fails(self):
        now = base(exposed_loc={"Kernel": 10, "Core": 21, "Processes": 0})
        self.assertIn("exposed lines of Core rose from 20 to 21", check.compare(base(), now))

    def test_a_drop_asks_for_a_lower_baseline(self):
        now = base(exposed_loc={"Kernel": 10, "Core": 19, "Processes": 0})
        self.assertTrue(any("lower the baseline" in m for m in check.compare(base(), now)))

    def test_a_new_reference_against_the_order_fails(self):
        now = base(against_order=["c.php -> p.php", "k.php -> c.php"])
        self.assertIn("new reference against the area order: k.php -> c.php", check.compare(base(), now))

    def test_a_removed_reference_asks_for_a_lower_baseline(self):
        self.assertTrue(any("are gone" in m for m in check.compare(base(), base(against_order=[]))))

    def test_kernel_growth_fails(self):
        self.assertIn("Kernel grew from 10 to 11 lines", check.compare(base(), base(kernel_loc=11)))

    def test_degree_within_tolerance_passes(self):
        self.assertEqual(check.compare(base(), base(violation_degree=0.10004)), [])


if __name__ == "__main__":
    unittest.main()
