#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_listener_placement (gate 61).

Run with: python3 test_check_listener_placement.py
"""
from __future__ import annotations

import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_listener_placement as clp  # noqa: E402


def _write(root: Path, rel: str, body: str) -> Path:
    path = root / rel
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(body, encoding='utf-8')
    return path


class ParseRegistrationsTest(unittest.TestCase):
    """Every registration DIALECT the fleet actually uses must be seen.

    A positional-only regex returns ZERO for procest, shillinq and pipelinq —
    all three use named arguments — and a fleet count taken with one lands at 69
    instead of 149. Each dialect below is asserted separately so a regression
    names the dialect it broke.
    """

    def _parse(self, php: str) -> list[dict]:
        with tempfile.TemporaryDirectory() as tmp:
            path = _write(Path(tmp), 'lib/AppInfo/Application.php', php)
            return clp.parse_registrations(path)

    def test_positional_registration_is_parsed(self):
        found = self._parse(
            '<?php\n'
            '$context->registerEventListener(ObjectCreatedEvent::class, FooListener::class);\n'
        )
        self.assertEqual(
            [(r['event'], r['listener']) for r in found],
            [('ObjectCreatedEvent', 'FooListener')],
        )

    def test_named_argument_registration_is_parsed(self):
        found = self._parse(
            '<?php\n'
            '$context->registerEventListener(\n'
            '    event: ObjectUpdatedEvent::class,\n'
            '    listener: FooListener::class\n'
            ');\n'
        )
        self.assertEqual(
            [(r['event'], r['listener']) for r in found],
            [('ObjectUpdatedEvent', 'FooListener')],
        )

    def test_fully_qualified_names_reduce_to_short_names(self):
        found = self._parse(
            '<?php\n'
            '$context->registerEventListener(\n'
            '    \\OCA\\OpenRegister\\Event\\ObjectDeletedEvent::class,\n'
            '    \\OCA\\Foo\\Listener\\FooListener::class\n'
            ');\n'
        )
        self.assertEqual(
            [(r['event'], r['listener']) for r in found],
            [('ObjectDeletedEvent', 'FooListener')],
        )

    def test_quoted_fqcn_event_is_parsed(self):
        """procest registers OpenRegister's optional events by STRING, so it
        carries no compile-time dependency on that app."""
        found = self._parse(
            '<?php\n'
            '$context->registerEventListener(\n'
            "    event: 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',\n"
            '    listener: FooListener::class\n'
            ');\n'
        )
        self.assertEqual(
            [(r['event'], r['listener']) for r in found],
            [('ObjectCreatedEvent', 'FooListener')],
        )

    def test_filtered_subscription_wrapper_is_parsed(self):
        """Converting a listener to FILTERED subscription must not make it
        invisible to the gate — the work is still synchronous when it matches."""
        found = self._parse(
            '<?php\n'
            '$this->registerFilteredObjectListener(\n'
            '    dispatcher: $dispatcher,\n'
            '    event: ObjectCreatedEvent::class,\n'
            '    listener: FooListener::class,\n'
            "    registers: ['foo'],\n"
            "    schemas: ['bar']\n"
            ');\n'
        )
        self.assertEqual(
            [(r['event'], r['listener']) for r in found],
            [('ObjectCreatedEvent', 'FooListener')],
        )

    def test_foreach_over_a_constant_attributes_every_post_event(self):
        """zaakafhandelapp registers ONE listener for six events by looping over
        a class constant. Reading only the literal argument scores it ZERO."""
        found = self._parse(
            '<?php\n'
            'private const OBJECT_EVENTS = [\n'
            '    \\OCA\\OpenRegister\\Event\\ObjectCreatedEvent::class,\n'
            '    \\OCA\\OpenRegister\\Event\\ObjectUpdatedEvent::class,\n'
            '    \\OCA\\OpenRegister\\Event\\ObjectDeletedEvent::class,\n'
            '    \\OCA\\OpenRegister\\Event\\ObjectCreatingEvent::class,\n'
            '];\n'
            'foreach (self::OBJECT_EVENTS as $event) {\n'
            '    $this->registerFilteredObjectListener(\n'
            '        dispatcher: $dispatcher,\n'
            '        event: $event,\n'
            '        listener: \\OCA\\Foo\\Listener\\FooListener::class,\n'
            '        registers: null,\n'
            '        schemas: null\n'
            '    );\n'
            '}\n'
        )
        self.assertEqual(
            sorted(r['event'] for r in found),
            ['ObjectCreatedEvent', 'ObjectDeletedEvent', 'ObjectUpdatedEvent'],
        )

    def test_the_wrapper_declaration_itself_is_not_a_registration(self):
        found = self._parse(
            '<?php\n'
            'private function registerFilteredObjectListener(\n'
            '    IEventDispatcher $dispatcher,\n'
            '    string $event,\n'
            '    string $listener\n'
            '): void {\n'
            '}\n'
        )
        self.assertEqual(found, [])


class AnnotationTest(unittest.TestCase):
    """The escape hatch must be auditable, not silent.

    Same contract as gate 16's `@spec exclude <reason>` and gate 19's
    `@e2e exclude <reason>`: a bare tag is a FAIL, not a pass.
    """

    def test_absent_annotation(self):
        self.assertEqual(clp.classify_annotation(['/** Handle it. */']), ('none', None))

    def test_bare_inline_has_no_category(self):
        block = ['/**', ' * @listener-placement inline', ' */']
        self.assertEqual(clp.classify_annotation(block), ('no-category', None))

    def test_bare_inline_followed_by_another_tag_has_no_category(self):
        block = ['/**', ' * @listener-placement inline', ' * @param Event $event', ' */']
        self.assertEqual(clp.classify_annotation(block), ('no-category', None))

    def test_category_without_a_reason_fails(self):
        block = ['/**', ' * @listener-placement inline sapi-memory', ' */']
        self.assertEqual(clp.classify_annotation(block), ('no-reason', 'sapi-memory'))

    def test_category_outside_the_closed_four_fails(self):
        block = ['/**', ' * @listener-placement inline performance — it is quick', ' */']
        self.assertEqual(clp.classify_annotation(block), ('bad-category', 'performance'))

    def test_valid_category_and_reason_passes(self):
        block = [
            '/**',
            ' * @listener-placement inline sapi-memory — SubscriptionService stores',
            ' *   via apcu_store and APCu is per-SAPI.',
            ' */',
        ]
        self.assertEqual(clp.classify_annotation(block), ('ok', 'sapi-memory'))

    def test_reason_wrapping_onto_the_next_line_still_counts(self):
        """Requiring the reason to fit on one line would push authors towards a
        terse unhelpful reason, which is the opposite of the point."""
        block = [
            '/**',
            ' * @listener-placement inline correctness',
            ' *   a prune landing after a same-UUID re-create wipes armed state',
            ' */',
        ]
        self.assertEqual(clp.classify_annotation(block), ('ok', 'correctness'))

    def test_every_valid_category_is_accepted(self):
        for category in sorted(clp.VALID_CATEGORIES):
            block = ['/**', f' * @listener-placement inline {category} — measured reason', ' */']
            with self.subTest(category=category):
                self.assertEqual(clp.classify_annotation(block), ('ok', category))

    def test_the_closed_set_is_literally_the_four_adr_078_categories(self):
        """Assert the LITERAL names, not `for c in VALID_CATEGORIES`.

        Iterating the set under test is tautological: it passes for whatever
        strings the set happens to contain. This gate shipped its first draft
        with `read-after-write` in place of `cheap-bounded` — a category that
        appears nowhere in ADR-078 — and every category test still passed,
        because they all read the set rather than the ADR. The failure mode is
        silent in the worst direction: a correctly-annotated `cheap-bounded`
        listener would have been rejected as a bad category, and an invented
        `read-after-write` justification would have been accepted.

        Source of truth: ADR-078 D2, table `Category | Why inline`.
        """
        self.assertEqual(
            clp.VALID_CATEGORIES,
            {'realtime', 'sapi-memory', 'cheap-bounded', 'correctness'},
        )

    def test_cheap_bounded_is_accepted_by_its_literal_name(self):
        block = ['/**',
                 ' * @listener-placement inline cheap-bounded — one fail-soft INSERT;'
                 ' a job row plus a cron round-trip costs more than the work',
                 ' */']
        self.assertEqual(clp.classify_annotation(block), ('ok', 'cheap-bounded'))

    def test_read_after_write_is_not_a_category(self):
        """The string that was wrongly shipped must be actively rejected."""
        block = ['/**', ' * @listener-placement inline read-after-write — needs it', ' */']
        self.assertEqual(
            clp.classify_annotation(block), ('bad-category', 'read-after-write'))


class ScanBodyTest(unittest.TestCase):
    """Signals must use word boundaries.

    A prior gate in this repo matched `dd(` inside `->add(` and produced a wave
    of phantom findings; these are the regression tests for that class of bug.
    """

    def test_outbound_http_via_new_client_is_flagged(self):
        found = clp.scan_body('$c = $this->clients->newClient();')
        self.assertEqual([k for k, _ in found], ['outbound-io'])

    def test_save_object_is_flagged(self):
        found = clp.scan_body('$this->objectService->saveObject($o);')
        self.assertEqual([k for k, _ in found], ['write'])

    def test_unbounded_find_all_is_flagged(self):
        found = clp.scan_body("$this->objectService->findAll(config: ['filters' => []]);")
        self.assertEqual([k for k, _ in found], ['unbounded-query'])

    def test_find_all_with_a_limit_is_not_flagged(self):
        found = clp.scan_body("$this->objectService->findAll(['limit' => 10]);")
        self.assertEqual(found, [])

    def test_update_status_is_not_a_database_update(self):
        """`->update(` must not match `->updateStatus(` — the substring trap."""
        found = clp.scan_body('$this->service->updateStatus($id);')
        self.assertEqual(found, [])

    def test_insert_before_is_not_a_database_insert(self):
        found = clp.scan_body('$this->list->insertBefore($node);')
        self.assertEqual(found, [])

    def test_signals_named_only_in_a_comment_are_not_findings(self):
        found = clp.scan_body('// this used to call saveObject() on every write\n$x = 1;')
        self.assertEqual(found, [])

    def test_a_plain_handler_is_clean(self):
        found = clp.scan_body('$this->cache->remove($event->getObject()->getUuid());')
        self.assertEqual(found, [])


class AnalyseListenerTest(unittest.TestCase):
    def _analyse(self, php: str) -> dict:
        with tempfile.TemporaryDirectory() as tmp:
            path = _write(Path(tmp), 'lib/Listener/FooListener.php', php)
            return clp.analyse_listener(path)

    def test_constructor_injection_alone_is_not_a_finding(self):
        """A listener that merely HOLDS an IClientService does no I/O. Scanning
        the constructor would flag every listener in the fleet."""
        info = self._analyse(
            '<?php\n'
            'class FooListener {\n'
            '    public function __construct(private readonly IClientService $c) {\n'
            '    }\n'
            '    public function handle(Event $event): void {\n'
            '        $this->cache->clear();\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(info['findings'], [])

    def test_work_in_a_private_helper_is_still_a_finding(self):
        """`handle()` delegating to `$this->doWork()` must not launder the work."""
        info = self._analyse(
            '<?php\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            '        $this->doWork();\n'
            '    }\n'
            '    private function doWork(): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual([k for k, _ in info['findings']], ['write'])

    def test_deferral_routing_is_detected(self):
        info = self._analyse(
            '<?php\n'
            'use OCA\\OpenRegister\\Service\\Deferral\\ListenerDeferralService;\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            "        $this->deferral->defer('foo', $event);\n"
            '    }\n'
            '}\n'
        )
        self.assertTrue(info['defers'])

    def test_naming_the_service_without_calling_defer_is_not_deferral(self):
        """Holding the dependency is not using it — the green-but-dead shape."""
        info = self._analyse(
            '<?php\n'
            'use OCA\\OpenRegister\\Service\\Deferral\\ListenerDeferralService;\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertFalse(info['defers'])

    def test_handler_annotation_is_read_from_its_own_docblock(self):
        info = self._analyse(
            '<?php\n'
            'class FooListener {\n'
            '    /**\n'
            '     * @listener-placement inline realtime — this IS the realtime channel\n'
            '     */\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual([h['status'] for h in info['handlers']], ['ok'])


class EndToEndTest(unittest.TestCase):
    """The gate's own verdict, exercised through main()."""

    def _run(self, listener_body: str) -> int:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write(root, 'lib/AppInfo/Application.php',
                   '<?php\n'
                   '$context->registerEventListener(\n'
                   '    event: ObjectCreatedEvent::class,\n'
                   '    listener: FooListener::class\n'
                   ');\n')
            _write(root, 'lib/Listener/FooListener.php', listener_body)
            argv = sys.argv
            sys.argv = ['check_listener_placement.py', str(root), '--all']
            try:
                return clp.main()
            finally:
                sys.argv = argv

    def test_undeferred_unjustified_post_listener_fails(self):
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 1)

    def test_the_same_listener_with_a_reason_passes(self):
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    /**\n'
            '     * @listener-placement inline correctness — a prune landing after a\n'
            '     *   same-UUID re-create wipes freshly-armed state\n'
            '     */\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 0)

    def test_a_listener_doing_no_real_work_passes(self):
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            '        $this->cache->remove($event->getObject()->getUuid());\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 0)

    # --- the escape hatch, end to end -------------------------------------
    #
    # `classify_annotation` returning 'no-category' is not the same claim as
    # the GATE failing: main() has to reach that status and turn it into a
    # non-zero exit. An escape hatch that is only tested at the classifier
    # level can be wired into main() incorrectly and still look covered.

    def test_a_bare_inline_annotation_fails_the_gate(self):
        """A bare `@listener-placement inline` MUST NOT buy silence.

        This is the whole point of the hatch being reason-bearing: without
        this case, `inline` degrades into a one-word opt-out of ADR-078.
        """
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    /**\n'
            '     * @listener-placement inline\n'
            '     */\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 1)

    def test_a_category_with_no_reason_fails_the_gate(self):
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    /**\n'
            '     * @listener-placement inline sapi-memory\n'
            '     */\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 1)

    def test_an_invented_category_fails_the_gate(self):
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    /**\n'
            '     * @listener-placement inline performance — it is only a few ms\n'
            '     */\n'
            '    public function handle(Event $event): void {\n'
            '        $this->objectService->saveObject($o);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 1)

    def test_routing_through_the_deferral_service_passes(self):
        """The PREFERRED answer, not the annotation, must also be accepted."""
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            '        $this->deferral->defer(ObjectCleanupJob::class, $event);\n'
            '    }\n'
            '}\n'
            '// ListenerDeferralService\n'
        )
        self.assertEqual(rc, 0)

    def test_outbound_http_with_no_justification_fails(self):
        rc = self._run(
            '<?php\n'
            'class FooListener {\n'
            '    public function handle(Event $event): void {\n'
            '        $this->clients->newClient()->post($url, []);\n'
            '    }\n'
            '}\n'
        )
        self.assertEqual(rc, 1)


class DiffScopeTest(unittest.TestCase):
    """ADR-020: the gate is about NEW debt.

    The fleet carries 149 registrations. If the gate re-opened all of them on
    every PR it would be turned off within a week, which is the failure mode
    ADR-020 exists to prevent.
    """

    def _repo(self, tmp: Path) -> Path:
        root = tmp
        _write(root, 'lib/AppInfo/Application.php',
               '<?php\n'
               '$context->registerEventListener(\n'
               '    event: ObjectCreatedEvent::class,\n'
               '    listener: FooListener::class\n'
               ');\n')
        _write(root, 'lib/Listener/FooListener.php',
               '<?php\n'
               'class FooListener {\n'
               '    public function handle(Event $event): void {\n'
               '        $this->objectService->saveObject($o);\n'
               '    }\n'
               '}\n')
        return root

    def _git(self, root: Path, *args: str) -> None:
        import subprocess
        subprocess.run(['git', *args], cwd=str(root), check=True,
                       capture_output=True, text=True)

    def _run(self, root: Path, base: str) -> int:
        argv = sys.argv
        sys.argv = ['check_listener_placement.py', str(root), '--base', base]
        try:
            return clp.main()
        finally:
            sys.argv = argv

    def test_an_untouched_violating_registration_does_not_block(self):
        """The legacy backlog must not fail a PR that did not touch it."""
        with tempfile.TemporaryDirectory() as tmp:
            root = self._repo(Path(tmp))
            # No `-b main`: that flag needs git >= 2.28 and CI images here run
            # 2.25. The initial branch name is irrelevant — every assertion
            # below diffs against the explicitly-created `base` branch.
            self._git(root, 'init', '-q')
            self._git(root, 'config', 'user.email', 't@e.st')
            self._git(root, 'config', 'user.name', 'T')
            self._git(root, 'add', '-A')
            self._git(root, 'commit', '-qm', 'baseline with the violation already in it')
            self._git(root, 'branch', 'base')
            # A later commit that touches something unrelated entirely.
            _write(root, 'README.md', 'unrelated\n')
            self._git(root, 'add', 'README.md')
            self._git(root, 'commit', '-qm', 'unrelated change')
            # EXIT_EMPTY_SCOPE (3), not 0 (.github#276).
            #
            # Both are non-blocking, and this test's intent — "the legacy
            # backlog must not fail a PR that did not touch it" — is unchanged:
            # the runner maps 3 to `_skip … na`, which does not count against
            # --require-full-coverage. What changed is that 0 no longer has to
            # mean two different things. It used to mean BOTH "I inspected the
            # registrations and they were clean" and "the diff put all of them
            # out of scope, so I inspected none", and the runner printed PASS
            # for both — a verdict about work placement over a scope no run
            # opened.
            self.assertEqual(self._run(root, 'base'), clp.EXIT_EMPTY_SCOPE)

    def test_touching_the_listener_file_pulls_it_into_scope(self):
        """...and the same violation then fails. Same repo, same listener —
        the ONLY difference is that the PR touched it."""
        with tempfile.TemporaryDirectory() as tmp:
            root = self._repo(Path(tmp))
            # No `-b main`: that flag needs git >= 2.28 and CI images here run
            # 2.25. The initial branch name is irrelevant — every assertion
            # below diffs against the explicitly-created `base` branch.
            self._git(root, 'init', '-q')
            self._git(root, 'config', 'user.email', 't@e.st')
            self._git(root, 'config', 'user.name', 'T')
            self._git(root, 'add', '-A')
            self._git(root, 'commit', '-qm', 'baseline')
            self._git(root, 'branch', 'base')
            _write(root, 'lib/Listener/FooListener.php',
                   '<?php\n'
                   'class FooListener {\n'
                   '    public function handle(Event $event): void {\n'
                   '        $this->objectService->saveObject($o);\n'
                   '        $this->logger->info("now with logging");\n'
                   '    }\n'
                   '}\n')
            self._git(root, 'add', '-A')
            self._git(root, 'commit', '-qm', 'touch the listener')
            self.assertEqual(self._run(root, 'base'), 1)

    def test_an_unresolvable_base_fails_closed(self):
        """hydra#399's lesson. An unresolvable base yields an EMPTY changed-line
        set, every registration falls out of scope, and the gate reports PASS
        having inspected nothing — indistinguishable from a clean PR."""
        with tempfile.TemporaryDirectory() as tmp:
            root = self._repo(Path(tmp))
            # No `-b main`: that flag needs git >= 2.28 and CI images here run
            # 2.25. The initial branch name is irrelevant — every assertion
            # below diffs against the explicitly-created `base` branch.
            self._git(root, 'init', '-q')
            self._git(root, 'config', 'user.email', 't@e.st')
            self._git(root, 'config', 'user.name', 'T')
            self._git(root, 'add', '-A')
            self._git(root, 'commit', '-qm', 'baseline')
            self.assertEqual(self._run(root, 'no/such/ref'), 1)


class PreEventTest(unittest.TestCase):
    """`*ing` listeners MAY veto or mutate, so they MUST stay synchronous and
    the gate must never nudge them off the request path."""

    def test_pre_events_are_not_in_the_gated_set(self):
        for event in ('ObjectCreatingEvent', 'ObjectUpdatingEvent', 'ObjectDeletingEvent'):
            with self.subTest(event=event):
                self.assertNotIn(event, clp.POST_EVENTS)

    def test_all_three_post_events_are_gated(self):
        self.assertEqual(
            clp.POST_EVENTS,
            {'ObjectCreatedEvent', 'ObjectUpdatedEvent', 'ObjectDeletedEvent'},
        )


if __name__ == '__main__':
    unittest.main(verbosity=2)
