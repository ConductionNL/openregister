#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_apphost_autoload_prelude. Run with:

    python3 scripts/lib/test_check_apphost_autoload_prelude.py

The first two cases are the ones that matter: a KNOWN-BAD app must go RED, and
the same app with the prelude added must go GREEN. A gate that has only ever
been observed passing is indistinguishable from a gate that cannot fail.
"""
from __future__ import annotations

import io
import os
import shutil
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_apphost_autoload_prelude as gate  # noqa: E402

PRELUDE = """
        try {
            $orPath = \\OCP\\Server::get(\\OCP\\App\\IAppManager::class)->getAppPath('openregister');
            \\OC_App::registerAutoloading('openregister', $orPath);
        } catch (\\Throwable) {
            // OpenRegister absent/disabled — fall through to the degraded path.
        }
"""

UNGUARDED_BOOTSTRAP = """<?php
namespace OCA\\Leaf\\AppInfo;

use OCA\\OpenRegister\\AppHost\\Bootstrap;
use OCP\\AppFramework\\App;

class Application extends App
{
    public function register($context): void
    {
%s
        Bootstrap::register($context, 'leaf', ['namespace' => 'OCA\\\\Leaf']);
        $context->registerEventListener(SomeEvent::class, SomeListener::class);
    }
}
"""


def _write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def _run(app_dir: Path) -> tuple[int, str]:
    buf = io.StringIO()
    with redirect_stdout(buf):
        rc = gate.main(["check_apphost_autoload_prelude.py", str(app_dir)])
    return rc, buf.getvalue()


class GateCase(unittest.TestCase):
    def setUp(self) -> None:
        self.dir = Path(tempfile.mkdtemp(prefix="apphost-prelude-"))

    def tearDown(self) -> None:
        shutil.rmtree(self.dir, ignore_errors=True)

    def app(self, name: str, content: str) -> None:
        _write(self.dir / "lib" / "AppInfo" / name, content)


class SeverityFramingTest(GateCase):
    """The message must not overclaim. An app sorting AFTER openregister works
    today; saying otherwise makes the gate look like it is crying wolf."""

    def _with_id(self, app_id: str) -> None:
        _write(
            self.dir / "appinfo" / "info.xml",
            f"<?xml version='1.0'?>\n<info>\n <id>{app_id}</id>\n</info>\n",
        )
        self.app("Application.php", UNGUARDED_BOOTSTRAP % "")

    def test_app_sorting_before_openregister_is_LIVE_EXPOSED(self):
        self._with_id("doriath")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1)
        self.assertIn("LIVE-EXPOSED", out)
        self.assertIn("sorts BEFORE", out)

    def test_app_sorting_after_openregister_is_LATENT(self):
        self._with_id("pipelinq")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1, "still a landmine, so still a failure")
        self.assertIn("LATENT", out)
        self.assertIn("by alphabet alone", out)
        self.assertNotIn("LIVE-EXPOSED", out)

    def test_app_id_comes_from_info_xml_not_the_directory_name(self):
        """The checkout directory is not what Nextcloud sorts on."""
        self._with_id("zzz-sorts-last")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1)
        self.assertIn("zzz-sorts-last", out)
        self.assertIn("LATENT", out)


class RedThenGreenTest(GateCase):
    """The proof that the gate can fail, and that the documented fix clears it."""

    def test_known_bad_input_is_RED(self):
        self.app("Application.php", UNGUARDED_BOOTSTRAP % "")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1, "an unguarded Bootstrap reference with no prelude MUST fail")
        self.assertIn("FAIL", out)
        self.assertIn("Application.php", out)

    def test_same_app_with_the_prelude_is_GREEN(self):
        self.app("Application.php", UNGUARDED_BOOTSTRAP % PRELUDE)
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0, "the documented prelude MUST clear the gate")
        self.assertIn("apphost-autoload-prelude: OK", out)


class DetectionShapesTest(GateCase):
    def test_class_exists_probe_without_prelude_is_RED(self):
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    public function register($context): void {
        if (class_exists('OCA\\\\OpenRegister\\\\AppHost\\\\Routes') === true) {
            $context->registerService('x', fn () => null);
        }
    }
}
""")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1)
        self.assertIn("class_exists()", out)

    def test_fqcn_string_form_is_detected(self):
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    public function register($context): void {
        $b = 'OCA\\\\OpenRegister\\\\AppHost\\\\Bootstrap';
        $b::register($context, 'leaf', []);
    }
}
""")
        self.assertEqual(_run(self.dir)[0], 1)

    def test_prelude_in_a_sibling_composition_root_file_is_accepted(self):
        self.app("Application.php", UNGUARDED_BOOTSTRAP % "        $this->prelude();\n")
        self.app("AutoloadPrelude.php", "<?php\nnamespace OCA\\Leaf\\AppInfo;\nclass AutoloadPrelude {\n public function prelude(): void {\n%s\n }\n}\n" % PRELUDE)
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0, "the prelude may live in a sibling file register() calls")


class NoFalsePositiveTest(GateCase):
    def test_lazy_container_string_reference_is_not_flagged(self):
        """A closure body that resolves an AppHost service runs long after every
        app has registered. Flagging it would make the gate noise."""
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    public function register($context): void {
        $context->registerService('health', function ($c) {
            return $c->get('OCA\\\\OpenRegister\\\\AppHost\\\\Observability\\\\MetricsEngine');
        });
    }
}
""")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0, "lazy closure bodies must NOT be flagged")

    def test_openregister_itself_is_exempt(self):
        self.app("Application.php", """<?php
namespace OCA\\OpenRegister\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Bootstrap;
class Application {
    public function register($context): void {
        Bootstrap::register($context, 'openregister', []);
    }
}
""")
        self.assertEqual(_run(self.dir)[0], 0, "OpenRegister owns AppHost")

    def test_app_with_no_lib_appinfo_is_clean(self):
        (self.dir / "src").mkdir(parents=True, exist_ok=True)
        self.assertEqual(_run(self.dir)[0], 0)


class SuppressionTest(GateCase):
    def test_reason_bearing_suppression_is_accepted(self):
        self.app("Application.php", UNGUARDED_BOOTSTRAP % (
            "        // apphost-prelude exclude this app is openregister's own test harness\n"
        ))
        self.assertEqual(_run(self.dir)[0], 0)

    def test_bare_suppression_with_no_reason_still_FAILS(self):
        self.app("Application.php", UNGUARDED_BOOTSTRAP % (
            "        // apphost-prelude exclude\n"
        ))
        self.assertEqual(
            _run(self.dir)[0], 1,
            "a bare annotation with no reason must not buy a pass",
        )


class WrongFixTest(GateCase):
    def test_loadApp_is_rejected_and_explained(self):
        self.app("Application.php", UNGUARDED_BOOTSTRAP % (
            "        \\OCP\\Server::get(\\OCP\\App\\IAppManager::class)->loadApp('openregister');\n"
        ))
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1, "loadApp() is not a prelude")
        self.assertIn("bootApp()", out)


class ConstantAppIdTest(GateCase):
    """The app id may be a class constant, not only a quoted literal.

    MEASURED on doriath, which shipped a correct prelude
    (lib/AppInfo/OpenRegisterAutoloader.php) called BEFORE its Bootstrap
    reference, and was still reported as an ADR-040 violation — the gate was
    reading how the id is SPELLED, not what the code does.
    """

    def test_constant_resolving_to_openregister_is_a_prelude(self):
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Bootstrap;
class Application {
    private const OPENREGISTER_APP_ID = 'openregister';
    public function register($context): void {
        $p = \\OCP\\Server::get(\\OCP\\App\\IAppManager::class)
            ->getAppPath(self::OPENREGISTER_APP_ID);
        \\OC_App::registerAutoloading(self::OPENREGISTER_APP_ID, $p);
        if (class_exists(Bootstrap::class) === true) {
            Bootstrap::register($context, 'leaf', []);
        }
    }
}
""")
        self.assertEqual(
            _run(self.dir)[0], 0,
            "a constant defined to 'openregister' is the same prelude",
        )

    def test_prelude_may_live_in_a_sibling_file(self):
        """doriath's real shape: the prelude is its own class."""
        self.app("OpenRegisterAutoloader.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class OpenRegisterAutoloader {
    private const OPENREGISTER_APP_ID = 'openregister';
    public static function register(): bool {
        try {
            $m = \\OCP\\Server::get(\\OCP\\App\\IAppManager::class);
            \\OC_App::registerAutoloading(
                self::OPENREGISTER_APP_ID,
                $m->getAppPath(self::OPENREGISTER_APP_ID)
            );
            return true;
        } catch (\\Throwable) {
            return false;
        }
    }
}
""")
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Bootstrap;
class Application {
    public function register($context): void {
        OpenRegisterAutoloader::register();
        if (class_exists(Bootstrap::class) === true) {
            Bootstrap::register($context, 'leaf', []);
        }
    }
}
""")
        self.assertEqual(_run(self.dir)[0], 0)

    def test_constant_naming_a_DIFFERENT_app_is_not_a_prelude(self):
        """The positive control: the constant path must still be able to fail."""
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Bootstrap;
class Application {
    private const OTHER_APP_ID = 'opencatalogi';
    public function register($context): void {
        \\OC_App::registerAutoloading(self::OTHER_APP_ID, $p);
        if (class_exists(Bootstrap::class) === true) {
            Bootstrap::register($context, 'leaf', []);
        }
    }
}
""")
        self.assertEqual(
            _run(self.dir)[0], 1,
            "registering SOME OTHER app's autoloader is not the prelude",
        )


class CommentsAreNotCodeTest(GateCase):
    """Every rule is evidence about code, so comments must not feed it."""

    def test_comment_explaining_that_loadApp_is_avoided_is_not_a_finding(self):
        """doriath's second false finding, exactly.

        Both `loadApp` occurrences under its lib/AppInfo/ are prose saying the
        code deliberately does NOT call it.
        """
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Bootstrap;
class Application {
    private const OPENREGISTER_APP_ID = 'openregister';
    public function register($context): void {
        // Deliberately NOT IAppManager::loadApp('openregister'): that would
        // boot OpenRegister before its own register() has run.
        \\OC_App::registerAutoloading(self::OPENREGISTER_APP_ID, $p);
        if (class_exists(Bootstrap::class) === true) {
            Bootstrap::register($context, 'leaf', []);
        }
    }
}
""")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0, "a comment about loadApp is not a call to it")
        self.assertNotIn("bootApp()", out)

    def test_commented_OUT_prelude_is_NOT_a_prelude(self):
        """The dangerous direction: stripping comments must not buy a green."""
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Bootstrap;
class Application {
    public function register($context): void {
        // \\OC_App::registerAutoloading('openregister', $p);
        /* \\OC_App::registerAutoloading('openregister', $p); */
        if (class_exists(Bootstrap::class) === true) {
            Bootstrap::register($context, 'leaf', []);
        }
    }
}
""")
        self.assertEqual(
            _run(self.dir)[0], 1,
            "a prelude that does not RUN is not a prelude",
        )

    def test_php8_attribute_is_not_read_as_a_comment(self):
        """`#[` opens an attribute; treating it as `#` would eat the line."""
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    #[SomeAttribute]
    public function register($context): void {
        if (class_exists('OCA\\\\OpenRegister\\\\AppHost\\\\Bootstrap') === true) {
            return;
        }
    }
}
""")
        self.assertEqual(
            _run(self.dir)[0], 1,
            "the class_exists probe after an attribute must still be seen",
        )


_CLASS_EXISTS_APP = """<?php
namespace OCA\\Leaf\\AppInfo;
%(use)s
class Application
{
%(const)s
    public function register($context): void
    {
        if (class_exists(%(arg)s) === true) {
            $context->registerEventListener(SomeEvent::class, SomeListener::class);
        }
    }
}
"""

_AH = "OCA\\\\OpenRegister\\\\AppHost\\\\Controller\\\\GenericHealthController"


class ClassNameSpellingTest(GateCase):
    """RULE 2 SAW ONE OF PHP'S THREE WAYS TO NAME A CLASS (.github#276).

    `CLASS_EXISTS_APPHOST` required a QUOTED literal, so only the first of
    these was a finding — the other three were injected into larpingapp's
    register() and the gate reported OK for every one:

        class_exists('OCA\\OpenRegister\\AppHost\\…\\GenericHealthController')
        class_exists(\\OCA\\OpenRegister\\AppHost\\…\\GenericHealthController::class)
        use …\\GenericHealthController;  class_exists(GenericHealthController::class)
        const AH = 'OCA\\OpenRegister\\AppHost\\…';  class_exists(self::AH)

    That is #184's lesson in the file where it was learned: a checker that
    greps a STRING LITERAL misses every constant. The class name is the
    SUBJECT of this gate, so missing three of its four spellings is missing
    the gate.

    `Foo::class` does not itself autoload — it is compile-time. It is
    `class_exists()` that reaches the autoloader, which is why the CALL is
    what is matched and why a bare `use` import is deliberately NOT a finding
    (asserted below, so the fix cannot drift into flagging imports).
    """

    def _app(self, *, arg: str, use: str = "", const: str = "") -> None:
        self.app(
            "Application.php",
            _CLASS_EXISTS_APP % {"arg": arg, "use": use, "const": const},
        )

    def test_quoted_fqcn(self):
        self._app(arg=f"'{_AH}'")
        self.assertEqual(_run(self.dir)[0], 1)

    def test_fully_qualified_class_constant(self):
        self._app(arg="\\OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController::class")
        self.assertEqual(_run(self.dir)[0], 1, "the ::class form is the same probe")

    def test_imported_short_name_class_constant(self):
        self._app(
            arg="GenericHealthController::class",
            use="use OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController;",
        )
        self.assertEqual(_run(self.dir)[0], 1, "a `use` import must be resolved")

    def test_php_class_constant_holding_the_fqcn(self):
        self._app(arg="self::AH_HEALTH", const=f"    private const AH_HEALTH = '{_AH}';")
        self.assertEqual(_run(self.dir)[0], 1, "a constant is not a different defect")

    def test_the_prelude_still_clears_every_spelling(self):
        for arg, use, const in (
            (f"'{_AH}'", "", ""),
            ("\\OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController::class", "", ""),
            ("self::AH_HEALTH", "", f"    private const AH_HEALTH = '{_AH}';"),
        ):
            with self.subTest(arg=arg):
                self.app(
                    "Application.php",
                    _CLASS_EXISTS_APP % {"arg": arg, "use": use, "const": const}
                    + "\nclass Prelude { function p() {" + PRELUDE + "} }\n",
                )
                self.assertEqual(_run(self.dir)[0], 0)

    # --- ANTI-WIDENING -------------------------------------------------
    def test_a_bare_use_import_is_not_a_finding(self):
        """`use` is a compile-time alias. It does not autoload, so it is not
        this defect — and treating it as one would newly redden four repos
        (docudesk, opencatalogi, openconnector, openbuild) for code that
        works. Measured before this fix landed."""
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
use OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController;
class Application { public function register($c): void { $c->x(); } }
""")
        self.assertEqual(_run(self.dir)[0], 0)

    def test_class_exists_on_an_unrelated_class_is_not_a_finding(self):
        self._app(arg="\\OCA\\Talk\\Manager::class")
        self.assertEqual(_run(self.dir)[0], 0)

    def test_a_comment_naming_the_probe_is_not_the_probe(self):
        """#184's other direction, re-asserted on the new spellings."""
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    public function register($c): void {
        // Deliberately NOT class_exists(\\OCA\\OpenRegister\\AppHost\\Bootstrap::class):
        // ADR-040 says register the autoloader instead.
        $c->x();
    }
}
""")
        self.assertEqual(_run(self.dir)[0], 0)


_OR_EVENT_APP = """<?php
namespace OCA\\Leaf\\AppInfo;
%(use)s
class Application
{
    public function register($context): void
    {
        if (class_exists(%(arg)s) === true) {
            $context->registerEventListener(X::class, Y::class);
        }
    }

    public function boot($context): void
    {
        if (class_exists('OCA\\\\OpenRegister\\\\Event\\\\BootTimeEvent') === true) {
            $context->x();
        }
    }
}
"""


class OpenRegisterProbeNoteTest(GateCase):
    """A GREEN GATE-64 WAS NOT EVIDENCE ABOUT THIS (.github#276).

    The gate's hard rule is scoped to `OCA\\OpenRegister\\AppHost\\`, but the
    autoloader mechanism has nothing to do with AppHost: during register() the
    whole `OCA\\OpenRegister\\` PSR-4 prefix is absent for any app sorting
    earlier, so ANY class_exists() on it answers FALSE and everything it
    guards silently never happens.

    Measured 2026-08-08 across apps-extra with no prelude present:
      larpingapp  3 — Event\\{DeepLinkRegistration,ObjectCreating,ObjectUpdating}
                      and the last two carry larpingapp's server-authoritative
                      skill-requirement / XP-budget enforcement on character
                      writes, which therefore never registers.
      hermiq      3 — flow-node, leaf-provider and shareable-config registration
      nldesign    1 — shareable-config registration

    Reported as a NOTE, not a FAIL, and deliberately: this gate is not
    diff-scoped, so failing it turns every PR in three repos permanently red
    for code the PR did not touch — the trap
    check_store_and_settings_surface.py already records ("blocked EVERY
    manifest-touching PR in that repo, permanently").
    """

    def _app(self, *, arg: str, use: str = "") -> None:
        self.app("Application.php", _OR_EVENT_APP % {"arg": arg, "use": use})

    def test_quoted_probe_is_noted_but_does_not_fail(self):
        self._app(arg="'OCA\\\\OpenRegister\\\\Event\\\\ObjectCreatingEvent'")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0, "a NOTE must not fail the gate")
        self.assertIn("NOTE", out)
        self.assertIn("ObjectCreatingEvent", out)

    def test_class_constant_probe_is_noted(self):
        """The note must not repeat the string-literal blind spot it exists to
        report. hermiq and nldesign write theirs as `::class`; a note that
        could not see them would have measured 1 repo instead of 3."""
        self._app(arg="\\OCA\\OpenRegister\\Service\\Flow\\RegisterFlowNodesEvent::class")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0)
        self.assertIn("RegisterFlowNodesEvent", out)

    def test_imported_probe_is_noted(self):
        self._app(
            arg="RegisterLeafProvidersEvent::class",
            use="use OCA\\OpenRegister\\Event\\RegisterLeafProvidersEvent;",
        )
        self.assertIn("RegisterLeafProvidersEvent", _run(self.dir)[1])

    def test_a_probe_in_boot_is_NOT_noted(self):
        """boot() runs after every app has registered, so the prefix is on the
        autoloader and the probe resolves. Noting it would be a false positive
        — the reason a note gets ignored. The fixture's boot() carries
        `BootTimeEvent` in every case above and it must never appear."""
        self._app(arg="'OCA\\\\OpenRegister\\\\Event\\\\ObjectCreatingEvent'")
        self.assertNotIn("BootTimeEvent", _run(self.dir)[1])

    def test_the_prelude_silences_the_note(self):
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    public function register($context): void {
%s
        if (class_exists('OCA\\\\OpenRegister\\\\Event\\\\ObjectCreatingEvent') === true) {
            $context->x();
        }
    }
}
""" % PRELUDE)
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0)
        self.assertNotIn("NOTE", out)

    def test_an_apphost_probe_is_a_FAIL_not_a_note(self):
        """The two rules must not double-report one defect."""
        self._app(arg=f"'{_AH}'")
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1)
        self.assertNotIn("NOTE", out)


class LazyClosureTest(GateCase):
    """THE EXEMPTION WAS DOCUMENTED AND NEVER IMPLEMENTED (.github#276).

    This module's header has always said lazy service closures that merely
    MENTION an AppHost class are deliberately NOT flagged, because their
    bodies run at resolution time, long after every app has registered. Both
    rules ran over the whole file, so they did not.

    Measured on launchpad — a DIFFERENT repo shape from larpingapp, and the
    one that matters here: launchpad's whole composition root resolves
    OpenRegister lazily inside closures (it already does this for
    `AppHost\\Observability\\ManifestLoader`), which is the documented leaf
    pattern and the reason launchpad is green today. A closure body naming
    Bootstrap reported byte-identically to an eager
    `Bootstrap::register($context, …)`. The gate would have failed the one
    repo doing it correctly, for doing it correctly, and the only remedy is
    to stop writing the lazy form — i.e. to introduce the defect.
    """

    def _app(self, body: str) -> None:
        self.app("Application.php", """<?php
namespace OCA\\Leaf\\AppInfo;
class Application {
    public function register($context): void {
%s
    }
}
""" % body)

    def test_a_closure_body_naming_bootstrap_is_not_a_finding(self):
        self._app('        $context->registerService("L", function ($c) {\n'
                  '            return new \\OCA\\OpenRegister\\AppHost\\Bootstrap($c);\n'
                  '        });')
        self.assertEqual(_run(self.dir)[0], 0)

    def test_an_arrow_function_naming_bootstrap_is_not_a_finding(self):
        self._app('        $context->registerService("L", '
                  'fn ($c) => new \\OCA\\OpenRegister\\AppHost\\Bootstrap($c));')
        self.assertEqual(_run(self.dir)[0], 0)

    def test_a_closure_body_probing_an_apphost_class_is_not_a_finding(self):
        self._app('        $context->registerService("L", function ($c) {\n'
                  '            return class_exists(\\OCA\\OpenRegister\\AppHost'
                  '\\Controller\\GenericHealthController::class);\n'
                  '        });')
        self.assertEqual(_run(self.dir)[0], 0)

    def test_a_static_closure_is_also_lazy(self):
        self._app('        $context->registerService("L", static function ($c) {\n'
                  '            return new \\OCA\\OpenRegister\\AppHost\\Bootstrap($c);\n'
                  '        });')
        self.assertEqual(_run(self.dir)[0], 0)

    # --- ANTI-WIDENING. The mask must not swallow eager code. ------------
    def test_an_eager_reference_is_still_a_finding(self):
        self._app('        \\OCA\\OpenRegister\\AppHost\\Bootstrap::register($context, "leaf", []);')
        self.assertEqual(_run(self.dir)[0], 1)

    def test_an_eager_reference_AFTER_a_closure_is_still_a_finding(self):
        """The blanker walks every closure; a badly-bounded one would eat the
        rest of register(). This is the arm that catches that."""
        self._app('        $context->registerService("L", function ($c) { return 1; });\n'
                  '        \\OCA\\OpenRegister\\AppHost\\Bootstrap::register($context, "leaf", []);')
        self.assertEqual(_run(self.dir)[0], 1)

    def test_an_eager_reference_BETWEEN_two_closures_is_still_a_finding(self):
        self._app('        $context->registerService("A", function ($c) { return 1; });\n'
                  '        \\OCA\\OpenRegister\\AppHost\\Bootstrap::register($context, "leaf", []);\n'
                  '        $context->registerService("B", fn ($c) => 2);')
        self.assertEqual(_run(self.dir)[0], 1)

    def test_the_named_register_method_is_not_blanked(self):
        """`public function register(` must never be treated as a closure —
        blanking it would empty the gate entirely, which is the failure mode
        that looks exactly like a fix."""
        code = gate.blank_closure_bodies(
            "<?php class A { public function register($c): void {"
            " \\OCA\\OpenRegister\\AppHost\\Bootstrap::x(); } }")
        self.assertIn("Bootstrap", code)

    def test_a_probe_inside_a_closure_is_not_NOTEd_either(self):
        self._app('        $context->registerService("L", function ($c) {\n'
                  "            return class_exists('OCA\\\\OpenRegister\\\\Event\\\\ObjectCreatingEvent');\n"
                  '        });')
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0)
        self.assertNotIn("NOTE", out)


if __name__ == "__main__":
    unittest.main()
