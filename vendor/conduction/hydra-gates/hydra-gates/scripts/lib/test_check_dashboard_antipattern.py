#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_dashboard_antipattern. Run with:

    python3 scripts/lib/test_check_dashboard_antipattern.py
"""
from __future__ import annotations

import io
import json
import os
import shutil
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_dashboard_antipattern as cda  # noqa: E402


def _write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


class CustomWidgetIdsTest(unittest.TestCase):
    def test_empty_manifest(self):
        self.assertEqual(cda._custom_widget_ids({}), set())

    def test_dashboard_with_custom_widgets(self):
        m = {
            "pages": [
                {
                    "id": "Dashboard",
                    "type": "dashboard",
                    "config": {
                        "widgets": [
                            {"id": "my-work", "type": "custom"},
                            {"id": "kpis", "type": "custom"},
                            {"id": "feed", "type": "CnIndexPage"},
                        ],
                    },
                },
                {
                    "id": "Other",
                    "type": "index",
                    "config": {
                        "widgets": [{"id": "ignored", "type": "custom"}],
                    },
                },
            ],
        }
        self.assertEqual(cda._custom_widget_ids(m), {"my-work", "kpis"})


class SliceSlotBodyTest(unittest.TestCase):
    def test_nested_template_balanced(self):
        text = (
            '<template #widget-foo>'
            '<div><template v-for="x in xs"><span>{{ x }}</span></template></div>'
            '</template>'
        )
        # Position the cursor at the start of the slot body (after the
        # opening tag).
        start = text.index('<div>')
        end, body = cda._slice_slot_body(text, start)
        self.assertIn('<span>', body)
        # End must be at or after the matching </template>.
        self.assertIn('</template>', text[start:end])


class CheckFileTest(unittest.TestCase):
    def setUp(self):
        self.dir = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def test_flags_dashboard_in_widget_slot(self):
        vue = self.dir / "MyDashboard.vue"
        _write(vue, """
<template>
  <CnDashboardPage :widgets="widgets" :layout="layout">
    <template #widget-my-work="{ item }">
      <CnDashboardPage :widgets="subWidgets" :layout="subLayout" />
    </template>
  </CnDashboardPage>
</template>
""")
        findings: list[str] = []
        cda.check_file(vue, {"my-work"}, findings)
        self.assertEqual(len(findings), 1)
        self.assertIn("widget=my-work", findings[0])
        self.assertIn("rule=dashboard-in-dashboard-slot", findings[0])

    def test_clean_slot_passes(self):
        vue = self.dir / "MyDashboard.vue"
        _write(vue, """
<template>
  <CnDashboardPage>
    <template #widget-my-work>
      <CnStatsBlock :value="42" />
    </template>
  </CnDashboardPage>
</template>
""")
        findings: list[str] = []
        cda.check_file(vue, {"my-work"}, findings)
        self.assertEqual(findings, [])

    def test_v_slot_long_form_also_matches(self):
        vue = self.dir / "MyDashboard.vue"
        _write(vue, """
<template>
  <CnDashboardPage>
    <template v-slot:widget-my-work="{ item }">
      <CnDashboardPage />
    </template>
  </CnDashboardPage>
</template>
""")
        findings: list[str] = []
        cda.check_file(vue, {"my-work"}, findings)
        self.assertEqual(len(findings), 1)

    def test_extra_attributes_on_the_slot_tag_do_not_hide_it(self):
        # .github#271/#274 — the same defect class as gate-44's "judged on the
        # FIRST of name/id/v-model and stopped" (#272): the opening-tag pattern
        # assumed the slot binding was the LAST thing before `>`, so ONE extra
        # attribute made the whole element invisible and the nested dashboard
        # inside it went unreported.
        #
        # Each of these was a silent miss before the fix.
        for markup in (
            '<template #widget-my-work class="wide">',
            '<template v-slot:widget-my-work="{ item }" class="wide">',
            "<template #widget-my-work='{ item }'>",
            '<template #widget-my-work="{ item }" :data-x="a > b">',
        ):
            with self.subTest(markup=markup):
                vue = self.dir / "MyDashboard.vue"
                _write(vue, f"""
<template>
  <CnDashboardPage>
    {markup}
      <CnDashboardPage />
    </template>
  </CnDashboardPage>
</template>
""")
                findings: list[str] = []
                cda.check_file(vue, {"my-work"}, findings)
                self.assertEqual(
                    len(findings), 1,
                    f"nested dashboard hidden by the extra attribute in: {markup}",
                )

    def test_unknown_widget_id_is_ignored(self):
        # If the slot template targets a widget id not declared as
        # type=custom in any dashboard page, we don't care about its body.
        # (It might be a non-dashboard usage of the same convention.)
        vue = self.dir / "MyDashboard.vue"
        _write(vue, """
<template>
  <CnDashboardPage>
    <template #widget-untracked>
      <CnDashboardPage />
    </template>
  </CnDashboardPage>
</template>
""")
        findings: list[str] = []
        cda.check_file(vue, {"only-this-one"}, findings)
        self.assertEqual(findings, [])


class FullRunTest(unittest.TestCase):
    """End-to-end: build a small app dir and run main() against it."""

    def setUp(self):
        self.dir = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def _write_manifest(self, manifest: dict) -> None:
        _write(self.dir / "src" / "manifest.json", json.dumps(manifest))

    def test_clean_app(self):
        self._write_manifest({
            "pages": [
                {
                    "id": "Dashboard",
                    "type": "dashboard",
                    "config": {
                        "widgets": [{"id": "kpis", "type": "custom"}],
                    },
                },
            ],
        })
        _write(self.dir / "src" / "views" / "MainDashboard.vue", """
<template>
  <CnDashboardPage>
    <template #widget-kpis><div>KPIs</div></template>
  </CnDashboardPage>
</template>
""")
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cda.main(["check_dashboard_antipattern.py", str(self.dir)])
        self.assertEqual(rc, 0)
        # The ONLY line on a clean run is the terminal marker (.github#271).
        # The marker exists because gate-15 decided its verdict with `wc -l`
        # over this stdout after `2>/dev/null || true`: a helper that crashed
        # wrote nothing, so the count was 0, so the gate said PASS over a check
        # that never ran. Its presence is how the runner tells "clean" from
        # "did not execute", so a clean run MUST still emit it.
        self.assertEqual(buf.getvalue(), "# count=0\n")

    def test_custom_page_with_dashboard_not_referenced_as_widget_passes(self):
        # Regression (pipelinq Rapportage, 2026-06-12): a type=custom page
        # whose component renders <CnDashboardPage> but is NEVER referenced
        # as a widget body elsewhere must NOT be flagged. The page's own
        # `component` field is a definition, not a widget reference.
        self._write_manifest({
            "pages": [
                {
                    "id": "Rapportage",
                    "type": "custom",
                    "component": "RapportageView",
                    "route": "/rapportage",
                },
            ],
        })
        _write(self.dir / "src" / "views" / "RapportageView.vue", """
<template>
  <CnDashboardPage :widgets="widgets" />
</template>
""")
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cda.main(["check_dashboard_antipattern.py", str(self.dir)])
        self.assertEqual(rc, 0)
        self.assertEqual(buf.getvalue(), "# count=0\n")

    def test_pipelinq_style_antipattern(self):
        # Pipelinq pattern: a custom page named "Dashboard" with component
        # DashboardView; DashboardView.vue uses CnDashboardPage; and the
        # page id is also referenced as a widget elsewhere in the manifest.
        self._write_manifest({
            "pages": [
                {
                    "id": "Dashboard",
                    "type": "custom",
                    "component": "DashboardView",
                    "route": "/",
                },
                {
                    "id": "SuperDash",
                    "type": "dashboard",
                    "config": {
                        "widgets": [
                            # custom widget surfacing the inner dashboard
                            # via the manifest's ref convention
                            {"id": "embed", "type": "custom",
                             "component": "DashboardView"},
                        ],
                    },
                },
            ],
        })
        _write(self.dir / "src" / "views" / "DashboardView.vue", """
<template>
  <CnDashboardPage :widgets="widgets" />
</template>
""")
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cda.main(["check_dashboard_antipattern.py", str(self.dir)])
        self.assertGreaterEqual(rc, 1)
        self.assertIn("DashboardView.vue", buf.getvalue())
        self.assertIn("rule=dashboard-component-used-as-widget", buf.getvalue())

    def test_modals_are_excluded(self):
        # Modals legitimately wrap their own page bodies; they're out of
        # scope for this gate.
        self._write_manifest({
            "pages": [
                {
                    "id": "Dashboard",
                    "type": "dashboard",
                    "config": {
                        "widgets": [{"id": "kpis", "type": "custom"}],
                    },
                },
            ],
        })
        _write(self.dir / "src" / "modals" / "MyModal.vue", """
<template>
  <NcModal>
    <template #widget-kpis>
      <CnDashboardPage />
    </template>
  </NcModal>
</template>
""")
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cda.main(["check_dashboard_antipattern.py", str(self.dir)])
        self.assertEqual(rc, 0)


if __name__ == "__main__":
    unittest.main()
