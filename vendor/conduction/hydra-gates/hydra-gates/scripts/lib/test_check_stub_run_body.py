#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_stub_run_body (gate-3, the BackgroundJob arm).

Run with:  python3 scripts/lib/test_check_stub_run_body.py

The three fixtures from #226 are reproduced exactly, because the issue's table
IS the specification:

    A  genuine stub                       -> flagged  (correct before and after)
    B  real fail-safe delegation          -> was FLAGGED, must now be clean
    C  B plus one inert `$unused = 1;`    -> PASSED, and the padding is why

C is the false-NEGATIVE half, and it is the one that decides whether this is a
fix or a relaxation: a gate closable by a dead line is a gate that rewards
padding over code. The C-shaped case here therefore keeps the padding and
REMOVES the delegation, and must still be flagged.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_stub_run_body as gate  # noqa: E402


def job(body: str) -> str:
    return (
        "<?php\n"
        "namespace OCA\\Fixture\\BackgroundJob;\n"
        "use OCP\\BackgroundJob\\QueuedJob;\n"
        "class NotificationDispatchJob extends QueuedJob\n"
        "{\n"
        "    protected function run($argument): void\n"
        "    {\n"
        f"{body}"
        "    }\n"
        "    private function doRun(array $argument): void\n"
        "    {\n"
        "        $this->send($this->render($argument));\n"
        "    }\n"
        "}\n"
    )


FAILSAFE = (
    "        try {\n"
    "            $this->doRun(argument: $argument);\n"
    "        } catch (\\Throwable $e) {\n"
    "            $this->logger->error('dispatch failed', ['reason' => $e->getMessage()]);\n"
    "        }\n"
)


class TestTheThreeFixtures(unittest.TestCase):
    def test_a_genuine_stub_is_flagged(self):
        """The positive control. Everything else is only meaningful because
        this one is still red."""
        src = job("        $this->logger->info('not implemented yet');\n")
        self.assertTrue(gate.is_stub(src))

    def test_b_fail_safe_delegation_is_not_a_stub(self):
        """The false positive: 530 lines and 11 private methods on portaliq."""
        self.assertFalse(gate.is_stub(job(FAILSAFE)))

    def test_c_padding_alone_does_not_close_the_gate(self):
        """The false NEGATIVE. `$unused = 1;` used to take the line count from
        1 to 2 and the gate went quiet."""
        src = job(
            "        $unused = 1;\n"
            "        $this->logger->info('todo');\n"
        )
        self.assertTrue(gate.is_stub(src), "an inert assignment is not work")

    def test_c_padding_plus_delegation_is_still_not_a_stub(self):
        """And the padding must not turn a real body into a finding either."""
        self.assertFalse(gate.is_stub(job("        $unused = 1;\n" + FAILSAFE)))


class TestWhatMustStillBeFlagged(unittest.TestCase):
    def test_an_empty_body(self):
        self.assertTrue(gate.is_stub(job("")))

    def test_logger_only_with_several_levels(self):
        src = job(
            "        $this->logger->debug('start');\n"
            "        $this->logger->info('nothing to do');\n"
            "        $this->logger->warning('really nothing');\n"
        )
        self.assertTrue(gate.is_stub(src))

    def test_a_commented_out_implementation(self):
        """Written at column 0 so the old `^\\s*(//|\\*)` filter would have
        skipped it as a comment line — and it must not count as work either
        way. #184's false GREEN, in this gate's dialect."""
        src = job("// $this->doRun($argument);\n$this->logger->info('x');\n")
        self.assertTrue(gate.is_stub(src))

    def test_try_catch_with_nothing_inside(self):
        src = job(
            "        try {\n"
            "        } catch (\\Throwable $e) {\n"
            "            $this->logger->error('x');\n"
            "        }\n"
        )
        self.assertTrue(gate.is_stub(src))

    def test_control_flow_alone_is_not_work(self):
        """`if (` and `foreach (` look like calls to a naive matcher."""
        src = job(
            "        if ($argument === []) {\n"
            "            return;\n"
            "        }\n"
            "        $this->logger->info('nope');\n"
        )
        self.assertTrue(gate.is_stub(src))


class TestWhatMustNotBeFlagged(unittest.TestCase):
    def test_a_direct_service_call(self):
        self.assertFalse(gate.is_stub(job("        $this->service->dispatch($argument);\n")))

    def test_a_static_call(self):
        self.assertFalse(gate.is_stub(job("        Dispatcher::send($argument);\n")))

    def test_a_plain_function_call(self):
        self.assertFalse(gate.is_stub(job("        dispatch_now($argument);\n")))

    def test_a_non_logger_method_named_error(self):
        """`$mailer->error(...)` is work; only a logger receiver is discounted."""
        self.assertFalse(gate.is_stub(job("        $this->mailer->error($argument);\n")))

    def test_a_logger_method_that_is_not_a_log_line(self):
        self.assertFalse(gate.is_stub(job("        $this->logger->rotate();\n")))


class TestStructure(unittest.TestCase):
    def test_a_file_with_no_run_method_is_not_judged(self):
        src = "<?php\nclass X { public function handle(): void { } }\n"
        self.assertIsNone(gate.is_stub(src))

    def test_an_abstract_declaration_has_no_body_to_judge(self):
        src = "<?php\nabstract class X { abstract protected function run($a): void; }\n"
        self.assertIsNone(gate.is_stub(src))

    def test_braces_are_matched_not_guessed_by_indentation(self):
        """`awk '/function run\\(/,/^    }/'` guessed the close from four
        spaces. A job whose run() closes at a different indent had its body
        run to the end of the file — and then never looked like a stub."""
        src = (
            "<?php\n"
            "class X {\n"
            "  protected function run($a): void {\n"
            "    $this->logger->info('stub');\n"
            "  }\n"
            "  private function real(): void { $this->work(); }\n"
            "}\n"
        )
        self.assertTrue(gate.is_stub(src),
                        "the sibling method's work must not be read as run()'s")

    def test_a_string_containing_a_brace_does_not_end_the_body(self):
        """A `}` inside a message must not truncate the body — if it did, the
        work below it would be invisible and the job would read as a stub."""
        src = job(
            "        $this->logger->info('closing }');\n"
            "        $this->doRun($argument);\n"
        )
        self.assertFalse(gate.is_stub(src))


class TestCli(unittest.TestCase):
    def test_no_arguments_is_an_error(self):
        self.assertEqual(gate.main(["check_stub_run_body.py"]), 2)

    def test_an_unreadable_file_is_skipped(self):
        self.assertEqual(gate.scan_files(["/nope/Job.php"]), [])


if __name__ == "__main__":
    unittest.main()
