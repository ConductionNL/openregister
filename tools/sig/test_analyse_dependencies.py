#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for tools/sig/analyse_dependencies.py.

    python3 -m unittest discover -s tools/sig -p 'test_*.py' -v

The analysis runs on a small lib/ written into a temporary directory, so every count below can be
checked by hand: a controller calls a service, the service calls a mapper, the mapper calls back
into the service (one upward reference and one cycle of two files), and a listener in another
area calls the same service.
"""
import os
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import analyse_dependencies as analysis  # noqa: E402

NS = "OCA\\OpenRegister"
FILES = {
    "lib/Controller/FlowController.php": f"namespace {NS}\\Controller;\nuse {NS}\\Service\\FlowService;\nclass FlowController {{ public function __construct(FlowService $s) {{}} }}\n",
    "lib/Service/FlowService.php": f"namespace {NS}\\Service;\nuse {NS}\\Db\\FlowMapper;\nclass FlowService {{ public function __construct(FlowMapper $m) {{}} }}\n",
    "lib/Db/FlowMapper.php": f"namespace {NS}\\Db;\nuse {NS}\\Service\\FlowService;\nclass FlowMapper {{ public function x(FlowService $s) {{}} }}\n",
    "lib/Listener/MailListener.php": f"namespace {NS}\\Listener;\nuse {NS}\\Service\\FlowService;\nclass MailListener {{ public function __construct(FlowService $s) {{}} }}\n",
    "lib/Migration/Version1.php": f"namespace {NS}\\Migration;\nuse {NS}\\Db\\FlowMapper;\nclass Version1 {{ public function x(FlowMapper $m) {{}} }}\n",
}
COMPONENTS = """\
components:
  - name: Flows
    include:
      - ".*/lib/(Controller|Service|Db)/Flow[^/]*[.]php"
  - name: Mail
    include:
      - ".*/lib/Listener/Mail[^/]*[.]php"
"""


class AnalysisTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.app = self.tmp.name
        for rel, src in FILES.items():
            os.makedirs(os.path.dirname(os.path.join(self.app, rel)), exist_ok=True)
            with open(os.path.join(self.app, rel), "w", encoding="utf-8") as fh:
                fh.write("<?php\n" + src)
        self.components = os.path.join(self.app, "components.yaml")
        with open(self.components, "w", encoding="utf-8") as fh:
            fh.write(COMPONENTS)
        self.result, self.pairs = analysis.analyse(self.app, self.components, "test")

    def tearDown(self):
        os.environ.pop("SIG_COMPONENTS_YAML", None)
        self.tmp.cleanup()

    def test_migrations_are_left_out(self):
        self.assertEqual(self.result["modules"], 4)

    def test_roles_follow_the_first_layer_directory(self):
        self.assertEqual(analysis.role_of("lib/Files/Controller/X.php"), ("entry point", 3))
        self.assertEqual(analysis.role_of("lib/Search/Db/View.php"), ("persistence", 1))
        self.assertIsNone(analysis.role_of("lib/AppInfo/Application.php"))
        self.assertIsNone(analysis.role_of("lib/Mcp/Tool/X.php"))

    def test_layering_counts_the_mapper_calling_back_as_upward(self):
        counts = self.result["layering"]["counts"]
        self.assertEqual(counts, {"downward": 3, "upward": 1})
        self.assertEqual(self.result["layering"]["upward"],
                         {"persistence -> service": ["lib/Db/FlowMapper.php -> lib/Service/FlowService.php"]})

    def test_the_cycle_is_the_service_and_its_mapper(self):
        cyc = self.result["cycles"]
        self.assertEqual(cyc["cyclic_sets"], 1)
        self.assertEqual(cyc["largest"]["members"], ["lib/Db/FlowMapper.php", "lib/Service/FlowService.php"])
        self.assertEqual(cyc["largest"]["upward_edges_inside"], ["lib/Db/FlowMapper.php -> lib/Service/FlowService.php"])

    def test_directory_view_counts_layer_calls_between_components(self):
        view = self.result["views"]["directories under lib/"]
        self.assertEqual(view["components"], 4)
        # Controller -> Service, Service -> Db, Db -> Service, Listener -> Service. Service and Db
        # reference each other with equal weight, so both lines of that pair count as cyclic.
        self.assertEqual(view["communication_lines"], 4)
        self.assertEqual(view["violations"], {"cyclic": 2})

    def test_functional_view_keeps_controller_service_and_mapper_together(self):
        view = self.result["views"]["functional areas"]
        self.assertEqual(view["components"], 2)
        self.assertEqual(view["communication_lines"], 1)
        self.assertEqual(view["violation_degree"], 0.0)
        self.assertEqual(dict(self.pairs), {("Mail", "Flows"): 1})
        self.assertEqual(self.result["two_way_pairs"], [])

    def test_unmatched_file_is_placed_by_directory_majority(self):
        area, placed = analysis.functional_components(
            ["lib/Service/FlowService.php", "lib/Service/FlowOther.php", "lib/Service/Unknown.php"], self.components)
        self.assertEqual(placed, ["lib/Service/Unknown.php"])
        self.assertEqual(area["lib/Service/Unknown.php"], "Flows")

    def test_strongly_connected_finds_a_three_cycle(self):
        deps = {"a": {"b"}, "b": {"c"}, "c": {"a"}, "d": {"a"}}
        groups = analysis.strongly_connected(deps)
        self.assertEqual(groups[0], {"a", "b", "c"})
        self.assertEqual(len(groups), 2)


if __name__ == "__main__":
    unittest.main()
