---
kind: code
depends_on: [property-vocabulary-published]
---

# Proposal: extends-form-form-roles

## Summary

`x-openregister-extends-form` refuses every map role that is not a property
vocabulary key. `@conduction/nextcloud-vue` 2.79.0 added a role to its
`DEFAULT_MAP` that is not a property key: `hideOnCreate`, which tells the
create form to leave a field out because the app fills it in itself. dossiq
declared it (dossiq #3716), and every register import since refuses the
whole `case` schema with `Invalid x-openregister-extends-form declaration:
'hideOnCreate'`. An upgraded instance keeps its old case schema and logs
`PARTIAL IMPORT`.

## Why

The validator exists so a form cannot claim to forward a key nobody defines.
`hideOnCreate` forwards nothing: it steers the form. Refusing it by name is
the same breakage the `definition` alias was added to prevent, one release
later.

## What changes

- A second list beside `SOURCE_ALIASES`: form roles, the roles the shipped
  consumer reads to steer the form rather than to fill a property key. Today
  that is `hideOnCreate`.
- A declaration mapping a form role is accepted. A form role forwards no key,
  is not counted as forwarded and never lands in a property built from a form
  submission.
- The extending-forms read publishes the form roles and the source aliases, so
  an app can check its declaration against what this layer accepts.
- The property vocabulary publishes `allowedValues` for the modifiers whose
  value the save path checks against a closed list (`inputControl`,
  `matchType`). The same import refused dossiq's `aanvullingsverzoek` for
  `inputControl: textarea`; `textarea` is a form widget, not a list filter
  control, so dossiq drops it. Publishing the list lets dossiq pin its shipped
  schemas against it at build time.

## Out of scope

Any other role. A role is added here when the shipped consumer reads it, not
before.
