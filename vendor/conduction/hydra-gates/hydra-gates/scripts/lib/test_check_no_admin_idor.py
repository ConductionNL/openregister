#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_no_admin_idor (gate-7). Run with:

    python3 scripts/lib/test_check_no_admin_idor.py

or via pytest:

    python3 -m pytest scripts/lib/test_check_no_admin_idor.py
"""
from __future__ import annotations

import io
import os
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_no_admin_idor as cni  # noqa: E402


# ---------------------------------------------------------------------------
# Helper
# ---------------------------------------------------------------------------

def _scan(src: str) -> list[str]:
    """Write *src* to a temp file, scan it, capture printed lines."""
    with tempfile.NamedTemporaryFile(
        suffix=".php", mode="w", encoding="utf-8", delete=False
    ) as fh:
        fh.write(src)
        fh_name = fh.name
    buf = io.StringIO()
    try:
        with redirect_stdout(buf):
            cni.scan_file(fh_name)
    finally:
        os.unlink(fh_name)
    return [ln for ln in buf.getvalue().splitlines() if ln.strip()]


# ---------------------------------------------------------------------------
# Exemption 2 — preflightedCors* name prefix
# ---------------------------------------------------------------------------

class PreflightedCorsExemptionTest(unittest.TestCase):
    """Methods whose name starts with preflightedCors must NOT be flagged.

    Nextcloud convention: OPTIONS routes handled by ``preflightedCors`` /
    ``preflightedCorsItem`` / ``preflightedCorsNested`` etc. are sent by
    browsers *without credentials* before the real request; an auth guard
    would break CORS.  These are never IDOR vectors.
    """

    def test_preflightedCors_not_flagged(self):
        """The exact fleet name preflightedCors with @NoAdminRequired is exempted."""
        src = """\
<?php
class DirectoryController {
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): Response
    {
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE');
        return $response;
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_preflightedCorsItem_not_flagged(self):
        """Variant suffix preflightedCorsItem is also exempted."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function preflightedCorsItem(): Response
    {
        $r = new Response();
        $r->addHeader('Access-Control-Allow-Origin', '*');
        $r->addHeader('Access-Control-Allow-Methods', 'PUT, PATCH');
        return $r;
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_preflightedCors_mixed_case_not_flagged(self):
        """Case-insensitive match: PreflightedCors prefix is also exempt."""
        src = """\
<?php
class SomeController {
    /**
     * @NoAdminRequired
     */
    public function PreflightedCors(): Response
    {
        $r = new Response();
        $r->addHeader('Access-Control-Allow-Origin', 'https://example.com');
        return $r;
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_non_preflight_method_without_guard_is_flagged(self):
        """A method NOT named preflightedCors* without a guard must be flagged."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function previewItem(string $id): JSONResponse
    {
        $item = $this->service->find($id);
        return new JSONResponse($item);
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("previewItem", findings[0])
        self.assertIn("no-auth-guard-in-body", findings[0])

    def test_idor_exempt_tag_with_reason_passes(self):
        """`@no-admin-idor-exempt <reason>` in the docblock exempts the method."""
        src = """\
<?php
class XWikiController {
    /**
     * Search xWiki pages.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt read-only knowledge-base proxy, no object ids
     */
    public function search(): JSONResponse
    {
        return new JSONResponse($this->xwiki->search($q));
    }
}
"""
        findings = _scan(src)
        self.assertEqual(findings, [])

    def test_idor_exempt_tag_without_reason_still_flagged(self):
        """A bare `@no-admin-idor-exempt` tag (no reason) does NOT exempt."""
        src = """\
<?php
class XWikiController {
    /**
     * @NoAdminRequired
     * @no-admin-idor-exempt
     */
    public function search(): JSONResponse
    {
        return new JSONResponse($this->xwiki->search($q));
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("search", findings[0])

    def test_preview_prefix_not_confused_with_preflight(self):
        """Methods starting with 'preview' are NOT CORS handlers — still flagged."""
        src = """\
<?php
class ObjectController {
    /**
     * @NoAdminRequired
     */
    public function previewObject(): JSONResponse
    {
        return new JSONResponse($this->service->findAll());
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("previewObject", findings[0])


# ---------------------------------------------------------------------------
# Exemption 3 — CORS-headers-only body (no data access)
# ---------------------------------------------------------------------------

class CorsOnlyBodyExemptionTest(unittest.TestCase):
    """Oddly-named handlers that only set Access-Control-* headers are exempt."""

    def test_cors_only_body_exempted(self):
        """A method that only sets CORS headers is exempted even without the name convention."""
        src = """\
<?php
class ApiController {
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function corsHandler(): Response
    {
        $r = new Response();
        $r->addHeader('Access-Control-Allow-Origin', '*');
        $r->addHeader('Access-Control-Allow-Methods', 'GET');
        return $r;
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_cors_plus_data_access_not_exempted(self):
        """A method that sets CORS headers AND accesses data is still flagged."""
        src = """\
<?php
class ApiController {
    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $data = $this->mapper->findAll();
        $r = new JSONResponse($data);
        $r->addHeader('Access-Control-Allow-Origin', '*');
        return $r;
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("index", findings[0])


# ---------------------------------------------------------------------------
# Exemption 1 — __construct
# ---------------------------------------------------------------------------

class ConstructorExemptionTest(unittest.TestCase):
    def test_constructor_not_flagged(self):
        """__construct is never a routed endpoint — always skipped."""
        src = """\
<?php
class MyController {
    /**
     * @NoAdminRequired
     */
    public function __construct(
        private MyService $service
    ) {
    }
}
"""
        self.assertEqual(_scan(src), [])


# ---------------------------------------------------------------------------
# Guard patterns — must satisfy gate-7
# ---------------------------------------------------------------------------

class GuardPatternTest(unittest.TestCase):
    def test_ocs_forbidden_exception_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        if (!$this->canRead($id)) {
            throw new OCSForbiddenException('Access denied');
        }
        return new JSONResponse($this->service->find($id));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_is_admin_check_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        if (!$this->isAdmin()) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($this->service->find($id));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_authorize_service_call_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function update(string $id): JSONResponse
    {
        $this->authorizationService->authorizeAction('update', $id);
        return new JSONResponse($this->service->find($id));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_require_service_call_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function destroy(string $id): JSONResponse
    {
        $this->permissionService->requirePermission('delete', $id);
        $this->service->delete($id);
        return new JSONResponse([]);
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_ensure_service_call_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function create(): JSONResponse
    {
        $this->accessService->ensureOwnership($this->userId);
        return new JSONResponse($this->service->create([]));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_status_unauthorized_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->service->find($id));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_template_response_passes(self):
        """SPA page renderers that return TemplateResponse are exempt."""
        src = """\
<?php
class DashboardController {
    /**
     * @NoAdminRequired
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse('myapp', 'index');
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_template_response_in_return_type_hint_passes(self):
        """TemplateResponse in the return-type hint (not in the body) is also exempt.

        The bash gate includes the function declaration line in its _body
        variable, so a method like ``dashboard(): TemplateResponse`` that
        delegates to ``$this->makeSpaResponse()`` passes because the return
        type hint contains 'TemplateResponse'. The Python implementation must
        match this behaviour.
        """
        src = """\
<?php
class UiController {
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function dashboard(): TemplateResponse
    {
        return $this->makeSpaResponse();
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_public_page_annotation_on_method_passes(self):
        """@PublicPage on the method head satisfies the gate."""
        src = """\
<?php
class PublicController {
    /**
     * @NoAdminRequired
     * @PublicPage
     */
    public function listing(): JSONResponse
    {
        return new JSONResponse($this->service->findAll());
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_attribute_public_page_passes(self):
        """#[PublicPage] PHP 8 attribute on the method also satisfies the gate."""
        src = """\
<?php
class PublicController {
    /**
     * @NoAdminRequired
     */
    #[PublicPage]
    public function listing(): JSONResponse
    {
        return new JSONResponse($this->service->findAll());
    }
}
"""
        self.assertEqual(_scan(src), [])


# ---------------------------------------------------------------------------
# Real IDOR violation — must be caught
# ---------------------------------------------------------------------------

class RealIdorViolationTest(unittest.TestCase):
    def test_no_guard_at_all_is_flagged(self):
        """A @NoAdminRequired method with no guard, no PublicPage, no exemption is flagged."""
        src = """\
<?php
class ObjectsController {
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string $id): JSONResponse
    {
        $object = $this->objectService->find($id);
        return new JSONResponse($object);
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("show", findings[0])
        self.assertIn("no-auth-guard-in-body", findings[0])

    def test_multiple_violations_all_reported(self):
        """Multiple unguarded methods in the same file are all reported."""
        src = """\
<?php
class ObjectsController {
    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        return new JSONResponse($this->service->findAll());
    }

    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        return new JSONResponse($this->service->find($id));
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(string $id): JSONResponse
    {
        $this->service->delete($id);
        return new JSONResponse([]);
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 3)
        names = {f.split("method=")[1].split(" ")[0] for f in findings}
        self.assertEqual(names, {"index", "show", "destroy"})

    def test_method_without_no_admin_required_not_flagged(self):
        """Methods that lack @NoAdminRequired are out of scope for gate-7."""
        src = """\
<?php
class AdminController {
    public function adminAction(): JSONResponse
    {
        return new JSONResponse($this->service->findAll());
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_idor_with_cors_plus_data_access(self):
        """A method with CORS headers AND data access is still flagged if no guard."""
        src = """\
<?php
class ApiController {
    /**
     * @NoAdminRequired
     */
    public function records(): JSONResponse
    {
        $rows = $this->mapper->findAll();
        $r = new JSONResponse($rows);
        $r->addHeader('Access-Control-Allow-Origin', '*');
        return $r;
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("records", findings[0])


# ---------------------------------------------------------------------------
# Pattern 1 — private guard-helper delegation
# ---------------------------------------------------------------------------

class GuardHelperDelegationTest(unittest.TestCase):
    """A routed method that delegates its guard to a same-class helper passes."""

    def test_helper_that_throws_clears_caller(self):
        """Caller invoking a helper whose body throws is guarded."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        $this->guardCase($id);
        return new JSONResponse($this->service->find($id));
    }

    private function guardCase(string $id): void
    {
        if (!$this->canRead($id)) {
            throw new OCSForbiddenException('nope');
        }
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_helper_returning_403_response_clears_caller(self):
        """Helper that returns a 403 Response (checked by caller) is a guard."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $denial = $this->requireAdmin();
        if ($denial !== null) {
            return $denial;
        }
        return new JSONResponse($this->service->findAll());
    }

    private function requireAdmin(): ?JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        return null;
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_predicate_named_helper_clears_caller(self):
        """A helper whose NAME reads as an auth predicate counts as a guard."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function destroy(string $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse([], 403);
        }
        $this->service->delete($id);
        return new JSONResponse([]);
    }

    private function isCurrentUserAdmin(): bool
    {
        return $this->groupManager->isInGroup($this->userId, 'admin');
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_guard_helper_after_mutation_still_flags(self):
        """A guard-helper called only AFTER the write does not protect it."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function update(string $id): JSONResponse
    {
        $this->service->updateThing($id);
        $this->assertMayAct($id);
        return new JSONResponse([]);
    }

    private function assertMayAct(string $id): void
    {
        throw new OCSForbiddenException('too late');
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("update", findings[0])

    def test_calling_non_guard_helper_still_flags(self):
        """Invoking an ordinary (non-guard) helper does NOT clear the finding."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        $data = $this->serialize($id);
        return new JSONResponse($data);
    }

    private function serialize(string $id): array
    {
        return ['id' => $id];
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("show", findings[0])


# ---------------------------------------------------------------------------
# Pattern 2 — OpenRegister data-layer RBAC delegation (ADR-022)
# ---------------------------------------------------------------------------

class OrDataLayerDelegationTest(unittest.TestCase):
    """OR-namespace methods delegating to ObjectService / a *Mapper pass."""

    def test_objectservice_access_in_or_namespace_cleared(self):
        """@NoAdminRequired + ObjectService fetch inside OCA\\OpenRegister passes."""
        src = """\
<?php
namespace OCA\\OpenRegister\\Controller;
class ObjectsController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        $object = $this->objectService->find($id);
        return new JSONResponse($object);
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_mapper_access_in_or_namespace_cleared(self):
        """@NoAdminRequired + *Mapper fetch inside OCA\\OpenRegister passes."""
        src = """\
<?php
namespace OCA\\OpenRegister\\Controller;
class SourcesController {
    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $sources = $this->sourceMapper->findAll();
        return new JSONResponse($sources);
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_helper_objectservice_fetch_in_or_namespace_cleared(self):
        """A validateObject-style helper doing the OR RBAC fetch clears the caller."""
        src = """\
<?php
namespace OCA\\OpenRegister\\Controller;
class DeckLinksController {
    /**
     * @NoAdminRequired
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        $object = $this->validateObject($register, $schema, $id);
        if ($object === null) {
            return new JSONResponse(['error' => 'not found'], 404);
        }
        return new JSONResponse($this->deckLinkService->getLinkedCards($object->getUuid()));
    }

    private function validateObject(string $register, string $schema, string $id): ?ObjectEntity
    {
        $this->objectService->setRegister($register);
        $this->objectService->setSchema($schema);
        $this->objectService->setObject($id);
        return $this->objectService->getObject();
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_same_objectservice_access_OUTSIDE_or_namespace_still_flags(self):
        """The delegation is OR-scoped: an identical leaf-app method still flags.

        This is the decidesk#44 safety proof — a consumer app that fetches via
        ObjectService without an explicit controller guard is a real IDOR and
        must NOT be masked by Pattern 2.
        """
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;
class MinutesController {
    /**
     * @NoAdminRequired
     */
    public function generateALVDraft(string $minutesId): JSONResponse
    {
        $minutes = $this->objectService->findObject(id: $minutesId);
        return new JSONResponse($this->generate($minutes));
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("generateALVDraft", findings[0])

    def test_or_namespace_no_data_access_still_flags(self):
        """An OR method with NO ObjectService/Mapper access and no guard still flags."""
        src = """\
<?php
namespace OCA\\OpenRegister\\Controller;
class WidgetController {
    /**
     * @NoAdminRequired
     */
    public function ping(string $id): JSONResponse
    {
        return new JSONResponse($this->externalGateway->call($id));
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("ping", findings[0])


# ---------------------------------------------------------------------------
# Numeric status-code parity (401/403 literal == Http::STATUS_* constant)
# ---------------------------------------------------------------------------

class NumericStatusParityTest(unittest.TestCase):
    def test_numeric_403_statuscode_named_arg_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse([], statusCode: 401);
        }
        return new JSONResponse($this->service->find($id));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_numeric_403_positional_arg_passes(self):
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        if (!$this->canRead($id)) {
            return new JSONResponse(['error' => 'no'], 403);
        }
        return new JSONResponse($this->service->find($id));
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_unrelated_number_403_not_a_false_guard(self):
        """A bare 403 that is not a response status (e.g. an array value) does not clear."""
        src = """\
<?php
class ItemController {
    /**
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        $limit = 403000;
        return new JSONResponse($this->service->find($id, $limit));
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1)
        self.assertIn("show", findings[0])


# ---------------------------------------------------------------------------
# _is_preflight_cors_method unit tests
# ---------------------------------------------------------------------------

class IsPreflightCorsMethodTest(unittest.TestCase):
    def test_exact_name_matches(self):
        self.assertTrue(cni._is_preflight_cors_method("preflightedCors"))

    def test_suffix_variant_matches(self):
        self.assertTrue(cni._is_preflight_cors_method("preflightedCorsItem"))
        self.assertTrue(cni._is_preflight_cors_method("preflightedCorsNested"))

    def test_case_insensitive(self):
        self.assertTrue(cni._is_preflight_cors_method("PreflightedCors"))
        self.assertTrue(cni._is_preflight_cors_method("PREFLIGHTEDCORS"))

    def test_preview_prefix_does_not_match(self):
        self.assertFalse(cni._is_preflight_cors_method("previewItem"))

    def test_preflight_alone_does_not_match(self):
        """Only the specific 'preflightedCors' prefix is exempt by name."""
        self.assertFalse(cni._is_preflight_cors_method("preflight"))
        self.assertFalse(cni._is_preflight_cors_method("preflightItem"))

    def test_construct_does_not_match(self):
        self.assertFalse(cni._is_preflight_cors_method("__construct"))


# ---------------------------------------------------------------------------
# Response-helper deny spellings (::forbidden( / ->unauthorized( )
# ---------------------------------------------------------------------------

class ResponseHelperGuardSpellingTest(unittest.TestCase):
    """A deny response routed through a helper is the same guard shape.

    Controllers that centralise deny-responses in a helper class
    (``ResponseHelper::forbidden(...)``) were flagged even though the guard
    was present, purely because the gate only recognised the inline
    ``Http::STATUS_FORBIDDEN`` / numeric spellings.
    """

    def test_static_response_helper_forbidden_passes(self):
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function act(string $id) {
        if ($this->request->getParam('userId') !== $this->userId) {
            return ResponseHelper::forbidden(message: 'nope');
        }
        return $this->svc->get($id);
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_instance_response_helper_unauthorized_passes(self):
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function act(string $id) {
        if ($this->userId === null) {
            return $this->responses->unauthorized();
        }
        return $this->svc->get($id);
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_forbidden_substring_is_not_a_false_guard(self):
        """``forbiddenWords(`` must NOT be mistaken for a deny response.

        Guards against the classic substring-match bug: the name must be
        followed by the call parenthesis, not merely start with 'forbidden'.
        """
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function act(string $id) {
        $bad = $this->filter->forbiddenWords($id);
        return $this->svc->get($id);
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=act", out[0])


# ---------------------------------------------------------------------------
# Pattern 3 — session-scoped endpoint with no caller-supplied reference
# ---------------------------------------------------------------------------

class SessionScopedNoReferenceTest(unittest.TestCase):
    """Zero params + no request reads + session identity => not an IDOR vector.

    The adversarial cases below are the important half: each one removes a
    single condition and asserts the method is STILL flagged, so the pattern
    cannot be used to smuggle a real IDOR past the gate.
    """

    def test_zero_param_session_scoped_method_passes(self):
        """The canonical safe shape (cf. AcknowledgementController::pending)."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function pending() {
        return $this->svc->getPending(userId: $this->userId);
    }
}
"""
        self.assertEqual(_scan(src), [])

    def test_method_with_id_parameter_still_flagged(self):
        """A bound route parameter IS a direct object reference — must flag."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function show(int $id) {
        return $this->svc->find($id);
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=show", out[0])

    def test_zero_params_but_reads_request_param_still_flagged(self):
        """Reading an id from the request is equally attacker-controlled."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function show() {
        $id = $this->request->getParam('id');
        return $this->svc->find($id);
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=show", out[0])

    def test_zero_params_reading_superglobal_still_flagged(self):
        """$_GET is caller-controlled input just as much as getParam()."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function show() {
        return $this->svc->find($_GET['id']);
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=show", out[0])

    def test_zero_params_without_session_identity_still_flagged(self):
        """No session scoping => no positive evidence it is self-scoped."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function listEverything() {
        return $this->svc->findAll();
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=listEverything", out[0])

    def test_session_identity_does_not_launder_a_request_supplied_id(self):
        """The dangerous combination: session identity present but an id is
        still taken from the request and used unchecked. Must stay flagged."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function show() {
        $me = $this->userId;
        $id = $this->request->getParam('dossierId');
        return $this->svc->find($id);
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=show", out[0])

    def test_unparseable_signature_fails_closed(self):
        """When the parameter list cannot be read, do not clear the method."""
        self.assertFalse(cni._is_session_scoped_no_reference(None, "$this->userId"))

    def test_helper_requires_all_three_conditions(self):
        # zero params + session identity, no request read -> clear
        self.assertTrue(cni._is_session_scoped_no_reference("", "$this->userId"))
        # params present -> not clear
        self.assertFalse(cni._is_session_scoped_no_reference("int $id", "$this->userId"))
        # request read present -> not clear
        self.assertFalse(
            cni._is_session_scoped_no_reference("", "$this->request->getParam('id')")
        )
        # no session identity -> not clear
        self.assertFalse(cni._is_session_scoped_no_reference("", "$this->svc->findAll()"))

    def test_default_valued_params_with_parens_are_not_zero_params(self):
        """Brace-aware parsing: a default value containing '(' must not make
        the parameter list look empty."""
        src = """\
<?php
class C {
    /**
     * @NoAdminRequired
     */
    public function show(int $id = 0, array $opts = ['a' => (1 + 2)]) {
        $me = $this->userId;
        return $this->svc->find($id);
    }
}
"""
        out = _scan(src)
        self.assertEqual(len(out), 1)
        self.assertIn("method=show", out[0])


# ---------------------------------------------------------------------------
# Pattern 4 — delegation chains and collaborator-hosted guards
# ---------------------------------------------------------------------------

def _scan_app(controller_src: str, collaborators: dict) -> list[str]:
    """Scan a controller inside a throwaway app tree with real collaborators.

    Pattern 4 resolves a typed property to a *file* under the app's ``lib/``
    tree and reads that file, so these tests must lay out a real directory:

        <root>/lib/Controller/TestController.php
        <root>/lib/Service/<Name>.php

    *collaborators* maps ``ClassName -> php source``.
    """
    with tempfile.TemporaryDirectory() as root:
        ctl_dir = Path(root) / "lib" / "Controller"
        svc_dir = Path(root) / "lib" / "Service"
        ctl_dir.mkdir(parents=True)
        svc_dir.mkdir(parents=True)
        for name, body in collaborators.items():
            (svc_dir / f"{name}.php").write_text(body, encoding="utf-8")
        ctl = ctl_dir / "TestController.php"
        ctl.write_text(controller_src, encoding="utf-8")
        # Pattern 4 caches per-root and per-file; a temp dir is unique per test
        # but clear anyway so a reused inode can never leak a stale answer.
        cni._CLASS_INDEX_CACHE.clear()
        cni._COLLABORATOR_GUARD_CACHE.clear()
        buf = io.StringIO()
        with redirect_stdout(buf):
            cni.scan_file(str(ctl))
        cni._CLASS_INDEX_CACHE.clear()
        cni._COLLABORATOR_GUARD_CACHE.clear()
    return [ln for ln in buf.getvalue().splitlines() if ln.strip()]


# The decidesk responder, reduced to the shape that matters: staffAction()
# delegates to requireStaff() which denies with 401/403; citizenAction()
# denies an anonymous caller with 401; respond() is NOT a guard — it only maps
# a result or an exception onto a JSONResponse.
_RESPONDER = """\
<?php
namespace OCA\\Decidesk\\Service;

class ParticipationResponder {
    public function staffAction(callable $operation, ?string $key = null, int $status = 200) {
        return ($this->requireStaff() ?? $this->respond($operation, $key, $status));
    }

    public function citizenAction(callable $operation, ?string $key = null, int $status = 200) {
        $uid = $this->staffGuard->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        return $this->respond($operation, $key, $status);
    }

    private function requireStaff() {
        if ($this->staffGuard->currentUid() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        if ($this->staffGuard->isStaff() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    private function respond(callable $operation, ?string $key, int $status) {
        return new JSONResponse([$key => $operation()], $status);
    }
}
"""


class CollaboratorGuardDelegationTest(unittest.TestCase):
    """Pattern 4a — a guard reached through an injected collaborator.

    Regression cover for the decidesk measurement of 2026-08-04: gate-7
    reported 11 findings on ParticipationController /
    ParticipationBudgetController and every one was guarded, because the
    guard lives on ``$this->responder`` rather than in the method body.
    """

    def test_staffAction_delegation_is_recognised_as_guarded(self):
        """$this->responder->staffAction() reaches requireStaff() -> not flagged."""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function transitionBudgetRound(string $budgetId, string $status) {
        return $this->responder->staffAction(
            operation: fn (): array => $this->lifecycleService->transitionBudgetRound($budgetId, $status),
            key: 'budgetRound'
        );
    }
}
"""
        self.assertEqual(_scan_app(src, {"ParticipationResponder": _RESPONDER}), [])

    def test_citizenAction_delegation_is_recognised_as_guarded(self):
        """citizenAction() denies an anonymous caller with 401 -> not flagged."""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function submitProposal(string $budgetId, string $title = '') {
        return $this->responder->citizenAction(
            operation: fn (string $uid): array => $this->budgetService->submitProposal($budgetId, $title, $uid),
            key: 'proposal'
        );
    }
}
"""
        self.assertEqual(_scan_app(src, {"ParticipationResponder": _RESPONDER}), [])

    def test_three_hop_intra_class_chain_to_collaborator_guard(self):
        """validateProposal -> approve/reject -> applyDecision -> staffAction().

        The exact decidesk shape that needed three hops. Transitive closure
        must follow it all the way to the collaborator guard.
        """
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function validateProposal(string $proposalId, ?bool $approve = null) {
        if ($approve === false) {
            return $this->rejectProposal(proposalId: $proposalId);
        }
        return $this->approveProposal(proposalId: $proposalId);
    }

    private function approveProposal(string $proposalId) {
        return $this->applyProposalDecision(proposalId: $proposalId, approve: true);
    }

    private function rejectProposal(string $proposalId) {
        return $this->applyProposalDecision(proposalId: $proposalId, approve: false);
    }

    private function applyProposalDecision(string $proposalId, bool $approve) {
        return $this->responder->staffAction(
            operation: fn (): array => $this->budgetService->validateProposal($proposalId, $approve),
            key: 'proposal'
        );
    }
}
"""
        self.assertEqual(_scan_app(src, {"ParticipationResponder": _RESPONDER}), [])


class CollaboratorGuardStillCatchesRealIdorTest(unittest.TestCase):
    """Pattern 4 must not become a blanket clear — the negative direction.

    Every test here is a shape the gate MUST still flag. Without these, the
    Pattern 4 clear is only evidence about itself: a delegation-following
    gate that follows delegation to *anything* has stopped gating.
    """

    def test_plain_unguarded_method_still_flagged(self):
        """No responder, no helper, no guard, caller-supplied id -> flagged."""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function deleteReaction(string $reactionId) {
        $this->consultationService->deleteReaction($reactionId);
        return new JSONResponse(['ok' => true]);
    }
}
"""
        out = _scan_app(src, {"ParticipationResponder": _RESPONDER})
        self.assertEqual(len(out), 1)
        self.assertIn("method=deleteReaction", out[0])

    def test_collaborator_method_that_is_not_a_guard_still_flagged(self):
        """respond() EXISTS on the responder but performs no authorisation.

        The sharpest control: resolution must discriminate between methods of
        the collaborator, not clear anything called on a property whose class
        happens to contain a guard somewhere.
        """
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function deleteReaction(string $reactionId) {
        return $this->responder->respond(
            fn (): array => $this->consultationService->deleteReaction($reactionId),
            'reaction',
            200
        );
    }
}
"""
        out = _scan_app(src, {"ParticipationResponder": _RESPONDER})
        self.assertEqual(len(out), 1)
        self.assertIn("method=deleteReaction", out[0])

    def test_unresolvable_collaborator_class_clears_nothing(self):
        """A type with no file under lib/ must fail closed, not fail open."""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly MysteryResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function deleteReaction(string $reactionId) {
        return $this->responder->staffAction(
            fn (): array => $this->consultationService->deleteReaction($reactionId)
        );
    }
}
"""
        out = _scan_app(src, {})
        self.assertEqual(len(out), 1)
        self.assertIn("method=deleteReaction", out[0])

    def test_intra_class_chain_ending_in_no_guard_still_flagged(self):
        """A three-hop chain whose terminal method has no guard at all."""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function deleteReaction(string $reactionId) {
        return $this->hopOne(reactionId: $reactionId);
    }

    private function hopOne(string $reactionId) {
        return $this->hopTwo(reactionId: $reactionId);
    }

    private function hopTwo(string $reactionId) {
        $this->consultationService->deleteReaction($reactionId);
        return new JSONResponse(['ok' => true]);
    }
}
"""
        out = _scan_app(src, {"ParticipationResponder": _RESPONDER})
        self.assertEqual(len(out), 1)
        self.assertIn("method=deleteReaction", out[0])

    def test_collaborator_guard_after_the_write_still_flagged(self):
        """The guard must run BEFORE the mutation or it protects nothing."""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ParticipationResponder $responder,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function deleteReaction(string $reactionId) {
        $this->consultationService->deleteReaction($reactionId);
        return $this->responder->staffAction(fn (): array => []);
    }
}
"""
        out = _scan_app(src, {"ParticipationResponder": _RESPONDER})
        self.assertEqual(len(out), 1)
        self.assertIn("method=deleteReaction", out[0])

    def test_bare_throw_does_not_seed_a_delegation_chain(self):
        """A collaborator method that only throws NotFoundException is not a guard.

        `throw` is accepted by the one-hop Pattern-1 helper rule; propagation
        deliberately requires a STRICTER signal, so "this can fail" never
        becomes "this checks who you are" further up the chain.
        """
        thrower = """\
<?php
namespace OCA\\Decidesk\\Service;

class ThingLoader {
    public function load(string $id) {
        if ($id === '') {
            throw new NotFoundException('missing');
        }
        return $this->mapper->find($id);
    }
}
"""
        src = """\
<?php
namespace OCA\\Decidesk\\Controller;

class TestController {
    public function __construct(
        private readonly ThingLoader $loader,
    ) {
    }

    /**
     * @NoAdminRequired
     */
    public function showThing(string $thingId) {
        return new JSONResponse($this->loader->load($thingId));
    }
}
"""
        out = _scan_app(src, {"ThingLoader": thrower})
        self.assertEqual(len(out), 1)
        self.assertIn("method=showThing", out[0])


# ---------------------------------------------------------------------------
# Pattern 5 — the TENANCY guard (ConductionNL/.github#160)
#
# gate-7 was ANTI-CORRELATED with the property it checks on a multi-tenant
# codebase: it stayed red on the correct fix and would have gone green if the
# code were made to leak. The fixtures below are OpenRegister's real shapes.
#
# Both ways, in the same class: the tenancy-scoped service must clear its
# caller, and a service that loads by client-supplied id with NO tenancy
# comparison must still be flagged.
# ---------------------------------------------------------------------------

# The exact shape from OpenRegister's FlowService, comment and all. A flow the
# caller may not see raises the SAME exception as one that does not exist.
_FLOW_SERVICE = """<?php
namespace OCA\\\\OpenRegister\\\\Service;

class FlowService
{
    /**
     * A flow the caller may not see raises the SAME exception as a flow that
     * does not exist. Distinguishing them would turn every read into an
     * oracle for enumerating other tenants' flow ids.
     */
    public function find(string $uuid): Flow
    {
        $flow = $this->mapper->findByUuid($uuid);
        if ($flow->belongsTo($this->activeOrganisation()) === false) {
            throw new DoesNotExistException('No such flow');
        }
        return $flow;
    }

    public function findAll(): array
    {
        $org = $this->activeOrganisation();
        if ($org === null) {
            return [];
        }
        return $this->mapper->findAllForOrganisation($org);
    }
}
"""

# Same surface, NO tenancy comparison: the client-supplied uuid goes straight
# to an unscoped mapper. This is what FlowController::state() actually did
# before it was fixed.
_UNSCOPED_SERVICE = """<?php
namespace OCA\\\\OpenRegister\\\\Service;

class FlowService
{
    public function find(string $uuid): Flow
    {
        return $this->mapper->findByUuid($uuid);
    }

    public function findAll(): array
    {
        return $this->mapper->findAll();
    }
}
"""

_FLOW_CONTROLLER = """<?php
namespace OCA\\\\OpenRegister\\\\Controller;

class TestController extends Controller
{
    private FlowService $flows;

    /**
     * @NoAdminRequired
     */
    public function state(string $uuid) {
        return new JSONResponse($this->flows->find($uuid)->getState());
    }
}
"""


class TenancyGuardTest(unittest.TestCase):
    def test_fp_org_scoped_service_clears_its_caller(self):
        # THE demonstration from #160: FlowController::state() was a real
        # IDOR, was fixed by routing through FlowService::find(), and gate-7
        # reported it identically before and after. It must now clear.
        self.assertEqual(_scan_app(_FLOW_CONTROLLER, {"FlowService": _FLOW_SERVICE}), [])

    def test_tp_the_same_controller_over_an_UNSCOPED_service_is_still_flagged(self):
        # The pairing that proves this is not a mute. Identical controller,
        # identical service NAME and signature — only the tenancy comparison
        # differs, and that is the whole property gate-7 exists to measure.
        out = _scan_app(_FLOW_CONTROLLER, {"FlowService": _UNSCOPED_SERVICE})
        self.assertEqual(len(out), 1)
        self.assertIn("method=state", out[0])

    def test_a_tenancy_comparison_with_no_refusal_is_not_a_guard(self):
        self.assertFalse(cni._has_tenancy_guard(
            "$org = $this->activeOrganisation(); $out = $flow->belongsTo($org); return $flow;"))

    def test_a_refusal_with_no_tenancy_comparison_is_not_a_tenancy_guard(self):
        self.assertFalse(cni._has_tenancy_guard(
            "$flow = $this->mapper->findByUuid($uuid); if ($flow === null) { throw new DoesNotExistException(); } return $flow;"))

    def test_both_halves_together_are_a_guard(self):
        self.assertTrue(cni._has_tenancy_guard(
            "if ($flow->belongsTo($this->activeOrganisation()) === false) { throw new DoesNotExistException(); }"))

    def test_silent_narrowing_to_an_empty_list_counts(self):
        self.assertTrue(cni._has_tenancy_guard(
            "$org = $this->activeOrganisation(); if ($org === null) { return []; }"))

    def test_a_mapper_applying_the_organisation_filter_counts(self):
        self.assertTrue(cni._has_tenancy_guard(
            "$qb = $this->applyOrganisationFilter($qb); if ($rows === []) { return []; }"))

    def test_a_plain_getter_named_getOrganisation_is_not_a_scope_signal(self):
        # `->getOrganisation()` is an ordinary accessor all over the fleet.
        # Only the ACTIVE/CURRENT forms are session-derived, and only a
        # session-derived value makes the comparison an authorisation check.
        self.assertFalse(cni._has_tenancy_guard(
            "$org = $entity->getOrganisation(); if ($org === null) { throw new DoesNotExistException(); }"))


class FullyQualifiedAttributeIsStillTheAttribute(unittest.TestCase):
    """`#[\\OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired]` puts the method IN scope.

    Measured 2026-08-08. The look-back matched `#[NoAdminRequired` only — the
    imported short form. PHP equally permits the fully-qualified spelling, and
    with it a textbook IDOR (caller-supplied `$id`, no ownership check) fell out
    of scope entirely and the gate reported PASS. The byte-identical body under
    the short form reported FAIL.

    No fleet file uses the FQ form today, which is exactly why this needed
    closing deliberately: a false NEGATIVE on a security gate leaves no log, so
    the first FQ attribute anyone writes would have switched the gate off for
    that method silently.
    """

    _LEAK = """<?php
namespace OCA\\Fx\\Controller;
class C extends Controller {
    %s
    public function fetch(string $id): JSONResponse
    {
        $obj = $this->objectService->find(id: $id);
        return new JSONResponse(data: $obj);
    }
}
"""

    _GUARDED = """<?php
namespace OCA\\Fx\\Controller;
class C extends Controller {
    %s
    public function fetch(string $id): JSONResponse
    {
        $obj = $this->objectService->find(id: $id);
        if ($obj->getOwner() !== $this->userSession->getUser()->getUID()) {
            return new JSONResponse(data: [], statusCode: Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(data: $obj);
    }
}
"""

    def _scan(self, php: str) -> list[str]:
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "C.php"
            p.write_text(php, encoding="utf-8")
            buf = io.StringIO()
            with redirect_stdout(buf):
                cni.scan_file(str(p))
        return [ln for ln in buf.getvalue().splitlines() if ln.strip()]

    def test_short_form_leak_is_reported(self):
        self.assertEqual(len(self._scan(self._LEAK % "#[NoAdminRequired]")), 1)

    def test_fully_qualified_leak_is_reported(self):
        php = self._LEAK % "#[\\OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired]"
        self.assertEqual(len(self._scan(php)), 1)

    def test_fully_qualified_guarded_method_is_not_reported(self):
        php = self._GUARDED % "#[\\OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired]"
        self.assertEqual(self._scan(php), [])

    def test_short_form_guarded_method_is_not_reported(self):
        self.assertEqual(self._scan(self._GUARDED % "#[NoAdminRequired]"), [])

    def test_an_unannotated_method_stays_out_of_scope(self):
        # Anti-widening: no NoAdminRequired at all means this gate has no say.
        self.assertEqual(self._scan(self._LEAK % ""), [])


class ZeroInputReadOnlyEndpoints(unittest.TestCase):
    """Pattern 3b (.github#297) — a routed method that takes NO parameters and
    reads NOTHING from the request has no direct object reference for an
    attacker to substitute, so IDOR is structurally impossible. Every
    authenticated caller gets a byte-identical response.

    Before this, the only way to close such a finding was
    `@no-admin-idor-exempt <reason>` — and a reason-tag on a finding that was
    never real is indistinguishable, six months later, from a reason-tag on one
    that was. nldesign left the gate red rather than tag it.

    THE RELAXATION IS BOUNDED TO READS. `_MUTATION_CALL_RE` keeps a zero-input
    side effect reportable, because "the caller names no object" is not a
    reason to let an unguarded `purgeAll()` through — that is the shape this
    argument would otherwise wave past, and it is the abuse control here.
    """

    _TPL = """<?php
class CatalogController {
    #[NoAdminRequired]
    public function %s
}
"""

    def _scan1(self, method_src: str) -> list[str]:
        return _scan(self._TPL % method_src)

    # -- the false positives, gone ----------------------------------------
    def test_fp_a_zero_input_catalogue_read_is_not_an_idor(self):
        # nldesign CatalogController::tokenSets, verbatim.
        self.assertEqual(self._scan1(
            "tokenSets(): JSONResponse\n"
            "    {\n"
            "        return new JSONResponse(['tokenSets' => "
            "$this->tokenSetService->getPublicCatalogue()]);\n"
            "    }"), [])

    def test_fp_a_published_public_key_is_not_an_idor(self):
        # openregister FederatedConfigController::publicKey.
        self.assertEqual(self._scan1(
            "publicKey(): JSONResponse\n"
            "    {\n"
            "        return new JSONResponse(['publicKey' => "
            "$this->service->publicKey()]);\n"
            "    }"), [])

    def test_fp_a_static_event_catalogue_is_not_an_idor(self):
        # openregister FlowController::eventCatalog.
        self.assertEqual(self._scan1(
            "eventCatalog(): JSONResponse\n"
            "    {\n"
            "        $results = $this->eventCatalog->getCatalog();\n"
            "        return new JSONResponse(['results' => $results, "
            "'total' => count($results)]);\n"
            "    }"), [])

    # -- THE ABUSE CONTROL: reads only ------------------------------------
    def test_abuse_control_a_zero_input_MUTATION_is_still_reported(self):
        # No parameters, no request reads — and it deletes everything. If
        # Pattern 3b ever drops its read-only condition, this goes quiet.
        out = self._scan1(
            "purgeAll(): JSONResponse\n"
            "    {\n"
            "        $this->objectService->deleteAll();\n"
            "        return new JSONResponse(['purged' => true]);\n"
            "    }")
        self.assertEqual(len(out), 1, out)
        self.assertIn("method=purgeAll", out[0])

    def test_abuse_control_a_zero_input_reset_is_still_reported(self):
        out = self._scan1(
            "resetSettings(): JSONResponse\n"
            "    {\n"
            "        $this->settingsService->resetToDefaults();\n"
            "        return new JSONResponse(['ok' => true]);\n"
            "    }")
        self.assertEqual(len(out), 1, out)
        self.assertIn("method=resetSettings", out[0])

    # -- THE ABUSE CONTROL THAT CAUGHT THE FIRST DRAFT --------------------
    # Pattern 3b's first draft cleared any zero-input READ. These two shapes
    # are why that was wrong, and six existing tests failed on it. They are
    # restated here so the reason travels with the pattern rather than living
    # only in the classes that happened to use them as fixtures.
    def test_abuse_control_a_zero_input_findAll_is_still_reported(self):
        out = self._scan1(
            "listEverything(): JSONResponse\n"
            "    {\n"
            "        return new JSONResponse($this->svc->findAll());\n"
            "    }")
        self.assertEqual(len(out), 1, out)
        self.assertIn("method=listEverything", out[0])

    def test_abuse_control_a_zero_input_mapper_read_is_still_reported(self):
        out = self._scan1(
            "index(): JSONResponse\n"
            "    {\n"
            "        $data = $this->mapper->findAll();\n"
            "        return new JSONResponse($data);\n"
            "    }")
        self.assertEqual(len(out), 1, out)
        self.assertIn("method=index", out[0])

    # -- THE TRUE POSITIVES THIS MUST NOT SWALLOW -------------------------
    def test_tp_a_method_taking_an_id_is_still_reported(self):
        # The moment the caller names an object, IDOR is possible again.
        out = self._scan1(
            "show(string $id): JSONResponse\n"
            "    {\n"
            "        return new JSONResponse($this->service->find($id));\n"
            "    }")
        self.assertEqual(len(out), 1, out)
        self.assertIn("method=show", out[0])

    def test_tp_a_zero_param_method_that_READS_THE_REQUEST_is_still_reported(self):
        # Zero declared parameters is not the same as zero caller input —
        # `getParam` is the other door, and it is exactly what IDOR walks in
        # through.
        out = self._scan1(
            "show(): JSONResponse\n"
            "    {\n"
            "        $id = $this->request->getParam('id');\n"
            "        return new JSONResponse($this->service->find($id));\n"
            "    }")
        self.assertEqual(len(out), 1, out)
        self.assertIn("method=show", out[0])


class VerbObjectGuardHelperNames(unittest.TestCase):
    """A guard helper may spell its object noun AFTER the auth token.

    ``_GUARD_HELPER_NAME_RE``'s first alternative used to require the auth
    token (``Access``/``Permission``/``Owner``/…) to be the FINAL CamelCase
    segment, so ``canAccess`` matched but ``canUserAccessAgent`` did not. That
    rejected the very common verb-object spelling and made gate-7 blind to
    genuine authorisation predicates, reporting every method that delegates to
    one as an unguarded IDOR.

    MEASURED: ConductionNL/hermiq @ development (cd23f547), full-scope run
    31490144919 / job 93776678440 — gate-7 FAIL, 3 methods, all three false
    positives of exactly the two shapes below. Gate-7 was proven NOT blind on
    that repo first: a textbook IDOR planted into the TRACKED file
    ``lib/Controller/AgentVersionController.php`` took the count 3 → 4.

    AN AUTH TOKEN IS STILL REQUIRED — only its POSITION is relaxed. The
    negative controls at the bottom are the abuse control: ``canRender`` /
    ``hasChanges`` carry no auth token in ANY position and must still be
    reported. The unguarded-fetch positive control for this shape already
    exists twice and is deliberately not duplicated here:
    ``RealIdorViolationTest.test_no_guard_at_all_is_flagged`` (docblock form)
    and ``ZeroInputReadOnlyEndpoints.test_tp_a_method_taking_an_id_is_still_reported``
    (attribute form).
    """

    # -- SHAPE A: in-body per-object filter through a verb-object predicate --
    #
    # NOTE ON THE FIXTURE. The pagination read (``$this->request->getParams()``)
    # is load-bearing, not decoration: without it the method takes no caller
    # input at all and the session-scoped / zero-reference exemption clears it
    # before the guard-helper pattern is ever consulted — the first draft of
    # this test passed identically with the OLD regex for that reason. The real
    # hermiq method reads pagination params, so the reference is real and the
    # only thing standing between it and a finding is the helper's NAME. The
    # two abuse controls below pin that: drop the helper, or rename it to a
    # name with no auth token, and the finding comes back.
    _SHAPE_A = """\
<?php
class AgentsController {
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $userId = (string) $this->userSession->getUser()?->getUID();
        $params = $this->request->getParams();
        $limit  = (int) ($params['_limit'] ?? 50);
        $offset = (int) ($params['_offset'] ?? 0);

        $agents = $this->objectService->findAll(
            config: ['limit' => $limit, 'offset' => $offset]
        );

        $results = [];
        foreach ($agents as $agent) {
            if ($this->%s(agent: $agent, userId: $userId) === true) {
                $results[] = $agent->getObject();
            }
        }

        return new JSONResponse(data: ['results' => $results]);
    }
%s
}
"""

    _SHAPE_A_HELPER = """
    private function %s(ObjectEntity $agent, string $userId): bool
    {
        $data = $agent->getObject();
        if (($data['isPrivate'] ?? null) === false) {
            return true;
        }
        if ($agent->getOwner() === $userId) {
            return true;
        }
        return in_array($userId, ($data['invitedUsers'] ?? []), true);
    }
"""

    def test_in_body_verb_object_predicate_clears_caller(self):
        """hermiq AgentsController::index — every result filtered in-body."""
        src = self._SHAPE_A % (
            "canUserAccessAgent",
            self._SHAPE_A_HELPER % "canUserAccessAgent",
        )
        self.assertEqual(_scan(src), [])

    def test_shape_a_without_the_helper_is_still_reported(self):
        """Abuse control: the same body with NO helper defined stays a finding."""
        findings = _scan(self._SHAPE_A % ("canUserAccessAgent", ""))
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=index", findings[0])

    def test_shape_a_with_a_tokenless_helper_name_is_still_reported(self):
        """Abuse control: rename the helper to a name carrying no auth token
        and the finding returns — the NAME is what clears it, nothing else."""
        findings = _scan(
            self._SHAPE_A
            % ("canRenderAgent", self._SHAPE_A_HELPER % "canRenderAgent")
        )
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=index", findings[0])

    # -- SHAPE B: Pattern-4 transitive closure through a loader --------------
    def test_loader_delegating_to_verb_object_predicate_clears_caller(self):
        """hermiq AgentVersionController::index / ::diff — routed method →
        ``loadAccessibleAgent()`` → ``canUserAccessAgent()``, caller returns
        ``Http::STATUS_NOT_FOUND`` on null (the 404-style tenancy refusal this
        gate's own FAIL message endorses). Exercises the transitive closure:
        the loader itself has no auth token in its name and no strict deny
        signal in its body — it is guard-bearing only because it CALLS one.
        """
        src = """\
<?php
class AgentVersionController {
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $id): JSONResponse
    {
        $userId = (string) $this->userSession->getUser()?->getUID();

        $agent = $this->loadAccessibleAgent(id: $id, userId: $userId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        $versions = $this->agentVersionService->listVersions(agentUuid: $id);
        return new JSONResponse(['results' => $versions, 'total' => count($versions)]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function diff(string $id): JSONResponse
    {
        $userId = (string) $this->userSession->getUser()?->getUID();

        $agent = $this->loadAccessibleAgent(id: $id, userId: $userId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        $from = (string) $this->request->getParam('from', '');
        $to   = (string) $this->request->getParam('to', '');
        return new JSONResponse($this->agentVersionService->diff($id, $from, $to));
    }

    private function loadAccessibleAgent(string $id, string $userId): ?ObjectEntity
    {
        $agent = $this->objectService->find(id: $id);
        if (($agent instanceof ObjectEntity) === false) {
            return null;
        }

        if ($this->canUserAccessAgent(agent: $agent, userId: $userId) === false) {
            return null;
        }

        return $agent;
    }

    private function canUserAccessAgent(ObjectEntity $agent, string $userId): bool
    {
        $data = $agent->getObject();
        if (($data['isPrivate'] ?? null) === false) {
            return true;
        }
        if ($agent->getOwner() === $userId) {
            return true;
        }
        return in_array($userId, ($data['invitedUsers'] ?? []), true);
    }
}
"""
        self.assertEqual(_scan(src), [])

    # -- NEGATIVE CONTROLS: no auth token in ANY position -------------------
    def test_canRender_helper_does_not_clear_caller(self):
        """``canRender`` has the can- prefix but no auth token — still flagged."""
        src = """\
<?php
class WidgetController {
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $widget = $this->objectService->find($id);
        if ($this->canRender($widget) === false) {
            return new JSONResponse(['results' => []]);
        }
        return new JSONResponse($widget);
    }

    private function canRender(ObjectEntity $widget): bool
    {
        return ($widget->getObject()['template'] ?? null) !== null;
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=show", findings[0])

    def test_hasChanges_helper_does_not_clear_caller(self):
        """``hasChanges`` has the has- prefix but no auth token — still flagged."""
        src = """\
<?php
class DraftController {
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $draft = $this->objectService->find($id);
        if ($this->hasChanges($draft) === true) {
            $this->objectService->saveObject($draft);
        }
        return new JSONResponse($draft);
    }

    private function hasChanges(ObjectEntity $draft): bool
    {
        return $draft->getUpdated() > $draft->getCreated();
    }
}
"""
        findings = _scan(src)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("method=update", findings[0])

    def test_verb_object_name_without_an_auth_token_is_not_a_guard_name(self):
        """The regex itself: a token is REQUIRED, only its position is free."""
        matches = cni._GUARD_HELPER_NAME_RE.match
        # Auth token in a trailing-object position — now accepted.
        self.assertTrue(matches("canUserAccessAgent"))
        self.assertTrue(matches("hasOwnerPermissionForRun"))
        self.assertTrue(matches("mayUserAdminTenant"))
        # No auth token anywhere — still rejected.
        self.assertFalse(matches("canRender"))
        self.assertFalse(matches("hasChanges"))
        self.assertFalse(matches("canUserModifyAgent"))
        self.assertFalse(matches("hasPendingRevision"))

    def test_the_auth_token_may_be_the_first_segment(self):
        """`.github#360` — the SHORT idiomatic guard names.

        `#353` relaxed WHERE the auth token may sit but left the segment
        before it mandatory, so the token could never come first.  The most
        conventional per-object guard names in the fleet are exactly that
        shape, and gate-7 reported every method delegating to one as an
        unguarded IDOR — before AND after `#353`.  Measured end-to-end in
        `test_gate7_verb_object_guards.sh`: a controller guarded by
        `hasPermission()` and `canAccess()` produced 2 findings, and produces
        0 now, while the same fixture still goes red under the pre-#360 regex.
        """
        matches = cni._GUARD_HELPER_NAME_RE.match
        for name in (
            "hasPermission", "canAccess", "isOwner", "isAllowed",
            "mayAccess", "hasAccess", "isPermitted", "canAccessAgentForUser",
        ):
            self.assertTrue(matches(name), f"{name} should be a guard name")

    def test_360_did_not_widen_into_silence(self):
        """The abuse control for `#360`: no token, no guard.

        Making the pre-token segment optional must not turn the pattern into
        "any is/has/can/may method".  If it had, gate-7 would clear real
        IDORs — the failure mode that is strictly worse than the false
        positives `#360` removes.
        """
        matches = cni._GUARD_HELPER_NAME_RE.match
        for name in (
            "canRender", "hasChanges", "isVisible", "canDelete", "hasItems",
            "isReady", "mayRetry", "canUserModifyAgent", "hasPendingRevision",
        ):
            self.assertFalse(matches(name), f"{name} must NOT be a guard name")


if __name__ == "__main__":
    unittest.main()
