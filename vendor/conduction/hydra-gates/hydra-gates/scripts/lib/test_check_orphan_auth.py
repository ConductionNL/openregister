#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_orphan_auth (gate-6). Run with:

    python3 scripts/lib/test_check_orphan_auth.py

or via pytest:

    python3 -m pytest scripts/lib/test_check_orphan_auth.py

The fixtures deliberately do NOT mirror the gate's own regexes back at it
(the gate-56 lesson). Each candidate method is written as a realistic PHP
service — the true positives look like real dead guards; the false
positives look like the real reference-data / SLA / availability
predicates that the old prefix-only gate over-matched
(`Kcc\\SlaCalculator::isBreached` is copied verbatim from procest).
"""
from __future__ import annotations

import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_orphan_auth as coa  # noqa: E402


def _scan_in_repo(candidate_rel: str, candidate_src: str, extra: dict[str, str] | None = None) -> list[str]:
    """Materialise a throwaway app repo, write *candidate_src* at
    *candidate_rel* under lib/, plus any *extra* {rel_path: src} caller
    files, chdir in, and return finding lines."""
    with tempfile.TemporaryDirectory() as root:
        cand = Path(root) / candidate_rel
        cand.parent.mkdir(parents=True, exist_ok=True)
        cand.write_text(candidate_src, encoding="utf-8")
        for rel, src in (extra or {}).items():
            p = Path(root) / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(src, encoding="utf-8")
        cwd = os.getcwd()
        try:
            os.chdir(root)
            return coa.scan_files([candidate_rel])
        finally:
            os.chdir(cwd)


class TruePositiveDeadAccessControl(unittest.TestCase):
    """(a) A genuine dead access-control method guarding a mutation must FAIL."""

    def test_dead_ownership_guard_is_flagged(self):
        src = """\
<?php
namespace OCA\\Demo\\Service;

class RecordService {
    public function __construct(private PermissionService $permissions) {}

    /** Guards the delete mutation — but nobody calls it. */
    public function isOwner(string $userId, string $recordId): bool
    {
        return $this->permissions->ownerOf($recordId) === $userId;
    }

    public function delete(string $recordId): void
    {
        // BUG: no isOwner() check before the destructive write.
        $this->store->delete($recordId);
    }
}
"""
        findings = _scan_in_repo("lib/Service/RecordService.php", src)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=isOwner", findings[0])

    def test_wired_guard_is_not_flagged(self):
        """Same guard, but delete() actually calls it → PASS (not orphan)."""
        src = """\
<?php
class RecordService {
    public function isOwner(string $userId, string $recordId): bool
    {
        return $this->permissions->ownerOf($recordId) === $userId;
    }
    public function delete(string $userId, string $recordId): void
    {
        if (!$this->isOwner($userId, $recordId)) {
            throw new \\OCP\\Files\\ForbiddenException('nope');
        }
        $this->store->delete($recordId);
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/RecordService.php", src), [])


class FalsePositiveNonAuthPredicates(unittest.TestCase):
    """(b) The four confirmed non-auth predicates must PASS (not flagged)."""

    def test_is_valid_code_reference_data(self):
        src = """\
<?php
class Iv3TaakveldList {
    private const CODES = ['01.1', '01.2', '02.1'];
    public function isValidCode(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/Iv3TaakveldList.php", src), [])

    def test_is_deprecated_reference_data(self):
        src = """\
<?php
class Iv3TaakveldList {
    private const DEPRECATED = ['09.9'];
    public function isDeprecated(string $code): bool
    {
        return in_array($code, self::DEPRECATED, true);
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/Iv3TaakveldList.php", src), [])

    def test_is_breached_sla_query_verbatim(self):
        # Copied verbatim from procest lib/Service/Kcc/SlaCalculator.php:201.
        src = """\
<?php
class SlaCalculator {
    public function isBreached(string $channel, DateTimeImmutable $start, DateTimeImmutable $now): bool
    {
        return ($now > $this->deadlineFor(channel: $channel, start: $start));
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/Kcc/SlaCalculator.php", src), [])

    def test_is_llm_assist_available_ui_flag(self):
        src = """\
<?php
class WOOAnonymisationAssistService {
    public function isLlmAssistAvailable(): bool
    {
        return $this->config->getAppValue('woo', 'llm_enabled', 'no') === 'yes';
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/WOOAnonymisationAssistService.php", src), [])


class TruePositiveDecideskTrio(unittest.TestCase):
    """(c) The decidesk 2026-04 corpus (the original gate-6 finding) must
    STILL FAIL — all three uncalled guards flagged."""

    def test_decidesk_trio_all_flagged(self):
        src = """\
<?php
namespace OCA\\Decidesk\\Service;

class WorkflowService {
    /** Role-based transition guard. */
    public function isTransitionAllowed(Meeting $meeting, string $userId, string $newStatus): bool
    {
        $role = $this->roles->forUser($userId, $meeting);
        return in_array($newStatus, $this->allowedTransitions($role), true);
    }

    /** Whether the decision needs the chair's explicit authorization. */
    public function requiresChairAuthorization(Decision $decision): bool
    {
        return $decision->getImpact() === 'major';
    }

    /** Quorum enforcement — throws when not met. */
    public function validateQuorum(Meeting $meeting): void
    {
        if (count($meeting->presentMembers()) < $meeting->requiredQuorum()) {
            throw new QuorumNotMetException('quorum not reached');
        }
    }

    /** The mutation that SHOULD call all three but does not. */
    public function transition(Meeting $meeting, string $userId, string $newStatus): void
    {
        $meeting->setStatus($newStatus);
        $this->store->save($meeting);
    }
}
"""
        findings = _scan_in_repo("lib/Service/WorkflowService.php", src)
        names = sorted(f.split("method=")[1].split(" ")[0] for f in findings)
        self.assertEqual(
            names,
            ["isTransitionAllowed", "requiresChairAuthorization", "validateQuorum"],
            findings,
        )


class ShillinqSegregationGuard(unittest.TestCase):
    """The shillinq segregation-of-duties hardcoded-pass guard, uncalled,
    must STILL FAIL (subject param + role-service signal)."""

    def test_segregation_guard_flagged(self):
        src = """\
<?php
class SegregationService {
    public function checkSegregationOfDuties(string $userId, string $action): bool
    {
        $roles = $this->roles->forUser($userId);
        return true; // hardcoded pass — the very bug the gate must surface
    }
}
"""
        findings = _scan_in_repo("lib/Service/SegregationService.php", src)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=checkSegregationOfDuties", findings[0])


class PipelinqWidgetOriginGuard(unittest.TestCase):
    """The pipelinq `isWidgetOriginAllowed` origin-trust guard, uncalled,
    must STILL FAIL (the `Allowed` name token is an S4 authz signal). If
    the render path never consults it, an untrusted origin renders — the
    exact dead-guard shape the gate exists to surface."""

    def test_widget_origin_guard_flagged_when_uncalled(self):
        src = """\
<?php
namespace OCA\\Pipelinq\\Service;

class WidgetService {
    /** Only trusted origins may embed the widget. */
    public function isWidgetOriginAllowed(string $origin): bool
    {
        return in_array($origin, $this->trustedOrigins(), true);
    }

    /** BUG: renders without ever consulting isWidgetOriginAllowed(). */
    public function render(string $origin): string
    {
        return $this->build($origin);
    }
}
"""
        findings = _scan_in_repo("lib/Service/WidgetService.php", src)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=isWidgetOriginAllowed", findings[0])

    def test_widget_origin_guard_not_flagged_when_wired(self):
        """Same guard, but render() consults it → PASS (not orphan)."""
        src = """\
<?php
class WidgetService {
    public function isWidgetOriginAllowed(string $origin): bool
    {
        return in_array($origin, $this->trustedOrigins(), true);
    }
    public function render(string $origin): string
    {
        if (!$this->isWidgetOriginAllowed($origin)) {
            throw new \\RuntimeException('untrusted origin');
        }
        return $this->build($origin);
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/WidgetService.php", src), [])


class GenericInputValidatorNotFlagged(unittest.TestCase):
    """A plain input validator with no throw and no auth signal is NOT an
    access control and must PASS even though it starts with `validate`."""

    def test_validate_email_format_not_flagged(self):
        src = """\
<?php
class MailService {
    public function validateEmailFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
"""
        self.assertEqual(_scan_in_repo("lib/Service/MailService.php", src), [])


_LAUNCHPAD_CONTROLLER = """\
<?php
class LiveTileController {
    public function validateSource(string $userId, string $url): JSONResponse
    {
        if (!$this->service->isHostAllowed($url)) {
            throw new \\OCP\\AppFramework\\OCS\\OCSForbiddenException('blocked host');
        }
        return new JSONResponse(['ok' => true]);
    }
}
"""

_ROUTES = """\
<?php
return ['routes' => [
    ['name' => 'liveTile#validateSource', 'url' => '/api/livetile/validate-source', 'verb' => 'POST'],
]];
"""


class RoutedControllerActionIsNotAnOrphan(unittest.TestCase):
    """.github#290 — the router invokes a controller action by reflection from
    `appinfo/routes.php`, so it has NO `->method(` call site anywhere and the
    lib/+src/ corpus cannot contain one. Every routed action whose name starts
    with a gate verb was reported `defined-but-never-called` while live.

    Measured on launchpad: `LiveTileController::validateSource` — routed at
    appinfo/routes.php:557, called from src/services/liveTileClient.js by URL,
    and unit-tested — was reported as an orphan.

    Each relaxation below ships with the true positive it must not swallow.
    The route table is treated as EVIDENCE, not a name heuristic: the
    controller and the method must both match the `'liveTile#validateSource'`
    entry.
    """

    # -- the false positive, gone -----------------------------------------
    def test_fp_a_routed_controller_action_is_not_an_orphan(self):
        self.assertEqual(
            _scan_in_repo("lib/Controller/LiveTileController.php",
                          _LAUNCHPAD_CONTROLLER,
                          {"appinfo/routes.php": _ROUTES}),
            [])

    def test_fp_a_routes_subdirectory_table_also_counts(self):
        self.assertEqual(
            _scan_in_repo("lib/Controller/LiveTileController.php",
                          _LAUNCHPAD_CONTROLLER,
                          {"appinfo/routes/api.php": _ROUTES}),
            [])

    def test_fp_an_attribute_routed_action_is_not_an_orphan(self):
        # NC attribute routing needs no routes.php entry at all.
        src = """\
<?php
class LiveTileController {
    #[ApiRoute(verb: 'POST', url: '/api/livetile/validate-source')]
    public function validateSource(string $userId, string $url): JSONResponse
    {
        if (!$this->service->isHostAllowed($url)) {
            throw new \\OCP\\AppFramework\\OCS\\OCSForbiddenException('blocked host');
        }
        return new JSONResponse(['ok' => true]);
    }
}
"""
        self.assertEqual(
            _scan_in_repo("lib/Controller/LiveTileController.php", src), [])

    # -- THE TRUE POSITIVE THIS MUST NOT SWALLOW --------------------------
    def test_tp_an_unrouted_controller_action_is_still_an_orphan(self):
        # No route table at all: the method really is unreachable.
        findings = _scan_in_repo("lib/Controller/LiveTileController.php",
                                 _LAUNCHPAD_CONTROLLER)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=validateSource", findings[0])

    def test_tp_a_route_table_naming_a_DIFFERENT_method_does_not_clear_it(self):
        # THE ABUSE CONTROL. If the match ever degrades to "this controller
        # appears in the route table", every unrouted action on a routed
        # controller goes quiet — and that is most of them.
        routes = """\
<?php
return ['routes' => [
    ['name' => 'liveTile#index', 'url' => '/api/livetile', 'verb' => 'GET'],
]];
"""
        findings = _scan_in_repo("lib/Controller/LiveTileController.php",
                                 _LAUNCHPAD_CONTROLLER,
                                 {"appinfo/routes.php": routes})
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=validateSource", findings[0])

    def test_tp_a_route_on_a_DIFFERENT_controller_does_not_clear_it(self):
        routes = """\
<?php
return ['routes' => [
    ['name' => 'other#validateSource', 'url' => '/api/other/validate', 'verb' => 'POST'],
]];
"""
        findings = _scan_in_repo("lib/Controller/LiveTileController.php",
                                 _LAUNCHPAD_CONTROLLER,
                                 {"appinfo/routes.php": routes})
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=validateSource", findings[0])

    def test_tp_a_SERVICE_is_never_cleared_by_the_route_table(self):
        # A service is not routable. This is the arm that proves the exemption
        # stays inside the controller layer instead of becoming a blanket:
        # the decidesk isTransitionAllowed/validateQuorum family lives in
        # services, and a route entry must never quiet one.
        src = """\
<?php
class MeetingService {
    public function validateQuorum(string $userId, string $meetingId): bool
    {
        if (!$this->acl->isAllowed($userId)) {
            throw new \\OCP\\AppFramework\\OCS\\OCSForbiddenException('no quorum rights');
        }
        return true;
    }
}
"""
        routes = """\
<?php
return ['routes' => [
    ['name' => 'meeting#validateQuorum', 'url' => '/api/meeting/quorum', 'verb' => 'POST'],
]];
"""
        findings = _scan_in_repo("lib/Service/MeetingService.php", src,
                                 {"appinfo/routes.php": routes})
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=validateQuorum", findings[0])


if __name__ == "__main__":
    unittest.main(verbosity=2)
