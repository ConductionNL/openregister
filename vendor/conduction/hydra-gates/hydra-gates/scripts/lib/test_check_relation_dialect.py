#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for gate-54 helper check_relation_dialect.py.

Focus: the recursive property collector added for issue #231.

Before the fix, ``property_ids`` was built by a single non-recursive loop over
``schema.properties.*`` while ``_raw_walk`` recursed into the whole document.
That made the gate wrong in both directions at the same time:

  * a CORRECT nested relation was reported as "placed off a property"
    unconditionally (structurally unrepresentable to the check — the finding
    could not be cleared without mangling correct schema);
  * a nested relation MISSING its ``$ref``, or carrying a dangling ``$ref`` or
    a bad filter token, was never inspected at all.

The suite therefore pins three things that must all hold together, because
widening a checker until it catches nothing is not a fix:

  1. TRUE POSITIVES SURVIVE — a filter riding on an ``x-*`` block, or on the
     ``items`` subschema itself (which is NOT a property), is still reported.
  2. FALSE POSITIVE GONE   — the real larpingapp node
     ``character.requirementOverrides.items.properties.skill`` passes.
  3. FALSE NEGATIVE CLOSED — nested shape/$ref/token defects are now reported.
"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_relation_dialect as g  # noqa: E402


# --------------------------------------------------------------------------
# Fixtures
# --------------------------------------------------------------------------
def _flat_relation():
    """`character.skills` as larpingapp actually authors it — the shape the
    gate already accepted before the fix. Used as the CONTROL: the nested case
    below differs from it only in nesting depth."""
    return {
        "type": "array",
        "description": "Skills this character possesses",
        "items": {"type": "string", "format": "uuid"},
        "$ref": "skill",
        "x-relation-filter": {"setting": "@object.setting"},
        "title": "Skills",
    }


def _nested_relation(skill_prop=None):
    """`character.requirementOverrides` — an array of objects whose element
    carries a canonical relation property. This is the node that produced the
    false positive in issue #231."""
    return {
        "type": "array",
        "description": "Audited GM overrides of unmet skill requirements.",
        "items": {
            "type": "object",
            "properties": {
                "skill": skill_prop if skill_prop is not None else {
                    "type": "string",
                    "format": "uuid",
                    "$ref": "skill",
                    "x-relation-filter": {"setting": "@object.setting"},
                    "description": "The assigned skill whose requirements are waived",
                    "title": "Skill",
                },
                "reason": {"type": "string", "title": "Override Reason"},
            },
        },
        "title": "Requirement Overrides",
    }


def _register(character_props):
    props = {
        "setting": {
            "type": "string",
            "format": "uuid",
            "$ref": "setting",
            "title": "Setting",
        },
    }
    props.update(character_props)
    return {
        "components": {
            "schemas": {
                "character": {"title": "Character", "properties": props},
                "skill": {"title": "Skill", "properties": {"name": {"type": "string"}}},
                "setting": {"title": "Setting", "properties": {"name": {"type": "string"}}},
            }
        }
    }


KEYS = {"character", "skill", "setting"}


class _Base(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self._tmp.name)

    def tearDown(self):
        self._tmp.cleanup()

    def run_check(self, doc, keys=None):
        """Write `doc` to a register file and return the finding messages."""
        path = self.dir / "app_register.json"
        path.write_text(json.dumps(doc, indent=2, ensure_ascii=False), encoding="utf-8")
        findings = []
        g.check_file(str(path), KEYS if keys is None else keys, findings, "")
        return [msg for _p, msg in findings]


# --------------------------------------------------------------------------
# 1. The false positive (issue #231) — and its control.
# --------------------------------------------------------------------------
class NestedRelationAcceptedTest(_Base):
    def test_flat_relation_is_clean(self):
        """CONTROL. If this ever goes red the fixture, not the fix, is wrong."""
        msgs = self.run_check(_register({"skills": _flat_relation()}))
        self.assertEqual(msgs, [], f"flat relation must be clean, got {msgs}")

    def test_nested_relation_is_clean(self):
        """The larpingapp node. Byte-for-byte the flat shape, one level deeper."""
        msgs = self.run_check(_register({"requirementOverrides": _nested_relation()}))
        self.assertEqual(msgs, [], f"nested relation must be clean, got {msgs}")

    def test_nested_relation_is_not_reported_as_misplaced(self):
        """Pins the exact message that made the finding unfixable in the app."""
        msgs = self.run_check(_register({"requirementOverrides": _nested_relation()}))
        self.assertFalse(
            any("placed off a property" in m for m in msgs),
            f"nested relation reported as misplaced: {msgs}",
        )

    def test_doubly_nested_relation_is_clean(self):
        """Arrays of objects nest arbitrarily; the collector must too."""
        inner = _nested_relation()
        doc = _register({
            "chapters": {
                "type": "array",
                "items": {"type": "object", "properties": {"overrides": inner}},
                "title": "Chapters",
            }
        })
        msgs = self.run_check(doc)
        self.assertEqual(msgs, [], f"doubly-nested relation must be clean, got {msgs}")

    def test_inline_object_property_relation_is_clean(self):
        """`type: object` + `properties` (no array) is the same blind spot."""
        doc = _register({
            "origin": {
                "type": "object",
                "title": "Origin",
                "properties": {
                    "skill": {
                        "type": "string",
                        "format": "uuid",
                        "$ref": "skill",
                        "x-relation-filter": {"setting": "@object.setting"},
                        "title": "Skill",
                    }
                },
            }
        })
        msgs = self.run_check(doc)
        self.assertEqual(msgs, [], f"inline-object relation must be clean, got {msgs}")


# --------------------------------------------------------------------------
# 2. True positives must survive the widening.
# --------------------------------------------------------------------------
class TruePositivesSurviveTest(_Base):
    def test_filter_on_an_x_block_is_still_reported(self):
        """A schema-level x-* block is not a property, at any depth."""
        doc = _register({"skills": _flat_relation()})
        doc["components"]["schemas"]["character"]["x-openregister-ui"] = {
            "x-relation-filter": {"setting": "@object.setting"}
        }
        msgs = self.run_check(doc)
        self.assertTrue(
            any("placed off a property" in m for m in msgs),
            f"filter on an x-* block must still be reported, got {msgs}",
        )

    def test_filter_on_the_items_node_itself_is_still_reported(self):
        """`items` is a subschema, NOT a property. The filter must ride the
        property. This is the genuine rule-6 violation the fix must not
        swallow — it lives inside `items`, exactly where the collector now
        descends."""
        bad = _nested_relation()
        bad["items"]["x-relation-filter"] = {"setting": "@object.setting"}
        msgs = self.run_check(_register({"requirementOverrides": bad}))
        self.assertTrue(
            any("placed off a property" in m for m in msgs),
            f"filter on the items node must still be reported, got {msgs}",
        )

    def test_filter_on_a_non_property_node_inside_items_is_still_reported(self):
        """Not every dict under `items.properties` reachable by _raw_walk is a
        property: an x-* block nested one level further is not."""
        bad = _nested_relation()
        bad["items"]["properties"]["skill"]["x-openregister-ui"] = {
            "x-relation-filter": {"setting": "@object.setting"}
        }
        msgs = self.run_check(_register({"requirementOverrides": bad}))
        self.assertTrue(
            any("placed off a property" in m for m in msgs),
            f"filter on a nested x-* block must still be reported, got {msgs}",
        )

    def test_placement_finding_names_the_offending_node(self):
        """Three identical unlocated lines, three different nodes, is what made
        issue #231 hard to triage. The finding must carry a JSON pointer."""
        bad = _nested_relation()
        bad["items"]["x-relation-filter"] = {"setting": "@object.setting"}
        msgs = self.run_check(_register({"requirementOverrides": bad}))
        self.assertTrue(
            any("/components/schemas/character/properties/"
                "requirementOverrides/items" in m for m in msgs),
            f"placement finding must name the node, got {msgs}",
        )

    def test_banned_dialect_still_reported(self):
        doc = _register({"skills": _flat_relation()})
        doc["components"]["schemas"]["character"]["x-openregister-relations"] = {}
        msgs = self.run_check(doc)
        self.assertTrue(
            any("banned dialect" in m for m in msgs), f"got {msgs}"
        )


# --------------------------------------------------------------------------
# 3. False negatives closed — nested defects are now inspected.
# --------------------------------------------------------------------------
class NestedFalseNegativesTest(_Base):
    def test_nested_relation_missing_ref_is_reported(self):
        """THE thing that matters: a nested relation with no `$ref`. Silent
        before the fix, because check (b) never saw nested properties."""
        doc = _register({"requirementOverrides": _nested_relation({
            "type": "string",
            "format": "uuid",
            "description": "Reference to the skill whose requirements are waived",
            "title": "Skill",
        })})
        msgs = self.run_check(doc)
        self.assertTrue(
            any("lacks canonical $ref" in m and "requirementOverrides.items.skill" in m
                for m in msgs),
            f"nested missing-$ref must be reported, got {msgs}",
        )

    def test_nested_dangling_ref_is_reported(self):
        doc = _register({"requirementOverrides": _nested_relation({
            "type": "string",
            "format": "uuid",
            "$ref": "nosuchschema",
            "title": "Skill",
        })})
        msgs = self.run_check(doc)
        self.assertTrue(
            any("does not resolve" in m and "nosuchschema" in m for m in msgs),
            f"nested dangling $ref must be reported, got {msgs}",
        )

    def test_nested_unknown_filter_token_is_reported(self):
        bad = _nested_relation()
        bad["items"]["properties"]["skill"]["x-relation-filter"] = {"setting": "@nope"}
        msgs = self.run_check(_register({"requirementOverrides": bad}))
        self.assertTrue(
            any("unknown token" in m for m in msgs),
            f"nested bad filter token must be reported, got {msgs}",
        )

    def test_nested_filter_token_resolves_against_the_ROOT_schema(self):
        """`@object` is the object under edit, not the array element. The real
        larpingapp filter is `@object.setting` and `setting` is a property of
        `character`, not of the array element — resolving against the element
        would manufacture a fresh false positive."""
        clean = self.run_check(_register({"requirementOverrides": _nested_relation()}))
        self.assertFalse(
            any("nonexistent field" in m for m in clean),
            f"root-level field must resolve, got {clean}",
        )
        bad = _nested_relation()
        bad["items"]["properties"]["skill"]["x-relation-filter"] = {
            "reason": "@object.reason"  # a sibling of the ELEMENT, not of the root
        }
        msgs = self.run_check(_register({"requirementOverrides": bad}))
        self.assertTrue(
            any("nonexistent field" in m and "'reason'" in m for m in msgs),
            f"element-level field must NOT resolve, got {msgs}",
        )


# --------------------------------------------------------------------------
# 4. Collector mechanics — termination guards and scope discipline.
# --------------------------------------------------------------------------
class CollectorTest(unittest.TestCase):
    def test_collects_nested_names_qualified(self):
        props = _register({"requirementOverrides": _nested_relation()})[
            "components"]["schemas"]["character"]["properties"]
        out = []
        g._collect_properties(props, "", 0, set(), out)
        names = [q for q, _p, _l, _d in out]
        self.assertIn("requirementOverrides", names)
        self.assertIn("requirementOverrides.items.skill", names)
        self.assertIn("requirementOverrides.items.reason", names)

    def test_items_itself_is_not_collected_as_a_property(self):
        props = _register({"requirementOverrides": _nested_relation()})[
            "components"]["schemas"]["character"]["properties"]
        out = []
        g._collect_properties(props, "", 0, set(), out)
        items = props["requirementOverrides"]["items"]
        self.assertNotIn(id(items), {id(p) for _q, p, _l, _d in out},
                         "the items subschema must never count as a property")

    def test_terminates_on_a_cycle(self):
        """A parsed JSON file is a tree, but the collector is also called on
        hand-assembled dicts. A cycle must terminate, not recurse forever."""
        loop = {"type": "object"}
        loop["properties"] = {"self": loop}
        out = []
        g._collect_properties({"root": loop}, "", 0, set(), out)
        self.assertGreaterEqual(len(out), 1)
        self.assertLess(len(out), 50, "cycle guard did not stop the walk")

    def test_depth_is_capped(self):
        node = {"type": "string"}
        for _ in range(g._MAX_PROPERTY_DEPTH + 20):
            node = {"type": "object", "properties": {"p": node}}
        out = []
        g._collect_properties({"root": node}, "", 0, set(), out)
        self.assertLessEqual(len(out), g._MAX_PROPERTY_DEPTH + 2,
                             f"depth cap not applied: collected {len(out)}")

    def test_tuple_form_items_list_is_walked(self):
        props = {
            "pairs": {
                "type": "array",
                "items": [{"type": "object", "properties": {"skill": {"type": "string"}}}],
            }
        }
        out = []
        g._collect_properties(props, "", 0, set(), out)
        self.assertIn("pairs.items.skill", [q for q, _p, _l, _d in out])


class ScopeDisciplineTest(_Base):
    def test_beyond_the_depth_cap_over_reports_rather_than_hanging(self):
        """The depth cap must fail in the SAFE direction. Past the bound a
        nested property is no longer collected, so `_raw_walk` reports its
        filter as misplaced — an over-report a human can dismiss. Silently
        accepting everything past the bound would be the dangerous direction,
        and it would look exactly like a pass."""
        deep = _nested_relation()
        for _ in range(g._MAX_PROPERTY_DEPTH + 4):
            deep = {"type": "array",
                    "items": {"type": "object", "properties": {"inner": deep}}}
        msgs = self.run_check(_register({"veryDeep": dict(deep, title="Very deep")}))
        self.assertTrue(
            any("placed off a property" in m for m in msgs),
            f"beyond-bound relation must be over-reported, got {msgs}",
        )

    def test_lifecycle_check_stays_top_level(self):
        """Rule-10 names a property of the SCHEMA. A nested element property
        that happens to share the lifecycle field's name must not trip it."""
        doc = _register({"requirementOverrides": _nested_relation({
            "type": "string",
            "$ref": "setting",
            "readOnly": True,
            "title": "Status",
        })})
        doc["components"]["schemas"]["character"]["x-openregister-lifecycle"] = {
            "field": "requirementOverrides.items.skill"
        }
        msgs = self.run_check(doc)
        self.assertFalse(any("permanently frozen" in m for m in msgs),
                         f"rule-10 must not fire on a nested property: {msgs}")


# --------------------------------------------------------------------------
# 5. Finding count vs defect count (the gate-53 lesson).
# --------------------------------------------------------------------------
class OneDefectOneFindingTest(_Base):
    def test_missing_ref_with_a_filter_emits_one_finding_not_two(self):
        """A property with `x-relation-filter` and no `$ref` matches check (b)
        ("lacks canonical $ref") AND check (c) ("filter is inert"). Same defect,
        same fix — it must be counted once."""
        doc = _register({"badRel": {
            "type": "string",
            "format": "uuid",
            "description": "Reference to the skill",
            "x-relation-filter": {"setting": "@object.setting"},
            "title": "Bad rel",
        }})
        msgs = self.run_check(doc)
        self.assertEqual(len(msgs), 1, f"one defect must emit one finding, got {msgs}")
        self.assertIn("lacks canonical $ref", msgs[0])

    def test_inert_filter_alone_is_still_reported(self):
        """The de-duplication must not silence check (c) when check (b) did not
        fire (no relation-shaped description, so (b) stays conservative)."""
        doc = _register({"plainProp": {
            "type": "string",
            "x-relation-filter": {"setting": "@object.setting"},
            "title": "Plain",
        }})
        msgs = self.run_check(doc)
        self.assertTrue(any("inert" in m for m in msgs),
                        f"inert filter must still be reported, got {msgs}")


# --------------------------------------------------------------------------
# 5b. Cross-app references — the gate must be CLOSABLE.
#
# Measured 2026-08-09 on docudesk (gate package e7bde0a): two properties
# reference a Zaak/case that lives in Procest's register, not DocuDesk's, and
# say so with `x-external-register: procest`. There was no way to author them:
#
#   WITH    "$ref": "case"  -> "$ref 'case' does not resolve to a schema key
#                              in the register set (case-exact)"        (f)
#   WITHOUT "$ref"          -> "relation-shaped property (format:uuid +
#                              relation description) lacks canonical $ref"  (b)
#
# Both arms fail, so the only way to reach green was to reword the description
# until `_RELATION_DESC_RE` stopped matching — degrading documentation to dodge
# a regex, which is exactly what a gate must never reward. `x-external-register`
# appeared ZERO times in this checker: it had no concept of a cross-app
# reference at all.
#
# The rule these tests pin: OpenRegister resolves `$ref` inside ONE register
# set, so a foreign schema is not expressible as a relation. A property that
# declares `x-external-register` is therefore a plain identifier — it must NOT
# carry a `$ref`, and it must not be asked for one.
# --------------------------------------------------------------------------
class CrossAppReferenceTest(_Base):
    EXTERNAL = {
        "type": "string",
        "format": "uuid",
        "description": "UUID of the source Zaak (case) in Procest",
        "x-external-register": "procest",
        "title": "Case reference",
    }

    def test_external_reference_without_ref_is_accepted(self):
        """The correct authoring must PASS — this is the closable arm."""
        doc = _register({"caseReference": dict(self.EXTERNAL)})
        msgs = self.run_check(doc)
        self.assertEqual(
            msgs, [],
            "a cross-app identifier carrying x-external-register and no $ref is "
            f"correctly authored and must not be reported, got {msgs}",
        )

    def test_external_reference_with_a_dangling_ref_is_still_reported(self):
        """The true positive must SURVIVE. A `$ref` OpenRegister cannot resolve
        is still wrong — the fix is to drop it, and the message must say so
        rather than repeat the generic 'does not resolve'."""
        prop = dict(self.EXTERNAL)
        prop["$ref"] = "case"
        msgs = self.run_check(_register({"caseReference": prop}))
        self.assertEqual(len(msgs), 1, f"expected exactly one finding, got {msgs}")
        self.assertIn("x-external-register", msgs[0])
        self.assertIn("drop the $ref", msgs[0])

    def test_a_local_dangling_ref_is_unaffected(self):
        """Control: without x-external-register, a dangling $ref keeps its
        original message. Widening a checker until it catches nothing is not a
        fix."""
        msgs = self.run_check(_register({"badRel": {
            "type": "string",
            "format": "uuid",
            "$ref": "nosuchschema",
            "title": "Bad rel",
        }}))
        self.assertEqual(len(msgs), 1, f"expected exactly one finding, got {msgs}")
        self.assertIn("does not resolve", msgs[0])

    def test_external_marker_does_not_excuse_a_bad_filter_token(self):
        """Control: the exemption is scoped to the $ref rules only."""
        prop = dict(self.EXTERNAL)
        prop["x-relation-filter"] = {"setting": "@bogus"}
        msgs = self.run_check(_register({"caseReference": prop}))
        self.assertTrue(any("unknown token" in m for m in msgs),
                        f"filter validation must still apply, got {msgs}")


# --------------------------------------------------------------------------
# 6. End-to-end through main() — findings land in the log file, not stdout.
# --------------------------------------------------------------------------
class EndToEndTest(_Base):
    def test_main_writes_nested_findings_to_the_log(self):
        settings = self.dir / "lib" / "Settings"
        settings.mkdir(parents=True)
        reg = settings / "app_register.json"
        reg.write_text(json.dumps(_register({
            "requirementOverrides": _nested_relation(),
            "brokenNested": _nested_relation({
                "type": "string", "format": "uuid", "$ref": "nosuchschema",
            }),
        }), indent=2), encoding="utf-8")
        log = self.dir / "gate.log"
        g.main(["check_relation_dialect.py", str(log), str(reg)])
        text = log.read_text(encoding="utf-8")
        self.assertIn("nosuchschema", text)
        self.assertNotIn("placed off a property", text)


# --------------------------------------------------------------------------
# 5c. Polymorphic references — the gate must be CLOSABLE here too.
#
# Measured 2026-08-10 on scholiq (gate package 94c855b). Two report rows point
# at a DIFFERENT schema per row, named by a sibling discriminator:
#
#   CoursePackageImportReport.entries[].targetId  ← sibling `targetType`
#     (Course / Lesson / Material / Item / LtiToolPlacement / Assignment / Rubric)
#   LearningRecordExport.coverageReport[].sourceId ← sibling `sourceSchema`
#
# Both arms failed, the same way the cross-app case did before
# `x-external-register` existed:
#
#   WITHOUT $ref -> "relation-shaped property ... lacks canonical $ref"   (b)
#   WITH    $ref -> resolves, and is a LIE: OpenRegister would resolve every
#                   row against the one schema the author happened to pick.
#
# So the only route to green was rewording the description until
# `_RELATION_DESC_RE` stopped matching. The marker is
# `x-relation-schema-field: <siblingPropertyName>`, and it is a CLAIM THE GATE
# VERIFIES — the named sibling must exist — not a string that silences a rule.
# --------------------------------------------------------------------------
class PolymorphicReferenceTest(_Base):
    @staticmethod
    def _entries(target_extra=None):
        """An array of report rows: a discriminator + the identifier it names."""
        target = {
            "type": "string",
            "format": "uuid",
            "nullable": True,
            "description": "UUID of the created object named by targetType.",
            "x-relation-schema-field": "targetType",
            "title": "Target ID",
        }
        target.update(target_extra or {})
        return {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "targetType": {
                        "type": "string",
                        "title": "Target Type",
                        "description": "The schema the resource was materialised as.",
                    },
                    "targetId": target,
                },
            },
        }

    def test_polymorphic_identifier_without_ref_is_accepted(self):
        """The correct authoring must PASS — this is the closable arm."""
        msgs = self.run_check(_register({"entries": self._entries()}))
        self.assertEqual(
            msgs, [],
            "a polymorphic identifier naming a real sibling discriminator and "
            f"carrying no $ref is correctly authored, got {msgs}",
        )

    def test_the_true_positive_survives_without_the_marker(self):
        """CONTROL, and the whole point. Drop the marker and the SAME fixture
        must go red — otherwise the exemption is just a hole and this suite
        would be green about a rule that no longer fires."""
        entries = self._entries()
        del entries["items"]["properties"]["targetId"]["x-relation-schema-field"]
        msgs = self.run_check(_register({"entries": entries}))
        self.assertEqual(len(msgs), 1, f"expected exactly one finding, got {msgs}")
        self.assertIn("lacks canonical $ref", msgs[0])

    def test_a_discriminator_that_is_not_a_sibling_is_reported(self):
        """The marker is a verified claim. Naming a field that does not exist
        cannot resolve a schema at runtime, so it is decoration — and if this
        passed, `x-relation-schema-field: anything` would be a blanket waiver."""
        entries = self._entries()
        entries["items"]["properties"]["targetId"]["x-relation-schema-field"] = "nope"
        msgs = self.run_check(_register({"entries": entries}))
        self.assertEqual(len(msgs), 1, f"expected exactly one finding, got {msgs}")
        self.assertIn("not a property of the same object", msgs[0])

    def test_polymorphic_marker_plus_a_ref_is_reported(self):
        """Declaring both says the target varies per row AND is fixed. The
        `$ref` is the half OpenRegister acts on, so the fix is to drop it."""
        msgs = self.run_check(_register({
            "entries": self._entries({"$ref": "skill"}),
        }))
        self.assertEqual(len(msgs), 1, f"expected exactly one finding, got {msgs}")
        self.assertIn("x-relation-schema-field", msgs[0])
        self.assertIn("drop the $ref", msgs[0])

    def test_marker_does_not_excuse_a_bad_filter_token(self):
        """Control: the exemption is scoped to the $ref rules only."""
        msgs = self.run_check(_register({
            "entries": self._entries({"x-relation-filter": {"setting": "@bogus"}}),
        }))
        self.assertTrue(any("unknown token" in m for m in msgs),
                        f"filter validation must still apply, got {msgs}")

    def test_marker_on_a_top_level_property_resolves_against_its_siblings(self):
        """The discriminator is looked up among the property's OWN siblings at
        whatever depth it sits — not only inside an array's items."""
        msgs = self.run_check(_register({
            "sourceSchema": {"type": "string", "title": "Source Schema"},
            "sourceId": {
                "type": "string",
                "format": "uuid",
                "description": "UUID of the source object this entry reports on.",
                "x-relation-schema-field": "sourceSchema",
                "title": "Source ID",
            },
        }))
        self.assertEqual(msgs, [], f"expected a clean run, got {msgs}")


if __name__ == "__main__":
    unittest.main()
