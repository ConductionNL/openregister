#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_orphaned_write_capability (gate-52). Run with:

    python3 scripts/lib/test_check_orphaned_write_capability.py

or via pytest:

    python3 -m pytest scripts/lib/test_check_orphaned_write_capability.py
"""
from __future__ import annotations

import io
import os
import re
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_orphaned_write_capability as owc  # noqa: E402


class _AppFixture:
    def __init__(self):
        self._tmp = tempfile.TemporaryDirectory()
        self._old_cwd = None

    def __enter__(self):
        self._old_cwd = os.getcwd()
        os.chdir(self._tmp.name)
        return self._tmp.name

    def __exit__(self, *exc):
        os.chdir(self._old_cwd)
        self._tmp.cleanup()

    def write(self, rel_path: str, content: str) -> str:
        full = os.path.join(self._tmp.name, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


def _run_main(*service_paths: str) -> list[str]:
    buf = io.StringIO()
    with redirect_stdout(buf):
        owc.main(["check_orphaned_write_capability.py", *service_paths])
    return [ln for ln in buf.getvalue().splitlines() if ln.strip()]


class OrphanFlaggedTest(unittest.TestCase):
    def test_zero_caller_write_method_flagged(self):
        with _AppFixture() as root:
            path = self.write(
                root,
                "lib/Service/OrphanService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass OrphanService {\n"
                "    public function postJournalEntry(array $data): void { /* posts GL, never called */ }\n"
                "}\n",
            )
            out = _run_main(path)
            self.assertEqual(len(out), 1, out)
            self.assertIn("rule=orphaned-write-capability", out[0])
            self.assertIn("method=postJournalEntry", out[0])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class DirectCallerNotFlaggedTest(unittest.TestCase):
    def test_method_with_direct_caller_not_flagged(self):
        with _AppFixture() as root:
            svc_path = self.write(
                root,
                "lib/Service/WiredService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass WiredService {\n"
                "    public function createInvoice(array $data): void {}\n"
                "}\n",
            )
            self.write(
                root,
                "lib/Controller/InvoiceController.php",
                "<?php\nnamespace OCA\\Fixture\\Controller;\nclass InvoiceController {\n"
                "    public function store(): void { $this->wiredService->createInvoice([]); }\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class RegisterHandlerSeamTest(unittest.TestCase):
    def test_method_invoked_only_via_registerd_handler_not_flagged(self):
        with _AppFixture() as root:
            self.write(
                root,
                "lib/Settings/register.d/fixture.json",
                '{"transitions": {"submit": {"handler": '
                '"OCA\\\\Fixture\\\\Service\\\\HandlerOnlyService::generateReport"}}}',
            )
            svc_path = self.write(
                root,
                "lib/Service/HandlerOnlyService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass HandlerOnlyService {\n"
                "    public function generateReport(): void { /* OR-invoked, no PHP caller */ }\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class EventListenerSeamTest(unittest.TestCase):
    def test_class_registered_as_event_listener_not_flagged(self):
        with _AppFixture() as root:
            self.write(
                root,
                "lib/AppInfo/Application.php",
                "<?php\nnamespace OCA\\Fixture\\AppInfo;\nclass Application {\n"
                "    public function register($context): void {\n"
                "        $context->registerEventListener(\n"
                "            \\OCA\\OpenRegister\\Event\\ObjectUpdatedEvent::class,\n"
                "            \\OCA\\Fixture\\Service\\NotificationDispatchService::class\n"
                "        );\n"
                "    }\n}\n",
            )
            svc_path = self.write(
                root,
                "lib/Service/NotificationDispatchService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass NotificationDispatchService {\n"
                "    public function notifyOnTransition(): void { /* invoked as event listener */ }\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class BackgroundJobSeamTest(unittest.TestCase):
    def test_class_registered_as_background_job_not_flagged(self):
        with _AppFixture() as root:
            self.write(
                root,
                "appinfo/info.xml",
                "<?xml version=\"1.0\"?><info><background-jobs>"
                "<job>OCA\\Fixture\\Service\\ExportGeneratorService</job>"
                "</background-jobs></info>",
            )
            svc_path = self.write(
                root,
                "lib/Service/ExportGeneratorService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass ExportGeneratorService {\n"
                "    public function run(): void {}\n"
                "    public function exportLedger(): void { /* invoked from run() via the job scheduler */ }\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class LogAdapterSeamTest(unittest.TestCase):
    def test_log_only_adapter_class_exempt(self):
        with _AppFixture() as root:
            svc_path = self.write(
                root,
                "lib/Service/LogCreditScoreFetchAdapter.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass LogCreditScoreFetchAdapter {\n"
                "    public function notifyCreditScoreFetch(): void { /* intentional log-only seam */ }\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class ExcludeAnnotationTest(unittest.TestCase):
    def test_docblock_exclude_annotation_suppresses(self):
        with _AppFixture() as root:
            svc_path = self.write(
                root,
                "lib/Service/PlannedService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass PlannedService {\n"
                "    /**\n"
                "     * @orphaned-write-capability exclude Wired in the next PR (issue #999)\n"
                "     */\n"
                "    public function publishReport(): void {}\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class GetterMethodsNeverFlaggedTest(unittest.TestCase):
    def test_getter_and_guard_shaped_methods_ignored(self):
        with _AppFixture() as root:
            svc_path = self.write(
                root,
                "lib/Service/ReadOnlyService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass ReadOnlyService {\n"
                "    public function getBalance(): float { return 0.0; }\n"
                "    public function isValid(): bool { return true; }\n"
                "    public function findAll(): array { return []; }\n"
                "}\n",
            )
            out = _run_main(svc_path)
            self.assertEqual(out, [])

    def write(self, root, rel_path, content):
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


class _FleetFixture:
    """A parent dir holding several sibling NC app checkouts, mirroring the
    real ``apps-extra/`` layout that gate-57 runs inside. Each app gets an
    ``appinfo/info.xml`` so ``_find_sibling_app_roots`` recognises it.

    ``cwd`` is a deliberate KNOB, not a baked-in assumption. Gate-56's
    fixtures all chdir'd into the app root, which is precisely why its
    ``os.getcwd()`` bug survived a green suite and then produced 242 false
    positives the first time it ran from a foreign cwd (hydra#108/#109). A
    fixture must not encode the same assumption the gate makes, so this one
    can also run from the sweep parent — the way the fleet sweep really
    invokes the gate. See ForeignCwdTest.
    """

    def __init__(self, app_name: str, cwd: str = "app"):
        self._tmp = tempfile.TemporaryDirectory()
        self._app_name = app_name
        self._cwd_mode = cwd
        self._old_cwd = None

    def __enter__(self):
        self.parent = self._tmp.name
        self.app_root = self.add_app(self._app_name)
        self._old_cwd = os.getcwd()
        os.chdir(self.app_root if self._cwd_mode == "app" else self.parent)
        return self

    def __exit__(self, *exc):
        os.chdir(self._old_cwd)
        self._tmp.cleanup()

    def add_app(self, name: str) -> str:
        root = os.path.join(self.parent, name)
        os.makedirs(os.path.join(root, "lib"), exist_ok=True)
        self.write(root, "appinfo/info.xml", "<?xml version='1.0'?><info></info>\n")
        return root

    def write(self, root: str, rel_path: str, content: str) -> str:
        full = os.path.join(root, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


# Models the real openregister ObjectService::clearCurrents() —
# openconnector's EndpointService is its only caller (hydra#106).
_FOUNDATION_SVC = (
    "<?php\nnamespace OCA\\OpenRegister\\Service;\nclass ObjectService {\n"
    "    public function clearCurrents(): void { /* live: resets request-scoped cache */ }\n"
    "    public function generateNeverCalledReport(): void { /* genuinely dead */ }\n"
    "}\n"
)


class CrossAppCallerTest(unittest.TestCase):
    """hydra#106 FP class 1 — a foundation repo's public method whose only
    callers live in a sibling app must NOT be reported dead."""

    def test_foundation_method_called_only_from_sibling_is_not_flagged(self):
        with _FleetFixture("openregister") as fx:
            svc = fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            consumer = fx.add_app("openconnector")
            fx.write(
                consumer,
                "lib/Service/EndpointService.php",
                "<?php\nnamespace OCA\\OpenConnector\\Service;\nclass EndpointService {\n"
                "    public function handle(): void {\n"
                "        $this->objectService->getOpenRegisters()->clearCurrents();\n"
                "    }\n"
                "}\n",
            )
            out = _run_main(svc)
            self.assertNotIn(
                "clearCurrents",
                "\n".join(out),
                "regression: live cross-app-called method reported dead (hydra#106)",
            )

    def test_genuinely_dead_foundation_method_still_flagged(self):
        """The true positive must survive the fix: no sibling calls this."""
        with _FleetFixture("openregister") as fx:
            svc = fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            consumer = fx.add_app("openconnector")
            fx.write(
                consumer,
                "lib/Service/EndpointService.php",
                "<?php\nnamespace OCA\\OpenConnector\\Service;\nclass EndpointService {\n"
                "    public function handle(): void {\n"
                "        $this->objectService->getOpenRegisters()->clearCurrents();\n"
                "    }\n"
                "}\n",
            )
            out = _run_main(svc)
            self.assertEqual(len(out), 1, out)
            self.assertIn("method=generateNeverCalledReport", out[0])

    def test_foundation_repo_without_siblings_reports_nothing(self):
        """FAIL SAFE: consumers not on disk => deadness unprovable => silent.
        A missing sibling must never turn a live method into a 'dead'
        verdict."""
        with _FleetFixture("openregister") as fx:
            svc = fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            out = _run_main(svc)
            self.assertEqual(out, [])

    def test_leaf_app_verdicts_unaffected_by_siblings(self):
        """A leaf app's services are consumed only by that app (ADR-022), so
        its zero-caller verdicts stay sound — the shillinq true positives
        must not be weakened by the cross-app fix."""
        with _FleetFixture("shillinq") as fx:
            svc = fx.write(
                fx.app_root,
                "lib/Service/DisposalJournalEmitter.php",
                "<?php\nnamespace OCA\\Shillinq\\Service;\nclass DisposalJournalEmitter {\n"
                "    public function emitDisposalJournal(): void { /* posts GL, dead */ }\n"
                "}\n",
            )
            fx.add_app("openregister")
            out = _run_main(svc)
            self.assertEqual(len(out), 1, out)
            self.assertIn("method=emitDisposalJournal", out[0])


class ForeignCwdTest(unittest.TestCase):
    """The app root must come from the FILE'S path, never the process cwd.

    Every other fixture in this file chdir's into the app root, so all of
    them would still pass if the gate read ``os.getcwd()`` — the exact blind
    spot that let gate-56 ship a cwd bug behind a green suite (hydra#108/
    #109, 242 false positives). These tests run from the sweep PARENT, the
    way the real fleet sweep invokes the gate, and pass a repo-qualified
    relative path. Under the cwd-derived behaviour ``_is_foundation`` saw
    basename ``apps-extra``, never scanned siblings, and reported the live
    ``clearCurrents`` dead all over again."""

    def _fleet(self):
        fx = _FleetFixture("openregister", cwd="parent")
        return fx

    def test_cross_app_caller_seen_when_invoked_from_sweep_parent(self):
        """MUST PASS: live foundation method, sweep run from apps-extra/."""
        with self._fleet() as fx:
            fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            consumer = fx.add_app("openconnector")
            fx.write(
                consumer,
                "lib/Service/EndpointService.php",
                "<?php\nnamespace OCA\\OpenConnector\\Service;\nclass EndpointService {\n"
                "    public function handle(): void {\n"
                "        $this->objectService->getOpenRegisters()->clearCurrents();\n"
                "    }\n"
                "}\n",
            )
            # Repo-qualified relative path, exactly as a sweep from the
            # parent passes it — cwd is the parent, NOT the app root.
            out = _run_main(os.path.join("openregister", "lib", "Service", "ObjectService.php"))
            self.assertNotIn(
                "clearCurrents",
                "\n".join(out),
                "regression: gate read app root from cwd, missed the sibling "
                "caller and named live code dead (hydra#106)",
            )

    def test_genuine_orphan_still_flagged_from_sweep_parent(self):
        """MUST STILL FAIL: the true positive survives the foreign cwd."""
        with self._fleet() as fx:
            fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            consumer = fx.add_app("openconnector")
            fx.write(
                consumer,
                "lib/Service/EndpointService.php",
                "<?php\nnamespace OCA\\OpenConnector\\Service;\nclass EndpointService {\n"
                "    public function handle(): void {\n"
                "        $this->objectService->getOpenRegisters()->clearCurrents();\n"
                "    }\n"
                "}\n",
            )
            out = _run_main(os.path.join("openregister", "lib", "Service", "ObjectService.php"))
            self.assertEqual(len(out), 1, out)
            self.assertIn("method=generateNeverCalledReport", out[0])

    def test_seams_resolve_from_foreign_cwd(self):
        """A register.d handler seam must still exempt its method when the
        gate runs from the parent: a cwd-derived app_root looked for
        <parent>/lib/Settings, found nothing, and every seam came back empty
        — turning all seam-exempt methods into false positives."""
        with _FleetFixture("pipelinq", cwd="parent") as fx:
            fx.write(
                fx.app_root,
                "lib/Service/PosGuardService.php",
                "<?php\nnamespace OCA\\Pipelinq\\Service;\nclass PosGuardService {\n"
                "    public function confirmPosTransaction(): void { /* register.d handler */ }\n"
                "}\n",
            )
            fx.write(
                fx.app_root,
                "lib/Settings/register.d/10-pos.json",
                '{"handlers":[{"handler":'
                '"OCA\\\\Pipelinq\\\\Service\\\\PosGuardService::confirmPosTransaction"}]}\n',
            )
            out = _run_main(os.path.join("pipelinq", "lib", "Service", "PosGuardService.php"))
            self.assertEqual(out, [], "seam lost from a foreign cwd")

    def test_files_from_two_apps_in_one_invocation(self):
        """A sweep hands the gate files from several repos at once; each must
        be judged against its OWN app root, seams and caller index."""
        with _FleetFixture("openregister", cwd="parent") as fx:
            fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            consumer = fx.add_app("openconnector")
            fx.write(
                consumer,
                "lib/Service/EndpointService.php",
                "<?php\nnamespace OCA\\OpenConnector\\Service;\nclass EndpointService {\n"
                "    public function handle(): void {\n"
                "        $this->objectService->getOpenRegisters()->clearCurrents();\n"
                "    }\n"
                "}\n",
            )
            leaf = fx.add_app("shillinq")
            fx.write(
                leaf,
                "lib/Service/DisposalJournalEmitter.php",
                "<?php\nnamespace OCA\\Shillinq\\Service;\nclass DisposalJournalEmitter {\n"
                "    public function emitDisposalJournal(): void { /* dead */ }\n"
                "}\n",
            )
            out = _run_main(
                os.path.join("openregister", "lib", "Service", "ObjectService.php"),
                os.path.join("shillinq", "lib", "Service", "DisposalJournalEmitter.php"),
            )
            joined = "\n".join(out)
            self.assertNotIn("clearCurrents", joined)
            self.assertIn("method=generateNeverCalledReport", joined)
            self.assertIn("method=emitDisposalJournal", joined)


class InterfaceDeclarationTest(unittest.TestCase):
    """hydra#106 FP class 2 — a bodiless declaration cannot be dead."""

    def test_interface_method_declaration_not_flagged(self):
        with _AppFixture() as root:
            svc = _write(
                root,
                "lib/Service/External/Mollie/MolliePaymentAdapterInterface.php",
                "<?php\nnamespace OCA\\Shillinq\\Service\\External\\Mollie;\n"
                "interface MolliePaymentAdapterInterface {\n"
                "    public function createPayment(array $payload): MolliePaymentResult;\n"
                "}\n",
            )
            self.assertEqual(_run_main(svc), [])

    def test_abstract_method_declaration_not_flagged(self):
        with _AppFixture() as root:
            svc = _write(
                root,
                "lib/Service/AbstractEmitter.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\n"
                "abstract class AbstractEmitter {\n"
                "    abstract public function emitThing(): void;\n"
                "    public function createThing(string $sep = ';'): void { /* dead */ }\n"
                "}\n",
            )
            out = _run_main(svc)
            self.assertEqual(len(out), 1, out)
            self.assertIn("method=createThing", out[0])

    def test_trait_method_still_scanned_for_concrete_bodies(self):
        """A trait is skipped as a type declaration, mirroring interfaces."""
        with _AppFixture() as root:
            svc = _write(
                root,
                "lib/Service/EmitterTrait.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\ntrait EmitterTrait {\n"
                "    public function emitViaTrait(): void {}\n"
                "}\n",
            )
            self.assertEqual(_run_main(svc), [])


class GitEnumerationTest(unittest.TestCase):
    """hydra#106 FP class 3 — a nested custom_apps/ must neither mask the
    repo's own files nor pollute the caller index with a vendored copy."""

    def _git(self, root, *args):
        import subprocess

        subprocess.run(
            ["git", "-C", root, *args],
            check=True,
            capture_output=True,
        )

    def test_custom_apps_sibling_does_not_supply_phantom_callers(self):
        """The sibling scan must not treat a nested Nextcloud server tree
        (`custom_apps/`, which carries a vendored copy of every app and its
        own appinfo/info.xml) as a consumer repo — a stale vendored call
        site there would suppress a genuine finding."""
        with _FleetFixture("openregister") as fx:
            svc = fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            fx.add_app("openconnector")
            vendored = fx.add_app("custom_apps")
            fx.write(
                vendored,
                "lib/Service/Vendored.php",
                "<?php\nclass Vendored {\n"
                "    public function run(): void {\n"
                "        $this->svc->generateNeverCalledReport();\n"
                "    }\n"
                "}\n",
            )
            self.assertNotIn(
                vendored,
                owc._find_sibling_app_roots(fx.app_root),
                "custom_apps/ must never be treated as a sibling app repo",
            )
            out = _run_main(svc)
            self.assertIn(
                "method=generateNeverCalledReport",
                "\n".join(out),
                "regression: vendored copy under custom_apps/ suppressed a real finding",
            )

    def test_git_ls_files_skips_untracked_vendored_tree(self):
        with _FleetFixture("openregister") as fx:
            svc = fx.write(fx.app_root, "lib/Service/ObjectService.php", _FOUNDATION_SVC)
            fx.add_app("openconnector")
            try:
                self._git(fx.app_root, "init", "-q")
                self._git(fx.app_root, "add", "-A")
            except Exception:  # pragma: no cover - git absent
                self.skipTest("git unavailable")
            # Untracked AND gitignored — must not reach the caller index.
            fx.write(fx.app_root, ".gitignore", "ignored/\n")
            fx.write(
                fx.app_root,
                "lib/ignored/Phantom.php",
                "<?php\nclass Phantom {\n"
                "    public function run(): void { $this->s->generateNeverCalledReport(); }\n"
                "}\n",
            )
            out = _run_main(svc)
            self.assertIn("method=generateNeverCalledReport", "\n".join(out))


def _write(root, rel_path, content):
    full = os.path.join(root, rel_path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w", encoding="utf-8") as fh:
        fh.write(content)
    return full


# ---------------------------------------------------------------------------
# THE ATTRIBUTE-SCANNER SEAM (#200)
#
# gate-57 reported `pipelinq LeadService::createLead` — a curated, spec'd MCP
# write tool — as an orphan. It is reached by attribute reflection: an
# `#[McpTool]` attribute, a class listed by an `IMcpScannableServices`
# implementation, and a `registerServiceAlias` binding that implementation
# under `…IMcpScannableServices::<app>`. There is no `->createLead(` call site
# anywhere and there never will be, so the caller index cannot see it. Acting
# on the verdict would have DELETED a live tool — the failure mode this
# module's docstring already records from hydra#106.
#
# Widening a checker until nothing trips it is the opposite failure. EVERY
# exemption below is paired with a control that must still be REPORTED: the
# attribute without the registration, the registration without the attribute,
# an alias naming a class that does not implement the interface, an
# implementation nobody aliases, the registration commented out, and the
# attribute merely MENTIONED in a docblock.
# ---------------------------------------------------------------------------

_MCP_INFO_XML = "<?xml version='1.0'?>\n<info><id>demoapp</id></info>\n"

_MCP_APPLICATION_TMPL = """<?php
namespace OCA\\DemoApp\\AppInfo;

use OCA\\DemoApp\\Mcp\\DemoScannableServices;

class Application {
    public function register($context): void {
%s
    }
}
"""

_MCP_ALIAS_CALL = """        $context->registerServiceAlias(
            'OCA\\\\OpenRegister\\\\Mcp\\\\IMcpScannableServices::demoapp',
            DemoScannableServices::class
        );"""

_MCP_SCANNABLE_TMPL = """<?php
namespace OCA\\DemoApp\\Mcp;

use OCA\\OpenRegister\\Mcp\\IMcpScannableServices;
use OCA\\DemoApp\\Service\\LeadService;

class DemoScannableServices implements IMcpScannableServices
{
    public function getScannableServiceClasses(): array
    {
        return [
            LeadService::class,
        ];
    }
}
"""

# `createLead` is attributed; `createInvoice` is not. Both are write-verb
# methods with zero `->method(` call sites anywhere in the fixture. The file
# header deliberately MENTIONS `#[McpTool]` in prose, exactly as pipelinq's
# real LeadService.php does on line 7.
_MCP_LEAD_SERVICE_TMPL = """<?php
/**
 * Lead service.
 *
 * Both public entry points are annotated `#[McpTool]` (OpenRegister ADR-063).
 * That sentence is PROSE and must not register anything.
 */
namespace OCA\\DemoApp\\Service;

use OCA\\OpenRegister\\Mcp\\Attribute\\McpTool;

class LeadService
{
%s
    public function createLead(array $data): array
    {
        return $data;
    }

    /**
     * No attribute on this one.
     */
    public function createInvoice(array $data): array
    {
        return $data;
    }
}
"""

_MCP_ATTRIBUTE = """    #[McpTool(
        name: 'createLead',
        description: 'Create a lead',
        readOnlyHint: false,
        scope: 'create'
    )]"""


class _McpFixture:
    """A throwaway Nextcloud app tree wired for the attribute seam."""

    def __init__(self, root: str, *, alias: str, attribute: str, scannable: bool = True):
        self.root = root
        _write(root, "appinfo/info.xml", _MCP_INFO_XML)
        _write(root, "lib/AppInfo/Application.php", _MCP_APPLICATION_TMPL % alias)
        if scannable:
            _write(root, "lib/Mcp/DemoScannableServices.php", _MCP_SCANNABLE_TMPL)
        self.service = _write(
            root, "lib/Service/LeadService.php", _MCP_LEAD_SERVICE_TMPL % attribute
        )

    def methods(self) -> set:
        out: list[str] = []
        owc._scan_app(self.root, [self.service], out)
        names = set()
        for line in out:
            for part in line.split():
                if part.startswith("method="):
                    names.add(part[len("method="):])
        return names


class McpAttributeSeamTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = os.path.join(self._tmp.name, "demoapp")
        os.makedirs(self.root)

    def tearDown(self):
        self._tmp.cleanup()

    # --- the fix: a genuinely wired tool is no longer named dead ---------
    def test_registered_and_attributed_method_is_not_flagged(self):
        f = _McpFixture(self.root, alias=_MCP_ALIAS_CALL, attribute=_MCP_ATTRIBUTE)
        self.assertNotIn("createLead", f.methods())

    # --- THE CONTROLS. Each must still be REPORTED. ----------------------
    def test_an_unattributed_write_method_on_a_scannable_class_is_still_flagged(self):
        # The seam is per METHOD, not per class. Exempting the whole class
        # would hand every write method on LeadService a free pass.
        f = _McpFixture(self.root, alias=_MCP_ALIAS_CALL, attribute=_MCP_ATTRIBUTE)
        self.assertIn("createInvoice", f.methods())

    def test_the_attribute_without_the_DI_alias_is_still_flagged(self):
        # A bare `#[McpTool]` on a class nobody registers is not a seam —
        # OpenRegister never reflects it, so the tool does not exist.
        f = _McpFixture(self.root, alias="        // nothing registered", attribute=_MCP_ATTRIBUTE)
        self.assertIn("createLead", f.methods())

    def test_the_alias_without_the_attribute_is_still_flagged(self):
        f = _McpFixture(self.root, alias=_MCP_ALIAS_CALL, attribute="")
        self.assertIn("createLead", f.methods())

    def test_a_scannable_implementation_that_does_not_exist_is_still_flagged(self):
        f = _McpFixture(
            self.root, alias=_MCP_ALIAS_CALL, attribute=_MCP_ATTRIBUTE, scannable=False
        )
        self.assertIn("createLead", f.methods())

    def test_a_class_not_listed_by_the_implementation_is_still_flagged(self):
        f = _McpFixture(self.root, alias=_MCP_ALIAS_CALL, attribute=_MCP_ATTRIBUTE)
        _write(
            self.root,
            "lib/Mcp/DemoScannableServices.php",
            _MCP_SCANNABLE_TMPL.replace("LeadService::class", "TicketService::class"),
        )
        self.assertIn("createLead", f.methods())

    def test_an_aliased_class_that_does_not_implement_the_interface_grants_nothing(self):
        # The alias names it, but OpenRegister's scanner only ever calls
        # getScannableServiceClasses() on an IMcpScannableServices. A class
        # that does not implement it is never reflected.
        f = _McpFixture(self.root, alias=_MCP_ALIAS_CALL, attribute=_MCP_ATTRIBUTE)
        _write(
            self.root,
            "lib/Mcp/DemoScannableServices.php",
            _MCP_SCANNABLE_TMPL
            .replace("use OCA\\OpenRegister\\Mcp\\IMcpScannableServices;\n", "")
            .replace(" implements IMcpScannableServices", ""),
        )
        self.assertIn("createLead", f.methods())

    def test_an_UNALIASED_implementation_grants_nothing_even_when_another_is_aliased(self):
        # THE CONTROL FOR THE ALIAS REQUIREMENT. A registration exists — so the
        # seam builder does not bail out early — but it names a DIFFERENT
        # class. Any class in lib/ that happens to list `LeadService::class`
        # must not become a seam on that basis.
        alias = _MCP_ALIAS_CALL.replace(
            "DemoScannableServices::class", "OtherScannableServices::class"
        )
        f = _McpFixture(self.root, alias=alias, attribute=_MCP_ATTRIBUTE)
        _write(
            self.root,
            "lib/Mcp/OtherScannableServices.php",
            _MCP_SCANNABLE_TMPL
            .replace("class DemoScannableServices", "class OtherScannableServices")
            .replace("LeadService::class,", ""),
        )
        self.assertIn("createLead", f.methods())

    # --- the gate-64 shape: a comment is not a registration -------------
    def test_a_COMMENTED_OUT_alias_grants_nothing(self):
        # gate-64's `has_prelude()` matched inside comments, so a
        # commented-out prelude counted as compliance. A seam matched in a
        # comment exempts code that nothing calls.
        commented = "\n".join(
            "        // " + ln.strip() for ln in _MCP_ALIAS_CALL.split("\n")
        )
        f = _McpFixture(self.root, alias=commented, attribute=_MCP_ATTRIBUTE)
        self.assertIn("createLead", f.methods())

    def test_a_BLOCK_COMMENTED_alias_grants_nothing(self):
        f = _McpFixture(
            self.root,
            alias="        /*\n" + _MCP_ALIAS_CALL + "\n        */",
            attribute=_MCP_ATTRIBUTE,
        )
        self.assertIn("createLead", f.methods())

    def test_a_docblock_that_merely_MENTIONS_the_attribute_is_not_an_attribute(self):
        f = _McpFixture(
            self.root,
            alias=_MCP_ALIAS_CALL,
            attribute="    /** Annotated with #[McpTool] elsewhere. */",
        )
        self.assertIn("createLead", f.methods())

    # --- the SAME shape in the pre-existing seams -----------------------
    def test_a_commented_out_event_listener_does_not_exempt_the_class(self):
        # The listener seam exempts a WHOLE class, so a match inside a comment
        # is a false GREEN. Latent rather than observed — a sweep of the eight
        # repos under this gate found zero comment-only matches — but it is
        # the same shape and it is now closed.
        f = _McpFixture(self.root, alias="        // nothing", attribute="")
        _write(
            self.root,
            "lib/AppInfo/Application.php",
            _MCP_APPLICATION_TMPL
            % "        // $context->registerEventListener(SomeEvent::class, LeadService::class);",
        )
        self.assertIn("createLead", f.methods())

    def test_a_LIVE_event_listener_registration_still_exempts_the_class(self):
        # THE CONTROL. Blanking comments must not have broken the seam itself.
        f = _McpFixture(self.root, alias="        // nothing", attribute="")
        _write(
            self.root,
            "lib/AppInfo/Application.php",
            _MCP_APPLICATION_TMPL
            % "        $context->registerEventListener(SomeEvent::class, LeadService::class);",
        )
        self.assertEqual(f.methods(), set())

    def test_a_commented_out_background_job_does_not_exempt_the_class(self):
        f = _McpFixture(self.root, alias="        // nothing", attribute="")
        _write(
            self.root,
            "appinfo/info.xml",
            "<?xml version='1.0'?>\n<info><id>demoapp</id><background-jobs>\n"
            "<!-- <job>OCA\\DemoApp\\Service\\LeadService</job> -->\n"
            "</background-jobs></info>\n",
        )
        self.assertIn("createLead", f.methods())

    def test_a_LIVE_background_job_registration_still_exempts_the_class(self):
        f = _McpFixture(self.root, alias="        // nothing", attribute="")
        _write(
            self.root,
            "appinfo/info.xml",
            "<?xml version='1.0'?>\n<info><id>demoapp</id><background-jobs>\n"
            "<job>OCA\\DemoApp\\Service\\LeadService</job>\n"
            "</background-jobs></info>\n",
        )
        self.assertEqual(f.methods(), set())


class PhpCommentBlankerTest(unittest.TestCase):
    """The blanker underpins every seam above, so it is tested directly."""

    def test_preserves_line_numbers_and_length(self):
        src = "<?php\n// gone\n$a = 1; /* gone\nstill gone */ $b = 2;\n# gone\n"
        out = owc._blank_php_comments(src)
        self.assertEqual(len(out), len(src))
        self.assertEqual(out.count("\n"), src.count("\n"))
        self.assertNotIn("gone", out)
        self.assertIn("$a = 1;", out)
        self.assertIn("$b = 2;", out)

    def test_keeps_attributes_because_hash_bracket_is_not_a_comment(self):
        # `#` opens a line comment in PHP; `#[` opens an ATTRIBUTE. Blanking
        # attributes would delete the very thing the seam looks for.
        src = "#[McpTool(name: 'x')]\npublic function createLead() {}\n"
        self.assertIn("#[McpTool", owc._blank_php_comments(src))

    def test_does_not_eat_a_hash_inside_a_string(self):
        src = "<?php\n$u = 'http://x/#frag';\n$v = 2;\n"
        out = owc._blank_php_comments(src)
        self.assertIn("#frag", out)
        self.assertIn("$v = 2;", out)

    def test_does_not_treat_a_slash_slash_inside_a_string_as_a_comment(self):
        src = "<?php\n$u = 'http://example.test';\n$v = 3;\n"
        self.assertIn("$v = 3;", owc._blank_php_comments(src))


class AttributeWalkUpTest(unittest.TestCase):
    def test_an_attribute_argument_containing_the_word_class_is_still_read(self):
        # `#[McpTool(handler: Foo::class)]` contains the word `class`. A
        # word-based boundary stopped the walk-up before reaching it.
        src = (
            "<?php\nclass X\n{\n"
            "    #[McpTool(handler: Foo::class)]\n"
            "    public function createThing() {}\n}\n"
        )
        lines = owc._blank_php_comments(src).split("\n")
        idx = next(i for i, ln in enumerate(lines) if "function createThing" in ln)
        self.assertTrue(owc._method_has_mcp_attribute(lines, idx))

    def test_a_previous_methods_attribute_does_not_leak_downward(self):
        src = (
            "<?php\nclass X\n{\n"
            "    #[McpTool(name: 'a')]\n"
            "    public function createA() { return 1; }\n"
            "    public function createB() { return 2; }\n}\n"
        )
        lines = owc._blank_php_comments(src).split("\n")
        idx = next(i for i, ln in enumerate(lines) if "function createB" in ln)
        self.assertFalse(owc._method_has_mcp_attribute(lines, idx))


class ExitContractTest(unittest.TestCase):
    """gate-19 once returned its finding COUNT as an exit status — a byte — so
    266 findings reported as 10 and exactly 256 would have reported PASS
    (#209). This helper must not do that: it prints one line per finding and
    always exits 0, and the bash gate counts the lines."""

    def test_main_exits_zero_even_with_findings(self):
        with _AppFixture() as root:
            svc = _write(
                root,
                "lib/Service/OrphanService.php",
                "<?php\nnamespace OCA\\Fixture\\Service;\nclass OrphanService {\n"
                "    public function postJournalEntry(array $d): void {}\n"
                "}\n",
            )
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = owc.main(["check_orphaned_write_capability.py", svc])
            self.assertEqual(rc, 0, "the count must never be returned as an exit status")
            self.assertGreater(
                len([ln for ln in buf.getvalue().splitlines() if ln.strip()]), 0
            )


class McpSeamSpellingTest(unittest.TestCase):
    """A LEADING BACKSLASH MUST NOT DISSOLVE THE SEAM (.github#276).

    Both halves of the attribute seam matched a namespace prefix as
    `(?:[A-Za-z_]\\w*\\s*\\\\+\\s*)*` — every segment had to START with a
    letter. So the fully-qualified spelling, which is the ordinary way to
    write a name with no `use` import for it, matched neither:

        registerServiceAlias('…::demoapp', \\OCA\\DemoApp\\Mcp\\Impl::class)
        #[\\OCA\\OpenRegister\\Mcp\\Attribute\\McpTool(name: 'createLead')]

    Either miss alone empties the seam and puts `createLead` — pipelinq's
    curated, spec'd MCP write tool — back on the finding list, which is
    .github#200 verbatim: a finding whose only remedy is deleting a live
    write tool.

    THE MUTANT IS BOTH SITES AT ONCE. The seam needs the alias AND the
    attribute, so reverting one regex while the other is fixed still
    reproduces the false positive — and reverting one while testing the other
    reads as "the fix changed nothing". `test_pre_fix_regexes_reproduce_the_
    false_positive` therefore restores the pre-fix pair together and asserts
    the finding comes BACK.
    """

    _FQ_ALIAS = """        $context->registerServiceAlias(
            'OCA\\\\OpenRegister\\\\Mcp\\\\IMcpScannableServices::demoapp',
            \\OCA\\DemoApp\\Mcp\\DemoScannableServices::class
        );"""

    _FQ_ATTRIBUTE = """    #[\\OCA\\OpenRegister\\Mcp\\Attribute\\McpTool(
        name: 'createLead',
        scope: 'create'
    )]"""

    # The two patterns exactly as they read before the fix.
    _PRE_FIX_ALIAS = re.compile(
        r"registerServiceAlias\s*\(\s*"
        r"(['\"])[^'\"]*IMcpScannableServices::[A-Za-z0-9_-]+\1"
        r"\s*,\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*\\+\s*)*"
        r"([A-Za-z_][A-Za-z0-9_]*)\s*::\s*class",
        re.DOTALL,
    )
    _PRE_FIX_ATTR = re.compile(r"#\[\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*\\+\s*)*McpTool\b")

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = os.path.join(self._tmp.name, "demoapp")
        os.makedirs(self.root)

    def tearDown(self):
        self._tmp.cleanup()

    def _fully_qualified(self):
        return _McpFixture(
            self.root, alias=self._FQ_ALIAS, attribute=self._FQ_ATTRIBUTE
        )

    def test_fully_qualified_alias_and_attribute_still_form_the_seam(self):
        self.assertNotIn("createLead", self._fully_qualified().methods())

    def test_fully_qualified_alias_with_short_attribute(self):
        f = _McpFixture(self.root, alias=self._FQ_ALIAS, attribute=_MCP_ATTRIBUTE)
        self.assertNotIn("createLead", f.methods())

    def test_short_alias_with_fully_qualified_attribute(self):
        f = _McpFixture(
            self.root, alias=_MCP_ALIAS_CALL, attribute=self._FQ_ATTRIBUTE
        )
        self.assertNotIn("createLead", f.methods())

    def test_pre_fix_regexes_reproduce_the_false_positive(self):
        """THE MUTANT. Restore BOTH pre-fix patterns; the finding must return.

        Without this the two assertions above could be green because the seam
        never depended on those regexes at all.
        """
        old_alias, old_attr = owc._MCP_ALIAS_RE, owc._MCP_TOOL_ATTR_RE
        self.assertIsNot(old_alias, self._PRE_FIX_ALIAS)
        self.assertIsNot(old_attr, self._PRE_FIX_ATTR)
        try:
            owc._MCP_ALIAS_RE = self._PRE_FIX_ALIAS
            owc._MCP_TOOL_ATTR_RE = self._PRE_FIX_ATTR
            self.assertIn(
                "createLead",
                self._fully_qualified().methods(),
                "the pre-fix patterns must reproduce the #200 false positive — "
                "if they do not, this test is measuring nothing",
            )
        finally:
            owc._MCP_ALIAS_RE, owc._MCP_TOOL_ATTR_RE = old_alias, old_attr

    def test_the_controls_survive_the_widening(self):
        """ANTI-WIDENING. Accepting a leading `\\` must not accept everything."""
        # A DIFFERENT interface in the alias grants nothing.
        f = _McpFixture(
            self.root,
            alias=self._FQ_ALIAS.replace("IMcpScannableServices", "ISomethingElse"),
            attribute=self._FQ_ATTRIBUTE,
        )
        self.assertIn("createLead", f.methods())
        # An attribute that is not McpTool grants nothing.
        f = _McpFixture(
            self.root,
            alias=self._FQ_ALIAS,
            attribute="    #[\\OCA\\Other\\Attribute\\NotAnMcpTool(name: 'x')]",
        )
        self.assertIn("createLead", f.methods())
        # And a write method with no attribute on the same class is still dead.
        self.assertIn("createInvoice", self._fully_qualified().methods())


if __name__ == "__main__":
    unittest.main()
