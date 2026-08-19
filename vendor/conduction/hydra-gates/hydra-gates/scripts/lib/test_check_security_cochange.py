#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_security_cochange (gate-47). Run with:

    python3 scripts/lib/test_check_security_cochange.py

BOTH WAYS, EVERY TIME
---------------------
gate-47 classified a changed file by grepping the WHOLE file, so a PR whose
hunks were CSS tokens and a chevron column was told to add a CSRF test, and a
provably comment-only PR was told to co-change tests. Neither author used the
opt-out — which is the tell. People do not reach for an opt-out when they
believe the finding is wrong.

Every relaxation below is paired, in the same class, with the real security
change it must not swallow. The two false-positive fixtures are the reported
shapes: a `.vue` file whose diff is CSS custom properties while the file
elsewhere uses `IUserSession`, and a `lib/*.php` whose 30 changed lines are
all docblock prose.
"""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_security_cochange as csc  # noqa: E402


class _Repo:
    def __init__(self):
        self.root = Path(tempfile.mkdtemp())
        self._git("init", "-q")
        self._git("config", "user.email", "t@example.invalid")
        self._git("config", "user.name", "test")

    def _git(self, *args: str) -> None:
        subprocess.run(["git", "-C", str(self.root), *args],
                       check=False, capture_output=True)

    def write(self, rel: str, body: str) -> None:
        p = self.root / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(body, encoding="utf-8")

    def move(self, src: str, dst: str) -> None:
        """`git mv`, with the destination directory created first.

        `git mv` FAILS when the destination directory does not exist, and
        `_git` swallows the error (check=False, output captured). A test that
        moved into a new directory therefore changed nothing, committed
        nothing, and diffed an EMPTY change set — which every assertion of the
        form `assertEqual(security, [])` passes for the wrong reason. Caught
        by `test_the_rename_map_pairs_source_to_destination`, which asserts a
        NON-empty result and so cannot pass on an empty diff.
        """
        (self.root / dst).parent.mkdir(parents=True, exist_ok=True)
        proc = subprocess.run(["git", "-C", str(self.root), "mv", src, dst],
                              check=False, capture_output=True, text=True)
        if proc.returncode != 0:
            raise AssertionError(f"git mv {src} -> {dst} failed: {proc.stderr}")

    def commit(self, msg: str) -> str:
        self._git("add", "-A")
        self._git("commit", "-qm", msg)
        return subprocess.run(["git", "-C", str(self.root), "rev-parse", "HEAD"],
                              capture_output=True, text=True).stdout.strip()

    def scan(self, base: str):
        return csc.scan(base, str(self.root))

    def close(self):
        shutil.rmtree(self.root, ignore_errors=True)


# A component that DOES use IUserSession — somewhere else in the file.
VUE_BEFORE = """<template>
  <div class="panel">
    <span>{{ user.displayName }}</span>
  </div>
</template>
<script>
import { getCurrentUser } from '@nextcloud/auth'
// resolves through IUserSession on the server side
export default { name: 'Panel' }
</script>
<style>
.panel { --panel-gap: 8px; }
</style>
"""

VUE_AFTER_CSS_ONLY = VUE_BEFORE.replace(
    ".panel { --panel-gap: 8px; }",
    ".panel { --panel-gap: 12px; --panel-pad: 4px; }\n.panel__chevron { width: 24px; }",
)

PHP_BEFORE = """<?php
namespace OCA\\Thing\\Controller;

class ThingController extends Controller
{
    /**
     * List things.
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->userSession->getUser()->getUID();
        return new JSONResponse($this->service->listFor($uid));
    }
}
"""

# 30-ish changed lines, every one of them docblock prose.
PHP_AFTER_DOCBLOCK_ONLY = PHP_BEFORE.replace(
    """    /**
     * List things.
     */""",
    """    /**
     * List things.
     *
     * Returns every thing visible to the caller. Visibility is resolved by
     * the service, which narrows on the caller's organisation; the controller
     * itself performs no filtering and holds no policy.
     *
     * The IUserSession lookup below is the only place the caller identity
     * enters this method. It is not re-read anywhere else, and callers must
     * not pass a user id in the request.
     *
     * @return JSONResponse the visible things, newest first
     */""",
)


class WholeFileClassificationFalsePositives(unittest.TestCase):
    def setUp(self):
        self.repo = _Repo()

    def tearDown(self):
        self.repo.close()

    def test_fp_a_css_only_diff_in_a_session_using_component(self):
        self.repo.write("src/Panel.vue", VUE_BEFORE)
        base = self.repo.commit("base")
        self.repo.write("src/Panel.vue", VUE_AFTER_CSS_ONLY)
        self.repo.commit("css tokens and a chevron column")
        security, has_test = self.repo.scan(base)
        self.assertEqual(security, [])
        self.assertFalse(has_test)

    def test_fp_a_docblock_only_diff_in_an_annotated_controller(self):
        self.repo.write("lib/Controller/ThingController.php", PHP_BEFORE)
        base = self.repo.commit("base")
        self.repo.write("lib/Controller/ThingController.php", PHP_AFTER_DOCBLOCK_ONLY)
        self.repo.commit("document the visibility rule")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, [])

    def test_tp_editing_the_annotation_itself_still_fires(self):
        # The pairing for the docblock case. Same file, same PR shape — the
        # only difference is that the changed line IS the declaration.
        self.repo.write("lib/Controller/ThingController.php", PHP_BEFORE)
        base = self.repo.commit("base")
        self.repo.write("lib/Controller/ThingController.php",
                        PHP_BEFORE.replace("#[NoAdminRequired]", "#[PublicPage]"))
        self.repo.commit("make it public")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, ["lib/Controller/ThingController.php"])

    def test_tp_a_docblock_annotation_counts_because_it_IS_the_declaration(self):
        # `@NoAdminRequired` in a docblock is Nextcloud's legacy auth
        # declaration, not prose. Excluding all comment lines would miss it —
        # which is why the discriminator is the TOKEN, not the line shape.
        self.repo.write("lib/Controller/Other.php",
                        "<?php\nclass Other {\n    /**\n     * Do it.\n     */\n"
                        "    public function go() {}\n}\n")
        base = self.repo.commit("base")
        self.repo.write("lib/Controller/Other.php",
                        "<?php\nclass Other {\n    /**\n     * Do it.\n     * @NoAdminRequired\n     */\n"
                        "    public function go() {}\n}\n")
        self.repo.commit("relax auth via docblock")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, ["lib/Controller/Other.php"])

    def test_tp_adding_a_session_lookup_in_code_still_fires(self):
        self.repo.write("src/Panel.vue", VUE_BEFORE)
        base = self.repo.commit("base")
        self.repo.write("src/Panel.vue", VUE_BEFORE.replace(
            "export default { name: 'Panel' }",
            "export default { name: 'Panel', computed: { uid() { return IUserSession.get() } } }"))
        self.repo.commit("read the session in the component")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, ["src/Panel.vue"])


class PathBasedClassificationUnchanged(unittest.TestCase):
    def setUp(self):
        self.repo = _Repo()

    def tearDown(self):
        self.repo.close()

    def test_any_change_under_an_auth_path_qualifies(self):
        # There is no incidental edit to lib/Service/Auth/*. Even a comment.
        self.repo.write("lib/Service/Auth/TokenVerifier.php", "<?php\nclass T {}\n")
        base = self.repo.commit("base")
        self.repo.write("lib/Service/Auth/TokenVerifier.php",
                        "<?php\n// a note\nclass T {}\n")
        self.repo.commit("comment")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, ["lib/Service/Auth/TokenVerifier.php"])

    def test_a_csrf_named_file_qualifies(self):
        self.repo.write("lib/CsrfGuard.php", "<?php\nclass C {}\n")
        base = self.repo.commit("base")
        self.repo.write("lib/CsrfGuard.php", "<?php\nclass C { public $x = 1; }\n")
        self.repo.commit("edit")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, ["lib/CsrfGuard.php"])


class TestCoChangeDetection(unittest.TestCase):
    def setUp(self):
        self.repo = _Repo()

    def tearDown(self):
        self.repo.close()

    def test_a_test_in_the_same_diff_is_seen(self):
        self.repo.write("lib/Service/Auth/T.php", "<?php\nclass T {}\n")
        base = self.repo.commit("base")
        self.repo.write("lib/Service/Auth/T.php", "<?php\nclass T { public $y = 2; }\n")
        self.repo.write("tests/Unit/TTest.php", "<?php\nclass TTest {}\n")
        self.repo.commit("change + test")
        security, has_test = self.repo.scan(base)
        self.assertEqual(security, ["lib/Service/Auth/T.php"])
        self.assertTrue(has_test)


class LineClassifierDirectly(unittest.TestCase):
    """The unit the whole fix turns on."""

    def test_annotations_count_in_any_line_shape(self):
        for line in ("    #[NoAdminRequired]",
                     "     * @NoCSRFRequired",
                     "// @PublicPage",
                     "    #[AuthorizedAdminSetting(Application::APP_ID)]"):
            with self.subTest(line=line):
                self.assertTrue(csc.line_is_security_relevant(line))

    def test_code_tokens_count_in_code(self):
        for line in ("        $u = $this->userSession;  // IUserSession",
                     "        if (hash_equals($a, $b)) {",
                     "        $p = parse_url($url);"):
            with self.subTest(line=line):
                self.assertTrue(csc.line_is_security_relevant(line))

    def test_code_tokens_do_not_count_in_prose(self):
        for line in ("     * The IUserSession lookup below is the only place",
                     "     * we call parse_url on the redirect target.",
                     "// requesttoken is set by the shell, not here",
                     "  /* hash_equals is used for the comparison */"):
            with self.subTest(line=line):
                self.assertFalse(csc.line_is_security_relevant(line))

    def test_a_php_attribute_is_not_a_hash_comment(self):
        # `#[...]` starts with `#`. Reading it as a shell comment would drop
        # every modern Nextcloud auth attribute on the floor.
        self.assertTrue(csc.line_is_security_relevant("#[NoAdminRequired]"))


class GateIsNotBlind(unittest.TestCase):
    """A floor: every token in the vocabulary must still classify."""

    def test_every_security_token_is_detected_as_code(self):
        for tok in ("#[NoAdminRequired]", "#[PublicPage]", "#[NoCSRFRequired]",
                    "@NoAdminRequired", "@NoCSRFRequired", "@PublicPage",
                    "parse_url($u)", "hash_equals($a,$b)", "password_verify($p,$h)",
                    "IUserSession", "getSecureRandom()", "requesttoken"):
            with self.subTest(tok=tok):
                self.assertTrue(csc.line_is_security_relevant(f"        {tok};"))

    def test_ordinary_code_is_not_security_relevant(self):
        for line in ("        $total = $a + $b;",
                     "  .panel { --panel-gap: 12px; }",
                     "        return new JSONResponse($rows);"):
            with self.subTest(line=line):
                self.assertFalse(csc.line_is_security_relevant(line))


class AnnotationMustBeInACodePosition(unittest.TestCase):
    """The annotation arm was wrong in BOTH directions from one regex.

    Measured on larpingapp 2026-08-08 against the pre-fix helper, which read::

        _ANNOTATION_RE = re.compile(
            r"#\\[NoAdminRequired\\]"
            ...
            r"|@NoAdminRequired\\b"
            ...
        )

    unanchored, so position was never constrained and the attribute forms were
    bare literals. Every case below was RUN against that regex first: the
    false-positive cases matched it (they must not now) and the
    fully-qualified cases did not (they must now). A test that only ever saw
    the fixed code proves nothing about what the fix changed.
    """

    # The exact pre-fix pattern, kept verbatim so these assertions are a
    # comparison and not an assertion about the current implementation.
    _PRE_FIX = re.compile(
        r"#\[NoAdminRequired\]"
        r"|#\[AuthorizedAdminSetting\("
        r"|#\[PublicPage\]"
        r"|#\[NoCSRFRequired\]"
        r"|@NoAdminRequired\b"
        r"|@NoCSRFRequired\b"
        r"|@PublicPage\b"
    )

    # Prose that NAMES an annotation. Verbatim from larpingapp's
    # CharactersController.php docblock, which explains why the method is
    # deliberately admin-only; rewording that sentence made gate-47 demand a
    # test co-change on a diff containing no code at all.
    PROSE = (
        " * becomes `@NoAdminRequired` again, paired with a real ownership check.",
        " * Deliberately NOT `@NoAdminRequired`. The body requires an administrator",
        " * (#[PublicPage] + #[NoCSRFRequired]) and the response contract are owned by",
        " * see the #[NoCSRFRequired] note above before changing this",
    )

    # The fully-qualified attribute forms. Valid PHP, in daily use, and
    # invisible to a literal `#[NoAdminRequired]` match.
    QUALIFIED = (
        "    #[\\OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired]",
        "    #[\\OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired]",
        "    #[\\OCP\\AppFramework\\Http\\Attribute\\PublicPage]",
        "    #[NoAdminRequired, NoCSRFRequired]",
    )

    def test_the_pre_fix_regex_really_did_fail_both_ways(self):
        """Positive control: show the mutant CAN fail before trusting the fix.

        Without this, a green suite would be equally consistent with "the bug
        was never there".
        """
        for line in self.PROSE:
            with self.subTest(direction="false positive", line=line):
                self.assertIsNotNone(
                    self._PRE_FIX.search(line),
                    "pre-fix regex was supposed to match this prose",
                )
        for line in self.QUALIFIED[:3]:
            with self.subTest(direction="false negative", line=line):
                self.assertIsNone(
                    self._PRE_FIX.search(line),
                    "pre-fix regex was supposed to MISS the qualified form",
                )

    def test_prose_naming_an_annotation_is_not_a_security_change(self):
        for line in self.PROSE:
            with self.subTest(line=line):
                self.assertFalse(csc.line_is_security_relevant(line))

    def test_a_fully_qualified_attribute_is_a_security_change(self):
        for line in self.QUALIFIED:
            with self.subTest(line=line):
                self.assertTrue(csc.line_is_security_relevant(line))

    def test_a_docblock_tag_at_tag_position_still_counts(self):
        for line in ("     * @NoAdminRequired",
                     "     * @NoCSRFRequired",
                     "     * @PublicPage",
                     "// @PublicPage",
                     "    #[NoAdminRequired]",
                     "    #[AuthorizedAdminSetting(Application::APP_ID)]"):
            with self.subTest(line=line):
                self.assertTrue(csc.line_is_security_relevant(line))

    def test_end_to_end_a_qualified_attribute_with_no_test_is_reported(self):
        """The whole-repo shape, not just the line classifier.

        Reproduces the measured miss: a commit that opens an admin-only
        endpoint to every authenticated user via the qualified attribute, with
        no test in the diff, reported PASS.
        """
        repo = _Repo()
        self.addCleanup(repo.close)
        repo.write("lib/Controller/SetupController.php",
                   "<?php\nclass SetupController {\n"
                   "    public function status() { return 1; }\n}\n")
        base = repo.commit("baseline")
        repo.write("lib/Controller/SetupController.php",
                   "<?php\nclass SetupController {\n"
                   "    #[\\OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired]\n"
                   "    public function status() { return 1; }\n}\n")
        repo.commit("open the endpoint to non-admins")
        security, has_test = repo.scan(base)
        self.assertEqual(security, ["lib/Controller/SetupController.php"])
        self.assertFalse(has_test)

    def test_end_to_end_a_comment_only_diff_is_not_reported(self):
        repo = _Repo()
        self.addCleanup(repo.close)
        repo.write("lib/Controller/CharactersController.php",
                   "<?php\nclass C {\n"
                   "    /**\n"
                   "     * becomes `@NoAdminRequired` again, with an ownership check.\n"
                   "     */\n"
                   "    public function report() { return 1; }\n}\n")
        base = repo.commit("baseline")
        repo.write("lib/Controller/CharactersController.php",
                   "<?php\nclass C {\n"
                   "    /**\n"
                   "     * becomes admin-optional again, with an ownership check.\n"
                   "     */\n"
                   "    public function report() { return 1; }\n}\n")
        repo.commit("reword one docblock sentence")
        security, has_test = repo.scan(base)
        self.assertEqual(security, [])


VUE_WITH_CSRF = """<template>
  <div class="import"/>
</template>

<script>
export default {
  methods: {
    async upload() {
      return axios.post(url, body, {
        headers: { requesttoken: OC.requestToken },
      })
    },
  },
}
</script>
"""


class RenamesAreNotContentChanges(unittest.TestCase):
    """A pathspec is applied BEFORE rename detection.

    Asking git for the destination path alone removes the source side of the
    pair, so git reports the destination as `new file mode` with every line
    added — and a pure move of a file that merely CONTAINS a security token
    classified as a security change of the whole file. That is the same
    classify-the-file-not-the-hunks defect this module exists to remove,
    arriving through the pathspec instead of through grep.

    Measured 2026-08-10 on pipelinq#763, where `git mv
    src/components/ContactImportDialog.vue src/dialogs/` — `similarity index
    100%`, `0 insertions(+), 0 deletions(-)` — was reported as 230 added
    lines and one security-touching change, on a PR that changed no code at
    all.
    """

    def setUp(self):
        self.repo = _Repo()

    def tearDown(self):
        self.repo.close()

    def test_fp_a_pure_rename_is_not_a_security_change(self):
        self.repo.write("src/components/ImportDialog.vue", VUE_WITH_CSRF)
        base = self.repo.commit("base")
        self.repo.move("src/components/ImportDialog.vue",
                       "src/dialogs/ImportDialog.vue")
        self.repo.commit("move the dialog into src/dialogs (gate-13)")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, [])

    def test_tp_a_rename_that_also_edits_security_code_still_fires(self):
        self.repo.write("src/components/ImportDialog.vue",
                        VUE_WITH_CSRF.replace(
                            "        headers: { requesttoken: OC.requestToken },\n", ""))
        base = self.repo.commit("base")
        self.repo.move("src/components/ImportDialog.vue",
                       "src/dialogs/ImportDialog.vue")
        self.repo.write("src/dialogs/ImportDialog.vue", VUE_WITH_CSRF)
        self.repo.commit("move the dialog AND add a CSRF header")
        security, _ = self.repo.scan(base)
        self.assertEqual(security, ["src/dialogs/ImportDialog.vue"])

    def test_the_rename_map_pairs_source_to_destination(self):
        self.repo.write("src/components/ImportDialog.vue", VUE_WITH_CSRF)
        base = self.repo.commit("base")
        self.repo.move("src/components/ImportDialog.vue",
                       "src/dialogs/ImportDialog.vue")
        self.repo.commit("move")
        self.assertEqual(
            csc.rename_map(base, str(self.repo.root)),
            {"src/dialogs/ImportDialog.vue": "src/components/ImportDialog.vue"},
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
