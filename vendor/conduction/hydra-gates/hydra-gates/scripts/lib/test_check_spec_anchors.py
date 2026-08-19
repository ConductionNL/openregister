#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_spec_anchors (gate-46). Run with:

    python3 scripts/lib/test_check_spec_anchors.py

or via pytest:

    python3 -m pytest scripts/lib/test_check_spec_anchors.py

BOTH WAYS, EVERY TIME
---------------------
Gate-46 was measured at 1,995 findings across 21 repos, 54% of them false.
Every relaxation below was written to clear a specific false-positive shape,
and every one of them ships PAIRED with a case that must still FAIL. A gate
that stops flagging is not a fixed gate, it is a dead one — and a dead gate
is indistinguishable from a passing repository, which is the defect this
whole package has spent a week fighting.

So each ``...Relaxed`` test class has a sibling assertion in the same class:

  * the false-positive input now resolves, AND
  * an input that differs only in the part the gate is supposed to check
    still does not resolve.

The fixtures are copied from real fleet specs (pipelinq's
``pos-payment-provider-adapter``, zaakafhandelapp's ``zgw-zaak-management``,
pipelinq's ``bi-export-and-data-warehouse-sink``) rather than written to
mirror the implementation's own regexes back at it.
"""
from __future__ import annotations

import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_spec_anchors as csa  # noqa: E402


def _anchor(md: str, fragment: str) -> bool:
    """Write *md* to a throwaway file and ask whether *fragment* resolves."""
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "spec.md"
        p.write_text(md, encoding="utf-8")
        return csa.has_anchor(str(p), fragment)


def _scan(tree: dict[str, str], source_rel: str, source_src: str) -> list[str]:
    """Materialise an openspec tree plus one annotated source file and scan."""
    with tempfile.TemporaryDirectory() as root:
        for rel, body in tree.items():
            p = Path(root) / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(body, encoding="utf-8")
        src = Path(root) / source_rel
        src.parent.mkdir(parents=True, exist_ok=True)
        src.write_text(source_src, encoding="utf-8")
        cwd = os.getcwd()
        try:
            os.chdir(root)
            return csa.scan_files([source_rel], root=root)
        finally:
            os.chdir(cwd)


# --------------------------------------------------------------------------
# Mode 1 — the `:`-tail rule accepted only EQUALITY where the full-heading
# rule accepted a PREFIX. 351 fleet findings.
# --------------------------------------------------------------------------
ZGW = """# ZGW Zaak Management

## Requirements

### Requirement: REQ-001: List and search zaken

### Requirement: REQ-005: Manage case-bound sub-resources
"""


class ColonTailPrefixParity(unittest.TestCase):
    def test_fp_the_id_before_the_second_colon_now_resolves(self):
        self.assertTrue(_anchor(ZGW, "REQ-001"))
        self.assertTrue(_anchor(ZGW, "REQ-005"))

    def test_tp_an_id_that_is_in_no_heading_still_fails(self):
        # REQ-002/3/4 exist in the real file; this fixture stops at 005.
        self.assertFalse(_anchor(ZGW, "REQ-002"))
        self.assertFalse(_anchor(ZGW, "REQ-999"))

    def test_tp_the_prefix_rule_still_respects_the_dash_boundary(self):
        # `#REQ-00` must not resolve against `REQ-001`: prefix matching is
        # only allowed to consume WHOLE dash-delimited segments.
        self.assertFalse(_anchor(ZGW, "REQ-00"))

    def test_regression_the_long_tail_still_resolves_by_prefix(self):
        head = "### Task 8 — implement the retry ladder\n"
        self.assertTrue(_anchor(head, "task-8"))
        self.assertFalse(_anchor(head, "task-89"))


# --------------------------------------------------------------------------
# Mode 2 — a requirement id in trailing parentheses. 944 fleet findings.
# --------------------------------------------------------------------------
POS_PAYMENT = """# POS Pluggable Payment Provider Specification

## ADDED Requirements

### Requirement: Payment Provider Adapter Interface (REQ-PAY-001)

#### Scenario: MollieAdapter implements PaymentProviderInterface

### Requirement: Provider Credential Storage & Encryption (REQ-PAY-002)

### Requirement: Refund and capture flows [REQ-PAY-003, REQ-PAY-004]
"""


class ParenthesisedRequirementIds(unittest.TestCase):
    def test_fp_a_parenthesised_id_now_resolves(self):
        self.assertTrue(_anchor(POS_PAYMENT, "REQ-PAY-001"))
        self.assertTrue(_anchor(POS_PAYMENT, "REQ-PAY-002"))

    def test_fp_a_bracketed_comma_list_resolves_each_member(self):
        self.assertTrue(_anchor(POS_PAYMENT, "REQ-PAY-003"))
        self.assertTrue(_anchor(POS_PAYMENT, "REQ-PAY-004"))

    def test_tp_an_id_no_heading_declares_still_fails(self):
        self.assertFalse(_anchor(POS_PAYMENT, "REQ-PAY-005"))
        self.assertFalse(_anchor(POS_PAYMENT, "REQ-CARD-001"))

    def test_tp_a_short_id_is_matched_by_equality_not_prefix(self):
        # This is the anti-blindness assertion for mode 2. If lifted tokens
        # were prefix-matched like full headings, `#REQ` would resolve
        # against REQ-PAY-001 and the gate would accept any tag beginning
        # with a live id's first segment.
        self.assertFalse(_anchor(POS_PAYMENT, "REQ"))
        self.assertFalse(_anchor(POS_PAYMENT, "REQ-PAY"))

    def test_regression_the_full_github_anchor_still_resolves(self):
        self.assertTrue(_anchor(
            POS_PAYMENT,
            "requirement-payment-provider-adapter-interface-req-pay-001",
        ))


# --------------------------------------------------------------------------
# Mode 3 — `- [~]` / `- [-]` checkboxes were invisible, which ALSO shifted
# every positional `#task-N` after them. 28 fleet findings, plus a silent
# mis-resolution class that produced no finding at all.
# --------------------------------------------------------------------------
TASKS = """# Tasks

- [x] 1.1 Write the mapper
- [~] 1.2 Wire the controller
- [ ] 1.3 Add the route
- [-] 1.4 Dropped: the legacy shim
"""


class PartialAndDroppedCheckboxes(unittest.TestCase):
    def test_fp_a_partial_item_id_now_resolves(self):
        self.assertTrue(_anchor(TASKS, "task-1.2"))

    def test_fp_a_dropped_item_id_now_resolves(self):
        self.assertTrue(_anchor(TASKS, "task-1.4"))

    def test_positional_resolution_counts_every_checkbox(self):
        # The important half: `#task-3` must land on 1.3, the THIRD item.
        # With `[~]` invisible the counter never incremented for 1.2, so
        # `#task-3` silently resolved to 1.4 — a wrong answer that reported
        # PASS, which is worse than the missing anchor it replaced.
        #
        # Proved by deleting the third item: `#task-3` must then fail.
        self.assertTrue(_anchor(TASKS, "task-3"))
        three_items = "\n".join(
            ln for ln in TASKS.splitlines() if "1.3" not in ln and "1.4" not in ln
        )
        self.assertFalse(_anchor(three_items, "task-3"))

    def test_tp_a_task_number_past_the_end_still_fails(self):
        self.assertFalse(_anchor(TASKS, "task-5"))
        self.assertFalse(_anchor(TASKS, "task-9.9"))


# --------------------------------------------------------------------------
# Mode 4 — apostrophes and slashes. GitHub DROPS them; the fleet's retrofit
# tooling turns them into `-`. Both spellings name the same heading, and an
# anchor built by dropping the character misses by exactly one and looks
# identical to a dangling reference.
# --------------------------------------------------------------------------
PUNCT = "## A subscription's retry/backoff policy\n"


class SlugPunctuation(unittest.TestCase):
    def test_fp_github_spelling_resolves(self):
        self.assertTrue(_anchor(PUNCT, "a-subscriptions-retrybackoff-policy"))

    def test_fp_kebab_spelling_resolves(self):
        self.assertTrue(_anchor(PUNCT, "a-subscription-s-retry-backoff-policy"))

    def test_tp_a_heading_that_does_not_exist_still_fails(self):
        self.assertFalse(_anchor(PUNCT, "a-subscriptions-retry-policy"))
        self.assertFalse(_anchor(PUNCT, "a-subscriptions-backoff-policy"))

    def test_slugify_turns_punctuation_into_a_separator(self):
        self.assertEqual(csa.slugify("A subscription's retry/backoff policy"),
                         "a-subscription-s-retry-backoff-policy")

    def test_gh_slugify_drops_punctuation_inside_a_word(self):
        self.assertEqual(csa.gh_slugify("A subscription's retry/backoff policy"),
                         "a-subscriptions-retrybackoff-policy")


# --------------------------------------------------------------------------
# Mode 5 — the flat/directory spec path shapes.
# --------------------------------------------------------------------------
SRC_TAG = """<?php
/**
 * @spec openspec/specs/task-collaboration.md#requirement-assign-a-task
 */
class Foo {}
"""
SRC_TAG_MISSING = """<?php
/**
 * @spec openspec/specs/no-such-capability.md#requirement-assign-a-task
 */
class Foo {}
"""
COLLAB = "# Task collaboration\n\n### Requirement: Assign a task\n"


class SpecPathShapes(unittest.TestCase):
    def test_fp_flat_tag_resolves_against_the_directory_form(self):
        findings = _scan(
            {"openspec/specs/task-collaboration/spec.md": COLLAB},
            "lib/Service/Foo.php", SRC_TAG,
        )
        self.assertEqual(findings, [])

    def test_fp_directory_tag_resolves_against_the_flat_form(self):
        src = SRC_TAG.replace("task-collaboration.md",
                              "task-collaboration/spec.md")
        findings = _scan(
            {"openspec/specs/task-collaboration.md": COLLAB},
            "lib/Service/Foo.php", src,
        )
        self.assertEqual(findings, [])

    def test_tp_a_spec_that_exists_in_neither_shape_still_fails(self):
        findings = _scan(
            {"openspec/specs/task-collaboration/spec.md": COLLAB},
            "lib/Service/Foo.php", SRC_TAG_MISSING,
        )
        self.assertEqual(len(findings), 1)
        self.assertIn("target file not found", findings[0])

    def test_tp_the_shape_fallback_does_not_excuse_a_bad_anchor(self):
        # The file is found via the other shape; the fragment still has to
        # exist in it.
        src = SRC_TAG.replace("requirement-assign-a-task",
                              "requirement-delete-a-task")
        findings = _scan(
            {"openspec/specs/task-collaboration/spec.md": COLLAB},
            "lib/Service/Foo.php", src,
        )
        self.assertEqual(len(findings), 1)
        self.assertIn("anchor not found", findings[0])


# --------------------------------------------------------------------------
# The id-like-token rule — `#### Scenario REQ-BIE-004-01: Cron triggers …`
# puts the id BEFORE the colon, as its own token.
# --------------------------------------------------------------------------
BI_EXPORT = """# BI export

#### Scenario REQ-BIE-004-01: Cron triggers export run creation

#### Scenario REQ-BIE-004-02: Worker picks up pending runs within 60 seconds

## BlastService (Task 2.3 of giant)
"""


class IdLikeTokensInHeadings(unittest.TestCase):
    def test_fp_a_pre_colon_id_token_resolves(self):
        self.assertTrue(_anchor(BI_EXPORT, "REQ-BIE-004-01"))
        self.assertTrue(_anchor(BI_EXPORT, "REQ-BIE-004-02"))

    def test_fp_an_id_inside_prose_parentheses_resolves(self):
        self.assertTrue(_anchor(BI_EXPORT, "task-2.3"))

    def test_tp_an_id_absent_from_every_heading_still_fails(self):
        self.assertFalse(_anchor(BI_EXPORT, "REQ-BIE-004-03"))
        self.assertFalse(_anchor(BI_EXPORT, "REQ-BIE-005-01"))
        self.assertFalse(_anchor(BI_EXPORT, "task-2.4"))

    def test_tp_prose_words_are_not_lifted_as_ids(self):
        # The anti-blindness assertion for this rule. Without the
        # digit requirement, every word of every heading before a colon
        # would become an anchor and the gate would resolve anything.
        self.assertFalse(_anchor(BI_EXPORT, "scenario"))
        self.assertFalse(_anchor(BI_EXPORT, "requirement"))
        self.assertFalse(_anchor(BI_EXPORT, "cron"))
        # `#blastservice` DOES resolve — `## BlastService (Task 2.3 of
        # giant)` is a real topic heading whose bracket carries provenance,
        # not title. That is the same class as `#webhooks` below.
        self.assertTrue(_anchor(BI_EXPORT, "blastservice"))
        self.assertFalse(_anchor(BI_EXPORT, "giant"))
        # ...but a fragment that names a real TOPIC heading still resolves:
        # `#webhooks` against `## Webhooks (Task 2.9 of giant)` is a working
        # anchor, so the structural-keyword exclusion is a keyword list and
        # not a ban on short fragments.
        self.assertTrue(_anchor("## Webhooks (Task 2.9 of giant)\n", "webhooks"))
        self.assertFalse(_anchor("### The retry and backoff policy: details\n",
                                 "retry"))
        self.assertFalse(_anchor("### The retry and backoff policy: details\n",
                                 "backoff"))

    def test_idlike_tokens_directly(self):
        self.assertEqual(csa._idlike_tokens("Scenario REQ-BIE-004-01"),
                         {"req-bie-004-01"})
        self.assertEqual(csa._idlike_tokens("Task 2.3 of giant"), {"2-3"})
        self.assertEqual(csa._idlike_tokens("The retry and backoff policy"),
                         set())


# --------------------------------------------------------------------------
# Behaviour that must survive every relaxation above.
# --------------------------------------------------------------------------
class PreservedBehaviour(unittest.TestCase):
    def test_a_missing_target_file_is_reported(self):
        findings = _scan(
            {"openspec/specs/other/spec.md": "# Other\n"},
            "lib/Foo.php",
            "<?php\n/** @spec openspec/specs/ghost/spec.md */\n",
        )
        self.assertEqual(len(findings), 1)
        self.assertIn("target file not found", findings[0])

    def test_an_archived_change_still_resolves(self):
        findings = _scan(
            {
                "openspec/changes/archive/2026-06-14-pos-payment-provider-adapter"
                "/specs/pos-payment-provider-adapter/spec.md": POS_PAYMENT,
            },
            "lib/Foo.php",
            "<?php\n/** @spec openspec/changes/pos-payment-provider-adapter"
            "/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001 */\n",
        )
        self.assertEqual(findings, [])

    def test_a_tag_with_no_fragment_only_needs_the_file(self):
        findings = _scan(
            {"openspec/specs/thing/spec.md": "# Thing\n"},
            "lib/Foo.php",
            "<?php\n/** @spec openspec/specs/thing/spec.md */\n",
        )
        self.assertEqual(findings, [])

    def test_spec_exclude_is_not_a_target(self):
        findings = _scan(
            {"openspec/specs/thing/spec.md": "# Thing\n"},
            "lib/Foo.php",
            "<?php\n/** @spec exclude pure DTO, no behaviour */\n",
        )
        self.assertEqual(findings, [])

    def test_a_wholly_invented_fragment_is_still_reported(self):
        findings = _scan(
            {"openspec/specs/thing/spec.md": ZGW},
            "lib/Foo.php",
            "<?php\n/** @spec openspec/specs/thing/spec.md"
            "#requirement-teleport-the-zaak */\n",
        )
        self.assertEqual(len(findings), 1)
        self.assertIn("anchor not found", findings[0])


class FragmentCopiedFromAGitHubLink(unittest.TestCase):
    """A tag copied from a real GitHub link must resolve.

    ``heading_aliases`` already emits both spellings of a heading — the kebab
    one and the one GitHub publishes — but the FRAGMENT was pushed through
    ``slugify()`` before comparison, and that rewrites ``_`` to ``-``. So a
    fragment matched neither alias: not the gh one (its underscore had been
    rewritten) and not the kebab one (which splits at the underscore).
    Observed on openconnector ``SynchronizationService`` (2026-08-07).
    """

    MD = (
        "# HTTP call engine\n\n"
        "### Requirement: Trace-scoped call correlation via call_log.sessionId (REQ-011)\n\n"
        "Body.\n"
    )

    def test_the_github_spelling_resolves(self):
        # What GitHub links to: underscore kept, dot dropped.
        self.assertTrue(_anchor(
            self.MD,
            "requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011",
        ))

    def test_the_kebab_spelling_still_resolves(self):
        self.assertTrue(_anchor(
            self.MD,
            "requirement-trace-scoped-call-correlation-via-call-log-sessionid-req-011",
        ))

    def test_a_near_miss_does_not_resolve(self):
        # The control. Accepting the raw fragment must not become "accept any
        # fragment that shares a prefix" — this one names a heading the file
        # does not have.
        self.assertFalse(_anchor(
            self.MD,
            "requirement-trace-scoped-call-correlation-via-call_logrequestid-req-011",
        ))

    def test_an_underscore_fragment_against_a_file_without_it_does_not_resolve(self):
        self.assertFalse(_anchor(
            "# Other\n\n### Requirement: Something else entirely (REQ-012)\n",
            "requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011",
        ))


class GateIsNotBlind(unittest.TestCase):
    """One consolidated demonstration that the gate still has teeth.

    If a future relaxation makes ``has_anchor`` return True unconditionally,
    every ``assertFalse`` above goes green individually only if someone
    deletes them. This test asserts the property directly: a spec file with
    real content must reject a fragment built from words that appear
    nowhere in it.
    """

    def test_random_fragments_do_not_resolve_against_a_real_spec(self):
        for frag in (
            "requirement-nonexistent",
            "REQ-ZZZ-999",
            "task-12345",
            "scenario-the-server-catches-fire",
            "zzzz",
            "",
        ):
            with self.subTest(frag=frag):
                self.assertFalse(_anchor(POS_PAYMENT, frag))
                self.assertFalse(_anchor(ZGW, frag))
                self.assertFalse(_anchor(BI_EXPORT, frag))


# --------------------------------------------------------------------------
# Mode 6 — `#T3` / `#T02`, the shorthand spelling of "Task N". 117 fleet
# findings (portaliq 76, procest 41). Paired controls below.
# --------------------------------------------------------------------------
PORTALIQ_TASKS = """# Tasks: contract-v2

## Implementation Tasks

### Task 1: Trust vocabulary + normalisation at the session edge
### Task 2: Registry v2 — multi-audience discovery + minTrust manifest filtering
### Task 3: Fail-closed trust re-checks on read and create paths
### Task 9: Demo provider v2 vocabulary + seed claims data
"""

PROCEST_TASKS = """# Tasks: process-mining-bottlenecks

## 1. Aggregation service
- [x] 1.1 `ProcessMiningService::getReport()`

## 2. Controller + routes
- [x] 2.1 `ProcessMiningController`

## 3. Frontend
- [x] 3.1 `ProcessMiningDashboard.vue`

## 4. Verification
- [x] 4.1 Manual smoke
"""


class TaskShorthandRelaxed(unittest.TestCase):
    """`#T3` names `### Task 3: …`; `#T02` names `## 2. …`.

    Both spellings are emitted by real annotation tooling and neither has
    ever resolved. The task is in the file; only the reference spelling is
    unusual — which is not what this gate is for.
    """

    def test_fp_portaliq_T_shorthand_resolves_against_a_task_heading(self):
        for frag in ("T1", "T2", "T3", "T9"):
            with self.subTest(frag=frag):
                self.assertTrue(_anchor(PORTALIQ_TASKS, frag))

    def test_fp_procest_zero_padded_shorthand_resolves_against_a_numbered_section(self):
        # Leading zeros stripped: the heading's lifted id token is `2`.
        for frag in ("T01", "T02", "T03", "T04"):
            with self.subTest(frag=frag):
                self.assertTrue(_anchor(PROCEST_TASKS, frag))

    def test_tp_a_task_number_the_file_does_not_have_still_fails(self):
        # THE CONTROL, and it is not hypothetical: procest really does carry
        # `#T05` against a file whose sections stop at 4, and that finding
        # survives this relaxation. If this ever goes green the rule has
        # stopped discriminating and the whole relaxation must come out.
        for frag in ("T05", "T5", "T99", "T0"):
            with self.subTest(frag=frag):
                self.assertFalse(_anchor(PROCEST_TASKS, frag))
        for frag in ("T4", "T10", "T99"):
            with self.subTest(frag=frag):
                self.assertFalse(_anchor(PORTALIQ_TASKS, frag))

    def test_tp_the_shorthand_is_not_wired_to_the_positional_checkbox_rule(self):
        # `#task-N` also means "the Nth checkbox". `T<n>` deliberately does
        # NOT, or `#T99` would resolve against any file with 99 checkboxes —
        # evidence about nothing. This file has 4 checkboxes and no `## 4.`
        # sibling for T-numbers above it, so a shorthand that leaked into the
        # positional rule would light up here.
        checkboxes = "# Tasks\n\n" + "".join(f"- [x] item {i}\n" for i in range(1, 5))
        for frag in ("T1", "T2", "T3", "T4"):
            with self.subTest(frag=frag):
                self.assertFalse(_anchor(checkboxes, frag))
        # …while the established `#task-N` positional spelling still works.
        self.assertTrue(_anchor(checkboxes, "task-3"))

    def test_tp_a_bare_T_is_not_a_task_reference(self):
        for frag in ("T", "TX", "Task", "trust"):
            with self.subTest(frag=frag):
                self.assertFalse(_anchor(PORTALIQ_TASKS, frag))


# --------------------------------------------------------------------------
# Mode 7 — the capability index: a spec resolves wherever archiving left it.
# 28 fleet findings (procest 18 + 10). This is the "survives archiving" rule.
# --------------------------------------------------------------------------
CAP_SPEC = """# Process Mining Bottlenecks

## ADDED Requirements

### Requirement: Per-status dwell-time statistics

#### Scenario: A closed case's dwell interval ends at the next transition
"""

TAGGED_PHP = """<?php
/**
 * @spec openspec/specs/process-mining-bottlenecks/spec.md#requirement-per-status-dwell-time-statistics
 */
class ProcessMiningService {}
"""


class CapabilityResolutionSurvivesArchiving(unittest.TestCase):
    """A tag written ONCE keeps resolving through the whole change lifecycle.

    This is acceptance criterion 3 for issue #228: after the SpecTagSniff is
    repointed at `openspec/specs/<cap>/spec.md`, archiving a change must not
    leave that anchor dangling. Before this rule it did — procest carries 18
    tags written exactly as the corrected sniff instructs, whose spec only
    ever existed inside the archived change directory.
    """

    def _lifecycle(self, spec_rel: str) -> list[str]:
        return _scan({spec_rel: CAP_SPEC}, "lib/Service/ProcessMiningService.php", TAGGED_PHP)

    def test_stage_1_in_flight_change_resolves(self):
        self.assertEqual(self._lifecycle(
            "openspec/changes/process-mining-bottlenecks/specs/"
            "process-mining-bottlenecks/spec.md"), [])

    def test_stage_2_archived_change_still_resolves(self):
        # THE ARCHIVING PROOF. Same tag, same anchor, spec moved to archive.
        self.assertEqual(self._lifecycle(
            "openspec/changes/archive/2026-07-14-process-mining-bottlenecks/specs/"
            "process-mining-bottlenecks/spec.md"), [])

    def test_stage_3_promoted_to_canonical_still_resolves(self):
        self.assertEqual(self._lifecycle(
            "openspec/specs/process-mining-bottlenecks/spec.md"), [])

    def test_a_capability_under_a_differently_named_change_resolves(self):
        # procest's `realtime-updates-ui` capability lives under the change
        # `adopt-live-updates-ui`. No CHANGE-keyed index can find it; a
        # CAPABILITY-keyed one can. 10 findings.
        self.assertEqual(self._lifecycle(
            "openspec/changes/adopt-live-updates-ui/specs/"
            "process-mining-bottlenecks/spec.md"), [])

    def test_tp_a_requirement_nobody_wrote_still_fails(self):
        # THE CONTROL THAT MATTERS MOST — doriath's shape, 77 findings.
        # The capability index finds the spec file in all three homes; the
        # anchor names a requirement that is in none of them. Widening WHERE
        # we look must never widen WHAT counts as resolved.
        bad = TAGGED_PHP.replace(
            "#requirement-per-status-dwell-time-statistics",
            "#requirement-listing-and-download")
        for spec_rel in (
            "openspec/specs/process-mining-bottlenecks/spec.md",
            "openspec/changes/archive/2026-07-14-process-mining-bottlenecks/"
            "specs/process-mining-bottlenecks/spec.md",
        ):
            with self.subTest(spec_rel=spec_rel):
                findings = _scan({spec_rel: CAP_SPEC},
                                 "lib/Service/ProcessMiningService.php", bad)
                self.assertEqual(len(findings), 1, findings)
                self.assertIn("anchor not found", findings[0])

    def test_tp_a_capability_that_exists_nowhere_still_fails(self):
        # larpingapp's shape: `manifest-v2-vue-scaffold` is in no home at all.
        findings = _scan({"openspec/specs/something-else/spec.md": CAP_SPEC},
                         "lib/Service/ProcessMiningService.php", TAGGED_PHP)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("target file not found", findings[0])

    def test_tp_the_index_does_not_redirect_a_tag_whose_own_path_resolves(self):
        # The capability index is LAST RESORT. When the tag's own path exists,
        # the anchor is judged against THAT file — otherwise a stale archived
        # copy could vouch for a requirement the canonical spec has dropped.
        canonical = "openspec/specs/process-mining-bottlenecks/spec.md"
        archived = ("openspec/changes/archive/2026-07-14-process-mining-bottlenecks/"
                    "specs/process-mining-bottlenecks/spec.md")
        findings = _scan(
            {canonical: "# Process Mining Bottlenecks\n\n## Requirements\n\n"
                        "### Requirement: Something completely different\n",
             archived: CAP_SPEC},
            "lib/Service/ProcessMiningService.php", TAGGED_PHP)
        self.assertEqual(len(findings), 1, findings)
        self.assertIn("anchor not found", findings[0])

    def test_capability_of_declines_per_change_documents(self):
        # tasks.md / design.md / proposal.md have no canonical home to
        # migrate to; the change-keyed archive index already resolves them.
        for p in ("openspec/changes/foo/tasks.md",
                  "openspec/changes/foo/design.md",
                  "openspec/changes/archive/2026-01-01-foo/proposal.md"):
            with self.subTest(p=p):
                self.assertIsNone(csa.capability_of(p))
        self.assertEqual(
            csa.capability_of("openspec/specs/bar/spec.md"), "bar")
        self.assertEqual(
            csa.capability_of("openspec/changes/foo/specs/bar/spec.md"), "bar")


if __name__ == "__main__":
    unittest.main(verbosity=2)
