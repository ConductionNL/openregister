#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_phantom_cross_app_rpc (gate-27). Run with:

    python3 scripts/lib/test_check_phantom_cross_app_rpc.py

or via pytest:

    python3 -m pytest scripts/lib/test_check_phantom_cross_app_rpc.py
"""
from __future__ import annotations

import io
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_phantom_cross_app_rpc as pcar  # noqa: E402


def _scan(src: str) -> list[str]:
    """Write *src* to a temp .php file, scan it, return printed finding lines."""
    with tempfile.NamedTemporaryFile(
        suffix=".php", mode="w", encoding="utf-8", delete=False
    ) as fh:
        fh.write(src)
        fh_name = fh.name
    buf = io.StringIO()
    try:
        with redirect_stdout(buf):
            pcar.scan_file(fh_name)
    finally:
        os.unlink(fh_name)
    return [ln for ln in buf.getvalue().splitlines() if ln.strip()]


# ---------------------------------------------------------------------------
# Rule E — phantom FOUNDATION call (removed OR method), receiver-discriminated.
# ---------------------------------------------------------------------------

class RuleEReceiverDiscriminationTest(unittest.TestCase):
    """Rule E must fire ONLY when a denylisted method is called on an
    OpenRegister handle — never on a same-named method of another receiver.

    Regression guard for the receiver-blind false positive that flagged every
    method literally named ``publish`` across pipelinq/decidesk/shillinq/
    procest/softwarecatalog (NC Activity ``$activityManager->publish()``, an
    app's own ``$this->publish()``, an app-local ``$svc->publish()``).
    """

    def test_or_objectservice_publish_flagged(self):
        src = "<?php\n$this->objectService->publish($register, $schema, $id);\n"
        out = _scan(src)
        self.assertEqual(len(out), 1, out)
        self.assertIn("rule=phantom-foundation-call", out[0])
        self.assertIn("publish()", out[0])

    def test_or_objectentity_setpublished_flagged(self):
        src = "<?php\n$objectEntity->setPublished(new DateTime());\n"
        out = _scan(src)
        self.assertEqual(len(out), 1, out)
        self.assertIn("setPublished()", out[0])

    def test_or_object_setdepublished_flagged(self):
        src = "<?php\n$object->setDepublished($when);\n"
        out = _scan(src)
        self.assertEqual(len(out), 1, out)
        self.assertIn("setDepublished()", out[0])

    def test_or_handle_getpublished_flagged(self):
        src = "<?php\n$this->or->getPublished();\n"
        out = _scan(src)
        self.assertEqual(len(out), 1, out)
        self.assertIn("getPublished()", out[0])

    def test_nc_activity_manager_publish_not_flagged(self):
        """NC's first-party Activity stream IManager::publish — legitimate."""
        src = "<?php\n$this->activityManager->publish($event);\n"
        self.assertEqual(_scan(src), [])

    def test_app_own_publish_method_not_flagged(self):
        """An app calling its OWN private publish() helper — legitimate."""
        src = "<?php\n$this->publish('subject', 'type', [], 'lead', $id);\n"
        self.assertEqual(_scan(src), [])

    def test_app_local_publication_service_publish_not_flagged(self):
        """An app-local PublicationService->publish() — legitimate, not OR."""
        src = "<?php\n$this->publicationService->publish($type, $uuid, $user);\n"
        self.assertEqual(_scan(src), [])

    def test_app_local_notificatie_service_publish_not_flagged(self):
        src = "<?php\n$this->notificatieService->publish($payload);\n"
        self.assertEqual(_scan(src), [])

    def test_ori_publication_service_publish_not_flagged(self):
        src = "<?php\n$this->oriPublicationService->publish($votingRoundId);\n"
        self.assertEqual(_scan(src), [])

    def test_comment_line_never_flagged(self):
        src = "<?php\n// $this->objectService->publish() is removed\n"
        self.assertEqual(_scan(src), [])


# ---------------------------------------------------------------------------
# Rules A–D — the original cross-app command RPC patterns (unchanged).
# ---------------------------------------------------------------------------

class RulesABCDStillFireTest(unittest.TestCase):
    def test_getleaf_flagged(self):
        src = "<?php\n$registry->getLeaf('decidesk')->createDecision($x);\n"
        out = _scan(src)
        self.assertTrue(any("phantom-getleaf" in ln for ln in out), out)

    def test_call_fleet_appid_flagged(self):
        src = "<?php\n$registry->call('docudesk', 'createSigningRequest', []);\n"
        out = _scan(src)
        self.assertTrue(any("phantom-registry-call" in ln for ln in out), out)

    def test_call_non_fleet_string_not_flagged(self):
        src = "<?php\n$registry->call('some-external-source', 'op', []);\n"
        self.assertEqual(_scan(src), [])

    def test_integration_service_fqn_flagged(self):
        src = "<?php\nuse OCA\\OpenRegister\\Service\\IntegrationService;\n"
        out = _scan(src)
        self.assertTrue(
            any("phantom-integration-service" in ln for ln in out), out
        )

    def test_app_local_integration_service_not_flagged(self):
        src = "<?php\nuse OCA\\Shillinq\\Service\\ShillinqIntegrationService;\n"
        self.assertEqual(_scan(src), [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
