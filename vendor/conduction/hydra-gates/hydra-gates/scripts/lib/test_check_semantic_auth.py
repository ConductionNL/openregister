#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_semantic_auth (gate-9). Run with:

    python3 scripts/lib/test_check_semantic_auth.py

WHY THIS SUITE EXISTS
---------------------
gate-9 shipped with NO tests. Its `public-page-annotation-with-auth-body`
rule produced 39 fleet findings of which 36 were false, and its remediation
text — *"remove `#[PublicPage]` or remove body auth check"* — would have
INTRODUCED a vulnerability in every one of those 36: the first half breaks
the endpoint (Nextcloud middleware rejects the remote caller before the
controller runs), the second half deletes its only authentication.

The fixtures below are the real call shapes, verbatim in structure:
openconnector's HMAC webhook, portaliq's portal `subject()` inbox,
openregister's bearer-share-token federation reader — against decidesk's
actual defect, a `#[PublicPage]` method that tests the SESSION.

Both ways, in the same class: the self-authenticating shapes must go quiet,
and the session-dependent shape must still fire.

2026-08-07 — three more defects, and the classes guarding them:

* :class:`AdminGuardsWithArguments` — the `#[NoAdminRequired]` rule matched
  `isAdmin` only through `[^)]*`, and `isAdmin($uid) === false` puts a `)`
  between the two. It had therefore never matched a real guard: 25 findings
  across 10 repos were invisible while the gate reported PASS.
* :class:`ProseDoesNotEarnTheExemption` — the self-auth exemption was
  matched against raw source, so a docblock describing a credential check
  exempted a method performing none. The gate-64 failure mode.
* :class:`StringLiteralsStillCount` — and the fix for the above must NOT
  extend to string literals. `'Bearer '`, `'HTTP_AUTHORIZATION'` and the
  header name passed to getHeader() are literals in every real handler.
  These two classes are each other's control.
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
import check_semantic_auth as csa  # noqa: E402


def _scan(php: str) -> list[str]:
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "Controller.php"
        p.write_text(php, encoding="utf-8")
        buf = io.StringIO()
        with redirect_stdout(buf):
            csa.scan_file(str(p))
    return [ln for ln in buf.getvalue().splitlines() if ln.strip()]


def _rules(findings: list[str]) -> list[str]:
    return [f.split("rule=", 1)[1].split(" ", 1)[0] for f in findings]


CLASS = """<?php
namespace OCA\\Thing\\Controller;

class ThingController extends Controller
{
%s
}
"""

HMAC_WEBHOOK = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function webhook(): JSONResponse
    {
        $signature = $this->request->getHeader('X-Mollie-Signature');
        $expected = hash_hmac('sha256', $this->request->getContent(), $this->secret);
        if (hash_equals($expected, $signature) === false) {
            return new JSONResponse(['error' => 'bad signature'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['ok' => true]);
    }
"""

PORTAL_INBOX = CLASS % """
    #[PublicPage]
    public function inbox(): JSONResponse
    {
        $subject = $this->portal->subject();
        if ($subject === null) {
            return new JSONResponse(['error' => 'no portal session'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->service->listFor($subject));
    }
"""

FEDERATION_BEARER = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function objects(string $shareToken): JSONResponse
    {
        $share = $this->shares->findByToken($shareToken);
        if ($share === null || $share->isRevoked()) {
            return new JSONResponse(['error' => 'unknown share'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($this->service->objectsFor($share));
    }
"""

# decidesk's real defect shape: annotated public, but the body tests the
# SESSION — a test that can only ever fail for the callers the annotation
# admits.
SESSION_DEPENDENT = CLASS % """
    #[PublicPage]
    public function load(): JSONResponse
    {
        $this->requireAdmin();
        return new JSONResponse($this->settings->all());
    }
"""

SESSION_NULL_CHECK = CLASS % """
    #[PublicPage]
    public function mine(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->service->forCurrentUser());
    }
"""

# 401 with no credential source anywhere: the shape that remains worth
# reporting, because nothing in the method says what it authenticates with.
UNSOURCED_DENIAL = CLASS % """
    #[PublicPage]
    public function report(): JSONResponse
    {
        if ($this->flags->closed()) {
            return new JSONResponse(['error' => 'closed'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($this->service->report());
    }
"""


class SelfAuthenticatingPublicEndpoints(unittest.TestCase):
    """The 36 false positives."""

    def test_fp_hmac_signed_webhook_is_not_a_finding(self):
        self.assertEqual(_scan(HMAC_WEBHOOK), [])

    def test_fp_portal_subject_resolution_is_not_a_finding(self):
        self.assertEqual(_scan(PORTAL_INBOX), [])

    def test_fp_bearer_share_token_is_not_a_finding(self):
        self.assertEqual(_scan(FEDERATION_BEARER), [])


class SessionDependentPublicPage(unittest.TestCase):
    """The 3 true positives — these MUST still fire."""

    def test_tp_require_admin_under_public_page_still_fires(self):
        self.assertEqual(_rules(_scan(SESSION_DEPENDENT)),
                         ["public-page-annotation-with-session-auth-body"])

    def test_tp_user_session_null_check_under_public_page_still_fires(self):
        self.assertEqual(_rules(_scan(SESSION_NULL_CHECK)),
                         ["public-page-annotation-with-session-auth-body"])

    def test_tp_a_session_check_fires_even_alongside_a_token(self):
        # Self-authentication excuses an UNSOURCED denial, never a session
        # test. A method that reads a token AND requires a session is still
        # contradicting its own annotation.
        php = CLASS % """
    #[PublicPage]
    public function mixed(string $shareToken): JSONResponse
    {
        $share = $this->shares->findByToken($shareToken);
        $this->requireAdmin();
        return new JSONResponse($share);
    }
"""
        self.assertEqual(_rules(_scan(php)),
                         ["public-page-annotation-with-session-auth-body"])

    def test_tp_a_denial_with_no_credential_source_is_reported(self):
        self.assertEqual(_rules(_scan(UNSOURCED_DENIAL)),
                         ["public-page-annotation-with-unsourced-denial"])


class RemediationTextIsSafe(unittest.TestCase):
    """The advice itself is part of the gate. If it tells a developer to open
    the endpoint, the gate is a vulnerability generator regardless of its
    precision. These assertions are on the STRING, deliberately."""

    def _advice(self, php: str) -> str:
        found = _scan(php)
        self.assertTrue(found, "expected a finding to inspect the advice of")
        return found[0]

    def test_advice_never_says_to_remove_the_public_page_annotation(self):
        for php in (SESSION_DEPENDENT, SESSION_NULL_CHECK, UNSOURCED_DENIAL):
            with self.subTest(php=php[:60]):
                advice = self._advice(php)
                self.assertNotIn("remove #[PublicPage] or", advice)

    def test_advice_never_says_to_remove_the_auth_check(self):
        for php in (SESSION_DEPENDENT, SESSION_NULL_CHECK, UNSOURCED_DENIAL):
            with self.subTest(php=php[:60]):
                advice = self._advice(php)
                self.assertNotIn("remove body auth check", advice)
                self.assertIn("Do NOT", advice)

    def test_advice_names_the_request_borne_alternative(self):
        advice = self._advice(SESSION_DEPENDENT)
        self.assertIn("route token", advice)


class NoAdminRequiredRuleUnchanged(unittest.TestCase):
    """The rule gate-9 was actually built for. It was RIGHT — 3 of 3 real —
    and nothing above may weaken it."""

    def test_tp_no_admin_required_with_an_admin_body_still_fires(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function load(): JSONResponse
    {
        $this->requireAdmin();
        return new JSONResponse($this->settings->all());
    }
"""
        self.assertEqual(_rules(_scan(php)),
                         ["no-admin-required-annotation-with-admin-body"])

    def test_fp_a_plain_no_admin_required_method_is_clean(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse($this->service->listForCurrentUser());
    }
"""
        self.assertEqual(_scan(php), [])


class AdminGuardsWithArguments(unittest.TestCase):
    """`isAdmin()` takes a UID, and the rule could not read past the `)`.

    `\\bisAdmin\\b[^)]*===\\s*false` cannot span `isAdmin($this->userId)`,
    which is how the guard is written everywhere. The rule above
    (``NoAdminRequiredRuleUnchanged``) passed only because its fixture calls
    `requireAdmin()`, matched by a different pattern — so the whole
    `if (...isAdmin...) { deny }` branch had never fired on real code.
    """

    def test_tp_is_admin_with_a_uid_argument_fires(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function trust(): JSONResponse
    {
        if ($this->groupManager->isAdmin($this->userId) === false) {
            return new JSONResponse(['error' => 'admins only'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($this->service->getTrustConfig());
    }
"""
        self.assertEqual(_rules(_scan(php)),
                         ["no-admin-required-annotation-with-admin-body"])

    def test_tp_yoda_comparison_fires(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function purge(): JSONResponse
    {
        if (false === $this->groupManager->isAdmin($this->userId)) {
            throw new OCSForbiddenException('admins only');
        }
        return new JSONResponse($this->cache->purgeAll());
    }
"""
        self.assertEqual(_rules(_scan(php)),
                         ["no-admin-required-annotation-with-admin-body"])

    def test_fp_a_non_admin_predicate_is_not_a_finding(self):
        # The guard has to be about being an admin, not merely an `if` that
        # returns 403. Loosening the condition matcher must not turn every
        # domain check into an attribute mismatch.
        php = CLASS % """
    #[NoAdminRequired]
    public function publish(int $id): JSONResponse
    {
        if ($this->publications->isReviewed($id) === false) {
            return new JSONResponse(['error' => 'not reviewed yet'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($this->publications->publish($id));
    }
"""
        self.assertEqual(_scan(php), [])


class PasswordsAreCredentialsToo(unittest.TestCase):
    """A login endpoint is the self-authenticating shape with a password.

    openconnector UserController::login: #[PublicPage] by necessity — the
    caller has no session yet, that is what it is asking for — resolving
    $username/$password from the request and calling checkPassword() in the
    body, reported as an unsourced denial by a token-only pattern list.
    """

    def test_fp_login_is_not_a_finding(self):
        php = CLASS % """
    #[NoCSRFRequired]
    #[PublicPage]
    public function login(): JSONResponse
    {
        $data        = $this->request->getParams();
        $credentials = $this->security->validateLoginCredentials($data);
        $username    = $credentials['username'];
        $password    = $credentials['password'];

        $user = $this->userManager->checkPassword($username, $password);
        if ($user === false) {
            $this->security->recordFailedLoginAttempt($username, $this->clientIp());
            return new JSONResponse(['error' => 'invalid credentials'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['uid' => $user->getUID()]);
    }
"""
        self.assertEqual(_scan(php), [])

    def test_fp_password_protected_share_is_not_a_finding(self):
        php = CLASS % """
    #[PublicPage]
    public function unlock(string $slug): JSONResponse
    {
        $folder = $this->folders->findBySlug($slug);
        if (password_verify((string) $this->request->getParam('password'), $folder->getPasswordHash()) === false) {
            return new JSONResponse(['error' => 'wrong password'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->folders->listing($folder));
    }
"""
        self.assertEqual(_scan(php), [])

    def test_fp_a_helper_resolved_token_passed_by_name_is_not_a_finding(self):
        # hermiq McpRunController::handle / EgressAuthorizeController::authorize.
        # The credential comes from a helper (`bearerToken()`) and is handed
        # over as a named argument (`token:`). Neither is one of the verbs the
        # pattern list started with — the ONLY thing that used to match was the
        # word "bearer" in the method's own comments, so this pair is what
        # would silently break if comment-stripping landed on its own.
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function handle(): JSONResponse
    {
        $binding = $this->runTokenService->verify(token: $this->bearerToken());
        if ($binding === null) {
            return new JSONResponse(['error' => 'invalid_token'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse($this->mcp->dispatch($binding, $this->readRawBody()));
    }
"""
        self.assertEqual(_scan(php), [])


class ProseDoesNotEarnTheExemption(unittest.TestCase):
    """Only executable code counts as authenticating a credential.

    The gate-64 shape: a checker that reads comments will accept a
    commented-out call as a real one, and a docblock as a check.
    """

    def test_tp_a_docblock_describing_a_token_check_still_fires(self):
        php = CLASS % """
    /**
     * Download an export.
     *
     * Callers must present a signed capability token in the Authorization
     * header; it is compared against the stored secret with hash_equals()
     * before any payload is returned.
     */
    #[PublicPage]
    public function download(int $id): JSONResponse
    {
        if ($this->exports->isPublished($id) === false) {
            return new JSONResponse(['error' => 'not available'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse($this->exports->payload($id));
    }
"""
        self.assertEqual(_rules(_scan(php)),
                         ["public-page-annotation-with-unsourced-denial"])

    def test_tp_a_commented_out_credential_check_still_fires(self):
        php = CLASS % """
    #[PublicPage]
    public function receive(): JSONResponse
    {
        // TODO re-enable once the partner rotates their key:
        // $presentedToken = (string) $this->request->getHeader('X-Api-Key');
        // if (hash_equals($this->expectedKey(), $presentedToken) === false) {
        if ($this->imports->isAcceptingUploads() === false) {
            return new JSONResponse(['error' => 'closed'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse([], 202);
    }
"""
        self.assertEqual(_rules(_scan(php)),
                         ["public-page-annotation-with-unsourced-denial"])


class StringLiteralsStillCount(unittest.TestCase):
    """The control on ProseDoesNotEarnTheExemption.

    Comments go; literals stay. `'Bearer '`, `'HTTP_AUTHORIZATION'` and the
    header name handed to getHeader() live in literals in every real
    handler, so blanking them would manufacture exactly the false positives
    this gate was rewritten to stop.
    """

    def test_fp_a_bearer_prefix_stripped_from_a_literal_is_not_a_finding(self):
        php = CLASS % """
    #[PublicPage]
    public function ingest(): JSONResponse
    {
        $header    = (string) $this->request->getHeader('Authorization');
        $presented = str_replace('Bearer ', '', $header);

        if ($this->apiKeys->authorize($presented) === false) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse([], 202);
    }
"""
        self.assertEqual(_scan(php), [])

    def test_fp_the_server_superglobal_header_key_is_not_a_finding(self):
        php = CLASS % """
    #[PublicPage]
    public function ping(): JSONResponse
    {
        $presented = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (hash_equals($this->expectedKey(), (string) $presented) === false) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['pong' => true]);
    }
"""
        self.assertEqual(_scan(php), [])

    def test_fp_a_double_slash_inside_a_url_does_not_swallow_the_method(self):
        # If `//` in a literal were read as a comment start, everything after
        # it — including the credential check — would be blanked and the
        # method would be reported as denying on nothing.
        php = CLASS % """
    #[PublicPage]
    public function callback(): JSONResponse
    {
        $issuer    = 'https://idp.example.org/realms/demo';
        $presented = (string) $this->request->getHeader('Authorization');

        if ($this->oidc->verifyIdToken($presented, $issuer) === false) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['issuer' => $issuer]);
    }
"""
        self.assertEqual(_scan(php), [])


class GateIsNotBlind(unittest.TestCase):
    def test_the_scanner_still_reads_methods_at_all(self):
        # If `_find_method_bodies` ever returns nothing, every `assertEqual([])`
        # above passes. This asserts the floor directly.
        php = CLASS % """
    #[PublicPage]
    public function a(): JSONResponse { $this->requireAdmin(); return new JSONResponse([]); }

    #[PublicPage]
    public function b(): JSONResponse { $this->requireAdmin(); return new JSONResponse([]); }
"""
        self.assertEqual(len(_scan(php)), 2)


class CredentialResolvedOneFrameDown(unittest.TestCase):
    """ConductionNL/.github#221 — where the credential is resolved.

    36 of gate-9's 39 fleet findings were false, and this class covers the
    two shapes the token list still could not see. Both are correct code and
    both had no closable remediation.

    Every method here is one half of a control pair: for each shape that must
    go quiet there is a sibling differing by the ONE thing the widening
    accepts, and that sibling must still fire. Widening a rule that guards an
    authorisation surface is the expensive direction, so the anti-widening
    halves are the point of the class, not an afterthought.
    """

    # -- the session IS a credential in the request ------------------------
    def test_fp_progressive_session_then_policy_denial_is_not_a_finding(self):
        """doriath ApplicationController::create, structurally verbatim.

        `#[PublicPage]` because the endpoint accepts anonymous registration
        WHEN THE ADMIN HAS OPTED IN. The 401 is reached only for a caller
        with no session on an instance that has not opted in — a stated
        policy, not a check against something the annotation forbids.
        """
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $user = $this->session->getUser();
        if ($user === null) {
            $anonEnabled = $this->appConfig->getValueString('doriath', 'anon_enabled', '0');
            if ($anonEnabled !== '1') {
                return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
            }
        }
        return new JSONResponse(['ok' => true]);
    }
"""
        self.assertEqual(_scan(php), [])

    def test_tp_a_denial_off_a_config_read_alone_still_fires(self):
        """The anti-widening half of the test above.

        Identical but for the ONE line that resolves an identity. Nothing
        here authenticates anybody: the endpoint is public and 401s on a
        feature flag. That is the shape the rule exists for and it must
        survive making the session count.
        """
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $anonEnabled = $this->appConfig->getValueString('doriath', 'anon_enabled', '0');
        if ($anonEnabled !== '1') {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['ok' => true]);
    }
"""
        self.assertEqual(_rules(_scan(php)), ["public-page-annotation-with-unsourced-denial"])

    # -- one frame down, in a private helper --------------------------------
    def test_fp_credential_resolved_in_a_private_helper_is_not_a_finding(self):
        """A controller that authenticates several actions the same way writes
        the resolution ONCE. Extracting that helper is the refactor every
        other gate in this suite asks for; it must not manufacture a finding.
        """
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['ok' => true]);
    }

    private function resolveUserId(): ?string
    {
        return $this->session->getUser()?->getUID();
    }
"""
        self.assertEqual(_scan(php), [])

    def test_fp_a_helper_that_verifies_a_bearer_token_is_not_a_finding(self):
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function inbox(): JSONResponse
    {
        if ($this->authenticateCaller() === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse(['ok' => true]);
    }

    private function authenticateCaller(): bool
    {
        $header = $this->request->getHeader('Authorization');
        return hash_equals($this->expected, substr($header, 7));
    }
"""
        self.assertEqual(_scan(php), [])

    def test_tp_a_helper_that_resolves_no_credential_still_fires(self):
        """Anti-widening: calling a helper is not itself evidence.

        Same delegation shape as the two above; the helper reads a config
        value. If merely *having* a sibling call earned the exemption, this
        would go quiet — and the rule would be closable by extracting any
        method at all.
        """
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(): JSONResponse
    {
        if ($this->featureEnabled() === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse(['ok' => true]);
    }

    private function featureEnabled(): bool
    {
        return $this->appConfig->getValueString('thing', 'enabled', '0') === '1';
    }
"""
        self.assertEqual(_rules(_scan(php)), ["public-page-annotation-with-unsourced-denial"])

    def test_tp_the_walk_is_one_frame_and_does_not_go_transitive(self):
        """The documented bound, pinned.

        The credential sits TWO calls away. Following the call graph to
        arbitrary depth would eventually reach a service that touches a token
        for unrelated reasons and exempt everything, so depth 1 is deliberate
        — and a deliberate bound that no test asserts is a bound that will be
        removed by the next person who reads the regex.
        """
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(): JSONResponse
    {
        if ($this->firstFrame() === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse(['ok' => true]);
    }

    private function firstFrame(): bool
    {
        return $this->secondFrame();
    }

    private function secondFrame(): bool
    {
        return hash_equals($this->expected, $this->request->getHeader('X-Signature'));
    }
"""
        self.assertEqual(_rules(_scan(php)), ["public-page-annotation-with-unsourced-denial"])

    # -- rule 1 is untouched -----------------------------------------------
    def test_tp_require_admin_under_public_page_is_unaffected_by_the_widening(self):
        """`getUser()` now counts as a credential source. That must not reach
        rule 1, which is tested FIRST and owns the genuine contradiction:
        #[PublicPage] admits a caller with no login, and requireAdmin() can
        only ever reject that caller. decidesk#44, the defect the gate was
        built for.
        """
        php = CLASS % """
    #[PublicPage]
    public function load(): JSONResponse
    {
        $this->requireAdmin();
        $user = $this->userSession->getUser();
        return new JSONResponse(['user' => $user]);
    }
"""
        self.assertEqual(_rules(_scan(php)), ["public-page-annotation-with-session-auth-body"])

    def test_tp_user_session_null_check_is_unaffected_by_the_widening(self):
        php = CLASS % """
    #[PublicPage]
    public function load(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
        }
        return new JSONResponse(['ok' => true]);
    }
"""
        self.assertEqual(_rules(_scan(php)), ["public-page-annotation-with-session-auth-body"])

    def test_tp_prose_in_a_helper_docblock_does_not_earn_the_exemption(self):
        """The gate-64 failure mode, one frame down. The helper is inlined
        into the credential surface, and that surface is comment-stripped
        before the question is asked — so a docblock describing a token check
        the helper does not perform still fires.
        """
        php = CLASS % """
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(): JSONResponse
    {
        if ($this->allowed() === false) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse(['ok' => true]);
    }

    /**
     * Verifies the caller's bearer token against the stored HMAC signature.
     */
    private function allowed(): bool
    {
        return $this->appConfig->getValueString('thing', 'enabled', '0') === '1';
    }
"""
        self.assertEqual(_rules(_scan(php)), ["public-page-annotation-with-unsourced-denial"])


class AdminEscalationBranchIsNotAnAdminRequirement(unittest.TestCase):
    """`!isAdmin` guarding a TIGHTER per-owner check is not "admin required".

    Measured 2026-08-08 on docudesk `SigningController::cancelRequest` and
    procest `InspectionChecklistController::submitResult`. Both carry
    `@NoAdminRequired` CORRECTLY and both were reported, because the rule
    searched the `!isAdmin` block for a denial token AT ANY DEPTH.

    The two shapes are semantically opposite:

        if (!isAdmin) { return 403; }          admin IS required  -> finding
        if (!isAdmin) { if (!owner) 403; }     admin NOT required  -> clean

    In the second, a non-admin OWNER proceeds. The remedy gate-9 printed for it
    — remove `@NoAdminRequired`, or switch to `#[AuthorizedAdminSetting]` —
    would have made a per-user endpoint admin-only, locking out the very users
    it exists for, and deleted the owner check's reason to exist.
    """

    def test_immediate_denial_under_not_admin_is_still_a_finding(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function purge(string $id): JSONResponse
    {
        if ($this->groupManager->isAdmin($this->userId) === false) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse([]);
    }
"""
        self.assertEqual(
            _rules(_scan(php)), ["no-admin-required-annotation-with-admin-body"]
        )

    def test_docudesk_escalation_branch_is_not_a_finding(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function cancelRequest(string $id): JSONResponse
    {
        $uid     = $this->userSession->getUser()->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);
        if ($isAdmin === false) {
            $request = $this->signingService->getRequest(requestId: $id);
            if (($request['initiatorUserId'] ?? '') !== $uid) {
                return new JSONResponse([], Http::STATUS_FORBIDDEN);
            }
        }

        return new JSONResponse($this->signingService->cancelRequest(requestId: $id));
    }
"""
        self.assertEqual(_rules(_scan(php)), [])

    def test_procest_escalation_branch_with_a_throw_is_not_a_finding(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function submitResult(string $id): JSONResponse
    {
        $uid = $this->userSession->getUser()->getUID();
        if ($this->groupManager->isAdmin($uid) === false) {
            $assignedUid = $this->request->getParams()['assignedInspector'] ?? '';
            if ($assignedUid !== '' && $assignedUid !== $uid) {
                throw new OCSForbiddenException('Not authorized');
            }
        }

        return new JSONResponse([]);
    }
"""
        self.assertEqual(_rules(_scan(php)), [])

    def test_a_try_body_still_counts_as_unconditional(self):
        # A `try` body executes whenever the block is entered, unlike an `if`.
        php = CLASS % """
    #[NoAdminRequired]
    public function purge(string $id): JSONResponse
    {
        if ($this->groupManager->isAdmin($this->userId) === false) {
            try {
                return new JSONResponse([], Http::STATUS_FORBIDDEN);
            } finally {
                $this->logger->info('denied');
            }
        }

        return new JSONResponse([]);
    }
"""
        self.assertEqual(
            _rules(_scan(php)), ["no-admin-required-annotation-with-admin-body"]
        )

    def test_requireAdmin_is_unaffected_by_the_narrowing(self):
        php = CLASS % """
    #[NoAdminRequired]
    public function load(): JSONResponse
    {
        $this->requireAdmin();
        return new JSONResponse([]);
    }
"""
        self.assertEqual(
            _rules(_scan(php)), ["no-admin-required-annotation-with-admin-body"]
        )

    def test_the_narrowing_helper_is_actually_wired_in(self):
        # An unapplied change looks exactly like a passing test. Assert the
        # guard exists before trusting the negatives above.
        src = Path(csa.__file__).read_text(encoding="utf-8")
        self.assertIn("_unconditional_part(body[body_start:i])", src)


if __name__ == "__main__":
    unittest.main(verbosity=2)
