#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for tools/sig/fleet_surface.py.

    python3 -m unittest discover -s tools/sig -p 'test_*.py' -v

They pin which names count as a reference to a concrete class and how a measurement compares with
its baseline.
"""
import os
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import fleet_surface as fleet  # noqa: E402

PHP = r"""<?php
namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
// use OCA\OpenRegister\Db\Commented;
/* \OCA\OpenRegister\Db\AlsoCommented */
#[\Attribute]
class X {
    public function a(): void { $this->c->get('OCA\\OpenRegister\\Db\\SchemaMapper'); new \OCA\OpenRegister\Db\Register(); }
}
"""


class ReferencesTest(unittest.TestCase):
    def test_contract_events_and_comments_do_not_count(self):
        self.assertEqual(fleet.references(PHP), {
            "OCA\\OpenRegister\\Service\\ObjectService",
            "OCA\\OpenRegister\\Db\\SchemaMapper",
            "OCA\\OpenRegister\\Db\\Register",
        })


class MeasureTest(unittest.TestCase):
    def test_pairs_per_app_file_and_openregister_itself_is_skipped(self):
        with tempfile.TemporaryDirectory() as root:
            for app in ("pipelinq", "openregister"):
                os.makedirs(os.path.join(root, app, "lib"))
                with open(os.path.join(root, app, "lib", "X.php"), "w", encoding="utf-8") as fh:
                    fh.write(PHP)
            pairs = fleet.measure(root)
        self.assertEqual(len(pairs), 3)
        self.assertTrue(all(p.startswith("pipelinq/lib/X.php -> ") for p in pairs))


class CompareTest(unittest.TestCase):
    def test_new_fails_and_gone_is_a_notice(self):
        self.assertEqual(fleet.compare(["a"], ["a"]), ([], []))
        self.assertEqual(len(fleet.compare(["a"], ["a", "b"])[0]), 1)
        fail, note = fleet.compare(["a", "b"], ["a"])
        self.assertEqual(fail, [])
        self.assertIn("lower the baseline", note[0])


if __name__ == "__main__":
    unittest.main()
