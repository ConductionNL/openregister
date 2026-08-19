#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-40 form-label-association — every form control must have an
accessible name (WCAG 2.2 AA, SC 1.3.1 Info and Relationships and SC 3.3.2
Labels or Instructions).

WHY THIS WAS REWRITTEN
----------------------
The previous implementation flattened the file's newlines into spaces and
ran four independent regexes over the result. It had no notion of nesting,
of a component's slot, or of where the template ends — so it could only see
attributes ON the element, and an accessible name that comes from anywhere
else read as an absent one. Measured across 21 fleet repos: **1,211
findings, 58% of them false**, in four shapes:

  implicit label wrapping   `<label><input …><span>Safe mode</span></label>`
                            is the canonical HTML association and needs no
                            `for`/`id` pair at all. 268 findings.

  NcCheckboxRadioSwitch     `<NcCheckboxRadioSwitch v-model="x">Installed
  default slot              apps only</NcCheckboxRadioSwitch>` — nc-vue
                            renders the default slot INTO the `<label>`.
                            463 findings, and this is the dangerous one:
                            the only way to satisfy the old gate was to add
                            `aria-label`, which OVERRIDES the visible label
                            and breaks speech-input users who say what they
                            see. A gate whose remediation is an
                            accessibility REGRESSION cannot be closed
                            honestly.

  dynamic :id / :for        `<label :for="`f-${id}`">` + `<input
                            :id="`f-${id}`">` is a correct association the
                            literal-only regex could not see. 56 findings.

  commented-out markup      `<!-- <input type="text"> -->` ships nothing.
                            5 findings.

WHAT STILL FAILS, AND MUST
--------------------------
A control with no accessible name from ANY of the recognised sources is
still reported. Every relaxation here is anchored to a real naming
mechanism the browser implements — a wrapping `<label>`, a matching
`for`/`id` pair (literal or expression), a component prop the library
documents, or slot content the component renders into its own `<label>`.
An `<input type="text">` standing on its own, a self-closed
`<NcCheckboxRadioSwitch />` with no prop and no slot, and a `<label>` whose
`for` matches no control are all still findings. See
``test_check_form_labels.py``, where each relaxation ships with the
true-positive case it must not swallow.

Usage:
    check_form_labels.py <file.vue>...        # findings on stdout
"""
from __future__ import annotations

import re
import sys

# Native controls that need a name. `select` is deliberately absent: it is
# gate-12 (nc-input-labels)' subject, and widening this gate's remit while
# fixing its false-positive rate would make the two numbers incomparable.
NATIVE = {'input', 'textarea'}
# Nextcloud components whose published API takes the name as a prop.
# `NcSelect` belongs to gate-12, same reason.
NC_PROP_COMPONENTS = {'nctextfield', 'ncinputfield', 'ncrichcontenteditable',
                      'nccheckboxradioswitch'}
# ...of which these ALSO accept the name as default-slot content, because
# they render that slot inside their own <label> element.
NC_SLOT_COMPONENTS = {'nccheckboxradioswitch'}

# `type=` values that carry their own name or take none.
EXEMPT_INPUT_TYPES = {'hidden', 'submit', 'button', 'reset', 'image'}

# NOT IN THE ACCESSIBILITY TREE (.github#273)
# ------------------------------------------
# An element that is not in the accessibility tree has no accessible name, so
# `aria-label` on it is INERT. Demanding a name from one is demanding either a
# no-op edit or the deletion of the very attribute that hides it — the same
# unclosable shape `check_table_headers.py` already resolves by exempting a
# `<th aria-hidden="true">`, because "a header with no accessible name names
# nothing".
#
# #273 keyed this on `aria-hidden` / `tabindex="-1"`. MEASURED FLEET-WIDE, that
# spelling matches NOTHING: of gate-40's 471 findings, `aria-hidden="true"`
# appears on 0 and `display:none` on 8 — so an exemption written for
# `aria-hidden` would have swallowed zero of the findings it was proposed for.
# The canonical hidden file picker in this fleet is spelled with a style:
#
#   <input type="file" ref="fileInput" style="display: none" @change="onPick">
#   <NcButton @click="$refs.fileInput.click()">Import</NcButton>
#
# Both spellings are therefore recognised, but NOT on the same terms, because
# they are not equally static:
#
#   aria-hidden="true"   an explicit authored declaration that the element is
#                        out of the tree. Honoured on any input, literal or
#                        `:aria-hidden="true"` — the same evidence gate-43 uses.
#
#   display:none         a STYLE, and #273 is right that JS can toggle a style,
#                        so an element hidden this way may be exposed at
#                        runtime. Honoured ONLY for `type="file"`, where
#                        hidden-and-clicked-programmatically is the canonical
#                        pattern (the visible, labelled trigger is a separate
#                        button) and a toggled-visible file input is not a real
#                        shape. All 8 fleet occurrences are exactly this; 0 are
#                        anything else, so the restriction costs nothing today
#                        and is what stops the exemption becoming a blanket
#                        "style yourself invisible to silence the gate".
#
# A dynamic `:aria-hidden="someVar"` is NOT accepted — its value is unknown at
# scan time. See `NotInTheAccessibilityTree` in the tests, where each arm ships
# with the true positive it must not swallow.
ARIA_HIDDEN_TRUE = re.compile(r'(^|\s)(?::|v-bind:)?aria-hidden\s*=\s*[\'"]true[\'"]', re.IGNORECASE)
DISPLAY_NONE = re.compile(r'(^|\s)style\s*=\s*[\'"][^\'"]*display\s*:\s*none', re.IGNORECASE)


def _not_in_accessibility_tree(input_type: str, attrs: str) -> bool:
    """True when the element is removed from the accessibility tree, so no
    accessible name is possible and none can be demanded. See the note above
    for why the two spellings carry different scopes."""
    if ARIA_HIDDEN_TRUE.search(attrs):
        return True
    if input_type == 'file' and DISPLAY_NONE.search(attrs):
        return True
    return False

TAG = re.compile(
    r'<(/?)([A-Za-z][A-Za-z0-9._:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>',
    re.DOTALL,
)
COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
BLOCK = re.compile(r'<(script|style)\b[^>]*>.*?</\1\s*>', re.DOTALL | re.IGNORECASE)
TEMPLATE = re.compile(r'<template\b[^>]*>(.*)</template\s*>', re.DOTALL | re.IGNORECASE)

ARIA = re.compile(r'(^|\s)(:|v-bind:)?(aria-label|aria-labelledby)\s*=')
NC_LABEL_PROP = re.compile(
    r'(^|\s)(:|v-bind:)?(label|input-label|inputLabel|aria-label-combobox|ariaLabelCombobox)\s*=')


def _attr(attrs: str, name: str) -> str | None:
    """Value of *name* (or its bound `:name` form) as written, or None."""
    m = re.search(
        r'(?:^|\s)(?::|v-bind:)?' + re.escape(name) + r'\s*=\s*("([^"]*)"|\'([^\']*)\')',
        attrs)
    if not m:
        return None
    return m.group(2) if m.group(2) is not None else m.group(3)


def _norm_expr(value: str) -> str:
    """Normalise a `for`/`id` value so a literal and a bound expression that
    denote the same thing compare equal. Whitespace-insensitive; quotes
    unified. `` `f-${id}` `` from a `:for` and from an `:id` match."""
    return re.sub(r'\s+', '', value).replace('"', "'")


def _strip_noise(src: str) -> str:
    """Template region only, comments and script/style blocks removed.

    Order matters: comments first, because a commented-out `</script>`
    would otherwise end the block early.
    """
    body = COMMENT.sub(' ', src)
    m = TEMPLATE.search(body)
    if m:
        body = m.group(1)
    return BLOCK.sub(' ', body)


class _El:
    __slots__ = ('name', 'attrs', 'start', 'text_start')

    def __init__(self, name: str, attrs: str, start: int, text_start: int):
        self.name = name
        self.attrs = attrs
        self.start = start
        self.text_start = text_start


def scan_source(fname: str, src: str) -> list[str]:
    """Return finding lines for one component source."""
    body = _strip_noise(src)

    # Pass 1 — every `for=` / `:for=` a <label> declares, normalised.
    label_for: set[str] = set()
    for m in TAG.finditer(body):
        closing, name, attrs, _self = m.group(1), m.group(2).lower(), m.group(3), m.group(4)
        if closing or name != 'label':
            continue
        v = _attr(attrs, 'for')
        if v:
            label_for.add(_norm_expr(v))

    findings: list[str] = []
    stack: list[_El] = []
    label_depth = 0

    def _named_by_attrs(name: str, attrs: str) -> bool:
        if ARIA.search(attrs):
            return True
        if name in NC_PROP_COMPONENTS and NC_LABEL_PROP.search(attrs):
            return True
        if name in NATIVE:
            v = _attr(attrs, 'id')
            if v and _norm_expr(v) in label_for:
                return True
        if name in NC_PROP_COMPONENTS:
            # An Nc* wrapper is named by an EXTERNAL `<label for>` when the id
            # that label points at is the id the wrapper puts on the `<input>`
            # it renders. The association is real HTML — the same one accepted
            # for a native element two lines up — so it is an accessible name
            # by the same evidence.
            #
            # TWO SPELLINGS REACH THE <input>, AND WHICH ONE DOES IS
            # PER-COMPONENT (.github#310)
            # ------------------------------------------------------
            # `input-id` is a real prop on the NcSelect / NcActionInput /
            # NcSelectUsers / NcFormBoxSwitch family, and it is kept here so
            # this gate stays correct if one of those is ever added to
            # NC_PROP_COMPONENTS.
            #
            # It is NOT a prop on NcTextField / NcInputField — the family this
            # gate actually judges. Measured against the published package,
            # which is the authority on a component's props:
            #
            #   nc-vue 9.9.0  `grep -ril inputid dist/components/NcTextField/
            #                  dist/components/NcInputField/` -> no matches;
            #                 NcTextField.vue.d.ts declares `id`, `label`,
            #                 `placeholder`, `inputClass`, `helperText`,
            #                 `modelValue` — no `inputId`.
            #   nc-vue 8.39.0 zero occurrences of `inputId` in NcTextField.
            #
            # So `:input-id="x"` on an NcTextField is not a prop at all: it
            # falls through `$attrs` onto the `<input>` as a literal
            # `input-id="x"` attribute, which no browser or AT consumes.
            #
            # `id` is what reaches the `<input>`, on BOTH major lines:
            #
            #   9.9.0   NcInputField-5Sg6EUP6.mjs renders
            #             createElementVNode("input", mergeProps(_ctx.$attrs, {
            #               id: __props.id, ...
            #           i.e. the declared `id` prop lands on the <input>.
            #   8.39.0  NcInputField is `inheritAttrs: false` and computes
            #             computedId() { return this.$attrs.id && this.$attrs.id !== ''
            #                              ? this.$attrs.id : this.inputName }
            #           i.e. a consumer-supplied `id` is DELIBERATELY read out
            #           of $attrs and used as the input's id, with a generated
            #           one only as the fallback.
            #
            # Before this, `<label for="x">` + `<NcTextField id="x">` — a
            # correct, working association — was reported unlabelled (11 in
            # openregister alone), and the only two edits that closed the
            # finding were a no-op `input-id` attribute or an `aria-label`,
            # which OVERRIDES the visible label and breaks WCAG 2.5.3 Label in
            # Name. A gate whose only remediations are a no-op and a
            # regression cannot be closed honestly.
            #
            # This is not a relaxation of the rule, it is the rule the native
            # branch already applies: the id must still match a `<label for>`
            # that exists in the file. An Nc* control with no `label` prop, no
            # `aria-*`, no wrapping `<label>` and no matching `<label for>` is
            # still reported.
            for _attr_name in ('input-id', 'id'):
                v = _attr(attrs, _attr_name)
                if v and _norm_expr(v) in label_for:
                    return True
        return False

    def _report(name: str, attrs: str, rule: str) -> None:
        rendered = re.sub(r'\s+', ' ', f'<{name}{attrs}>').strip()
        if len(rendered) > 200:
            rendered = rendered[:197] + '...'
        findings.append(f'{fname}: {rendered} rule={rule}')

    for m in TAG.finditer(body):
        closing, raw_name, attrs, self_close = m.group(1), m.group(2), m.group(3), m.group(4)
        name = raw_name.lower()
        void = name in {'input', 'img', 'br', 'hr', 'meta', 'link', 'source', 'area'}

        if closing:
            # Pop back to the matching open tag, tolerating unbalanced markup.
            for i in range(len(stack) - 1, -1, -1):
                if stack[i].name == name:
                    el = stack[i]
                    inner = body[el.text_start:m.start()]
                    if el.name in NC_SLOT_COMPONENTS:
                        _decide_slot_component(el, inner, _report)
                    del stack[i:]
                    break
            label_depth = sum(1 for e in stack if e.name == 'label')
            continue

        is_void = void or bool(self_close)

        if name == 'input':
            t = (_attr(attrs, 'type') or 'text').strip().lower()
            if t not in EXEMPT_INPUT_TYPES and not _not_in_accessibility_tree(t, attrs):
                if not (_named_by_attrs(name, attrs) or label_depth > 0):
                    _report(raw_name, attrs, 'input-without-label')
        elif name == 'textarea':
            if not _not_in_accessibility_tree('textarea', attrs) and not (
                    _named_by_attrs(name, attrs) or label_depth > 0):
                _report(raw_name, attrs, f'{name}-without-label')
        elif name in NC_PROP_COMPONENTS:
            named = _named_by_attrs(name, attrs) or label_depth > 0
            if named:
                pass
            elif name in NC_SLOT_COMPONENTS and not is_void:
                # Defer: the default slot may supply the label. Decided when
                # the closing tag is reached.
                pass
            else:
                _report(raw_name, attrs, f'{name}-without-label-prop')

        if not is_void:
            stack.append(_El(name, attrs, m.start(), m.end()))
            if name == 'label':
                label_depth += 1

    # Unclosed slot components: no closing tag was ever seen, so no slot
    # content was proved. Report rather than silently accept.
    for el in stack:
        if el.name in NC_SLOT_COMPONENTS and not (
                ARIA.search(el.attrs) or NC_LABEL_PROP.search(el.attrs)):
            _report(el.name, el.attrs, f'{el.name}-without-label-prop')

    return findings


def _decide_slot_component(el: _El, inner: str, report) -> None:
    """A slot-labelling component is named iff its default slot renders
    something. Whitespace, and nothing else, is not a name."""
    if ARIA.search(el.attrs) or NC_LABEL_PROP.search(el.attrs):
        return
    # Strip nested tags; what remains is the text the user would read. A
    # `{{ t('app', 'Installed apps only') }}` interpolation counts — it is
    # the translated visible label.
    text = TAG.sub(' ', inner)
    if text.strip():
        return
    report(el.name, el.attrs, f'{el.name}-without-label-prop')


def scan_files(files: list[str]) -> list[str]:
    out: list[str] = []
    for fname in files:
        try:
            with open(fname, encoding='utf-8', errors='replace') as f:
                src = f.read()
        except OSError:
            continue
        out.extend(scan_source(fname, src))
    return out


def main(argv: list[str]) -> int:
    for line in scan_files(argv[1:]):
        print(line)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
