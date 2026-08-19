#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""
test_filter_preexisting_methods.py — pytest-style tests for the gate
provenance filter.

The filter (filter_preexisting_methods.py) is load-bearing for gates
6/7/8/17 — any regression in its body-comparison logic would either
re-introduce the false-positive class it was built to close, or silently
swallow real findings. These tests pin its behaviour on synthetic git
repos so refactors can't drift.

Run with:
    python3 -m pytest scripts/lib/test_filter_preexisting_methods.py -v
Or direct:
    python3 scripts/lib/test_filter_preexisting_methods.py
"""
from __future__ import annotations

import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent

# Import the filter as a module (it's a script with a hyphenated name on disk
# — wait, it isn't, the file is filter_preexisting_methods.py — but we
# import it the same way for consistency).
_spec = importlib.util.spec_from_file_location(
    "filter_preexisting_methods", HERE / "filter_preexisting_methods.py"
)
assert _spec and _spec.loader
_mod = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_mod)


# ---------------------------------------------------------------------------
# Fixture helpers
# ---------------------------------------------------------------------------


def _make_repo(tmp: Path, *, base_files: dict[str, str], head_files: dict[str, str]) -> Path:
    """Create a git repo at `tmp` with `base_files` on main and `head_files` on feature."""
    subprocess.run(["git", "init", "-q"], cwd=tmp, check=True)
    subprocess.run(["git", "symbolic-ref", "HEAD", "refs/heads/main"],
                   cwd=tmp, check=True)
    subprocess.run(["git", "config", "user.email", "t@t"], cwd=tmp, check=True)
    subprocess.run(["git", "config", "user.name", "t"], cwd=tmp, check=True)
    for path, content in base_files.items():
        full = tmp / path
        full.parent.mkdir(parents=True, exist_ok=True)
        full.write_text(content)
    subprocess.run(["git", "add", "-A"], cwd=tmp, check=True)
    subprocess.run(["git", "commit", "-q", "-m", "initial"], cwd=tmp, check=True)
    subprocess.run(["git", "checkout", "-q", "-b", "feature"], cwd=tmp, check=True)
    for path, content in head_files.items():
        full = tmp / path
        full.parent.mkdir(parents=True, exist_ok=True)
        full.write_text(content)
    subprocess.run(["git", "add", "-A"], cwd=tmp, check=True)
    subprocess.run(["git", "commit", "-q", "-m", "feature"], cwd=tmp, check=True)
    return tmp


def _run_filter(log_path: Path, base_ref: str = "main") -> int:
    """Run filter_preexisting_methods.py against a log file."""
    cwd = log_path.parent if log_path.parent.name != "tmp" else log_path.parent
    return subprocess.call(
        ["python3", str(HERE / "filter_preexisting_methods.py"), base_ref, str(log_path)],
        cwd=log_path.parent.parent,  # repo root
    )


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


def test_extract_method_body_finds_simple_method():
    src = """<?php
class Foo {
    public function bar(): int {
        return 42;
    }
}
"""
    body = _mod._extract_method_body(src, "bar")
    assert body is not None
    assert "function bar()" in body
    assert "return 42;" in body


def test_extract_method_body_handles_braces_in_strings():
    """Brace counting must skip braces inside string literals."""
    src = '''<?php
class Foo {
    public function bar(): string {
        $x = "this { has braces } in strings";
        return $x;
    }
}
'''
    body = _mod._extract_method_body(src, "bar")
    assert body is not None
    # The body should end at the real closing brace, not the one inside the string
    assert body.rstrip().endswith("}")
    assert '"this { has braces } in strings"' in body


def test_extract_method_body_returns_none_when_missing():
    src = "<?php\nclass Foo {}\n"
    assert _mod._extract_method_body(src, "nonexistent") is None


def test_normalise_strips_trailing_whitespace():
    a = "function bar() {\n    return 1;   \n}\n"
    b = "function bar() {\n    return 1;\n}\n"
    assert _mod._normalise(a) == _mod._normalise(b)


def test_filter_partitions_preexisting_vs_touched():
    """Integration — a method byte-identical on base must move to .preexisting;
    a method whose body changed must stay in the main log."""
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        base = {
            "lib/Controller/A.php": "<?php\nclass A {\n    public function untouched(): int {\n        return 1;\n    }\n}\n",
            "lib/Controller/B.php": "<?php\nclass B {\n    public function will_change(): int {\n        return 2;\n    }\n}\n",
        }
        head = {
            # A.php — file touched (added a trailing comment) but method body untouched
            "lib/Controller/A.php": "<?php\nclass A {\n    public function untouched(): int {\n        return 1;\n    }\n}\n// touched comment\n",
            # B.php — method body changed
            "lib/Controller/B.php": "<?php\nclass B {\n    public function will_change(): int {\n        return 99;\n    }\n}\n",
        }
        _make_repo(tmp, base_files=base, head_files=head)
        # Synthesise the gate log the filter would receive
        log = tmp / "log.txt"
        log.write_text(
            "lib/Controller/A.php:3 method=untouched rule=no-auth-guard\n"
            "lib/Controller/B.php:3 method=will_change rule=no-auth-guard\n"
        )
        # Run filter
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "main", str(log)],
            cwd=tmp,
        )
        assert rc == 0
        # log.txt should now contain only B (the touched method)
        remaining = log.read_text().strip().splitlines()
        assert len(remaining) == 1, f"expected 1 touched line, got {remaining!r}"
        assert "B.php" in remaining[0]
        # The .preexisting file should contain A
        preex = log.with_name(log.name + ".preexisting")
        assert preex.exists(), "expected .preexisting sibling to be written"
        preex_lines = preex.read_text().strip().splitlines()
        assert len(preex_lines) == 1
        assert "A.php" in preex_lines[0]


def test_filter_treats_net_new_method_as_touched():
    """A method that doesn't exist on base must stay in the main log."""
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        base = {"lib/Controller/A.php": "<?php\nclass A {}\n"}
        head = {
            "lib/Controller/A.php": "<?php\nclass A {\n    public function brand_new(): int {\n        return 1;\n    }\n}\n",
        }
        _make_repo(tmp, base_files=base, head_files=head)
        log = tmp / "log.txt"
        log.write_text("lib/Controller/A.php:3 method=brand_new rule=no-auth-guard\n")
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "main", str(log)],
            cwd=tmp,
        )
        assert rc == 0
        assert "brand_new" in log.read_text()
        assert not log.with_name(log.name + ".preexisting").exists()


def test_filter_safe_on_missing_base_ref():
    """Missing base ref → filter exits cleanly, log unchanged."""
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        subprocess.run(["git", "init", "-q"], cwd=tmp, check=True)
        subprocess.run(["git", "symbolic-ref", "HEAD", "refs/heads/main"],
                       cwd=tmp, check=True)
        log = tmp / "log.txt"
        log.write_text("lib/Controller/A.php:3 method=foo rule=x\n")
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "nonexistent-ref", str(log)],
            cwd=tmp,
        )
        assert rc == 0
        # Log should be unchanged (safe default — never silently drop findings)
        assert "method=foo" in log.read_text()


def test_filter_safe_on_unparseable_log_line():
    """Lines that don't match the expected shape stay in the log as touched."""
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        base = {"lib/A.php": "<?php\nclass A {}\n"}
        head = {"lib/A.php": "<?php\nclass A {}\n// touched\n"}
        _make_repo(tmp, base_files=base, head_files=head)
        log = tmp / "log.txt"
        log.write_text("not a recognised log shape\nlib/A.php:1 method=foo rule=x\n")
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "main", str(log)],
            cwd=tmp,
        )
        assert rc == 0
        # Both lines should still be in the log (safe defaults)
        contents = log.read_text()
        assert "not a recognised log shape" in contents


def test_filter_safe_on_empty_log():
    """Empty log → filter is a no-op."""
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        subprocess.run(["git", "init", "-q"], cwd=tmp, check=True)
        subprocess.run(["git", "symbolic-ref", "HEAD", "refs/heads/main"],
                       cwd=tmp, check=True)
        subprocess.run(["git", "config", "user.email", "t@t"], cwd=tmp, check=True)
        subprocess.run(["git", "config", "user.name", "t"], cwd=tmp, check=True)
        subprocess.run(["git", "commit", "--allow-empty", "-q", "-m", "init"], cwd=tmp, check=True)
        log = tmp / "log.txt"
        log.write_text("")
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "main", str(log)],
            cwd=tmp,
        )
        assert rc == 0


def test_filter_keeps_a_method_whose_ATTRIBUTE_was_removed():
    """Deleting `#[PublicPage]` must keep the finding, not bury it.

    THE DEFECT THIS PINS
    --------------------
    `_extract_method_body` began at the `function NAME(` line, so a method's
    ATTRIBUTES and DOCBLOCK were outside the provenance comparison. Remove
    `#[PublicPage]` from a monitoring controller and the body is byte-identical
    to base, so the entry was classified pre-existing and MOVED OUT of the gate
    log — and gate-30 reported PASS.

    Measured 2026-08-08 on openregister with `#[PublicPage]` deleted from its
    own `AppHost/Controller/GenericHealthController::index` inside the diff
    under test: the findings log was empty and the `.preexisting` sibling held

        lib/AppHost/Controller/GenericHealthController.php:78 method=index
        rule=monitoring-endpoint-missing-public-page

    Gates 5, 9 and 30 judge the annotation region and nothing else, so for them
    a method whose annotations changed IS a changed method.
    """
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        base = {
            "lib/Controller/H.php": (
                "<?php\nclass H {\n"
                "    /**\n     * GET /api/health.\n     */\n"
                "    #[PublicPage]\n"
                "    #[NoCSRFRequired]\n"
                "    public function index(): int {\n        return 1;\n    }\n}\n"
            ),
        }
        head = {
            # SAME BODY. Only the attribute is gone.
            "lib/Controller/H.php": (
                "<?php\nclass H {\n"
                "    /**\n     * GET /api/health.\n     */\n"
                "    #[NoCSRFRequired]\n"
                "    public function index(): int {\n        return 1;\n    }\n}\n"
            ),
        }
        _make_repo(tmp, base_files=base, head_files=head)
        log = tmp / "log.txt"
        log.write_text(
            "lib/Controller/H.php:8 method=index "
            "rule=monitoring-endpoint-missing-public-page\n"
        )
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "main", str(log)],
            cwd=tmp,
        )
        assert rc == 0
        remaining = log.read_text().strip().splitlines()
        assert len(remaining) == 1, (
            "the finding was buried in .preexisting: removing an attribute left "
            f"the body byte-identical. log={remaining!r}"
        )
        assert "H.php" in remaining[0]


def test_filter_still_buries_a_genuinely_untouched_method():
    """Anti-widening control for the test above.

    Including the annotation region must not turn every finding into a
    PR-introduced one — otherwise the filter stops suppressing inherited debt,
    which is the whole reason it exists (ADR-020).
    """
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        method = (
            "    /**\n     * GET /api/health.\n     */\n"
            "    #[PublicPage]\n"
            "    public function index(): int {\n        return 1;\n    }\n"
        )
        base = {"lib/Controller/H.php": "<?php\nclass H {\n" + method + "}\n"}
        head = {
            # The FILE changed; the method and its annotations did not.
            "lib/Controller/H.php": "<?php\nclass H {\n" + method + "}\n// unrelated\n"
        }
        _make_repo(tmp, base_files=base, head_files=head)
        log = tmp / "log.txt"
        log.write_text("lib/Controller/H.php:6 method=index rule=some-rule\n")
        rc = subprocess.call(
            ["python3", str(HERE / "filter_preexisting_methods.py"), "main", str(log)],
            cwd=tmp,
        )
        assert rc == 0
        assert log.read_text().strip() == "", (
            "an untouched method with untouched annotations must still be "
            f"suppressed as pre-existing; log={log.read_text()!r}"
        )
        preex = log.with_name(log.name + ".preexisting")
        assert preex.exists() and "H.php" in preex.read_text()


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------


def main():
    tests = [
        test_extract_method_body_finds_simple_method,
        test_extract_method_body_handles_braces_in_strings,
        test_extract_method_body_returns_none_when_missing,
        test_normalise_strips_trailing_whitespace,
        test_filter_partitions_preexisting_vs_touched,
        test_filter_treats_net_new_method_as_touched,
        test_filter_safe_on_missing_base_ref,
        test_filter_safe_on_unparseable_log_line,
        test_filter_safe_on_empty_log,
        test_filter_keeps_a_method_whose_ATTRIBUTE_was_removed,
        test_filter_still_buries_a_genuinely_untouched_method,
    ]
    failures = 0
    for t in tests:
        try:
            t()
            print(f"  PASS  {t.__name__}")
        except AssertionError as exc:
            failures += 1
            print(f"  FAIL  {t.__name__}: {exc}")
        except Exception as exc:
            failures += 1
            print(f"  ERROR {t.__name__}: {exc!r}")
    print(f"\n{len(tests) - failures}/{len(tests)} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
