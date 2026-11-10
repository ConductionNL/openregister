# Design: modelling-required-when-enforced

Read at openregister development 555af7212, and nextcloud-vue development
e487bc8 (`openspec/changes/form-conditions-from-schema/design.md`).

## Context

- `DependentValueListener` (`lib/Listener/DependentValueListener.php`) is the
  model: registered on `ObjectCreatingEvent` and `ObjectUpdatingEvent`
  (`lib/AppInfo/Application.php:3372-3373`), it refuses a save with
  `$event->setErrors()` and `stopPropagation()` (`:216-225`). Its docblock
  explains why a listener: the API create, update, patch, import, flow node
  write and bulk job write all dispatch these events, so one subscription
  covers every path. It fails soft when it cannot read the schema and closed on
  the rule.
- Property modifiers live in `PropertyValidatorHandler::MODIFIERS` (`:471`).
- Nothing in `lib/` reads `x-openregister-required-when` today.

## D-1: the grammar is the form's

`RequiredWhenDeclaration::fromProperty()` reads one condition or a list of
them. Each is `{ field, op, value }` with `op` in `eq`, `neq`, `gt`, `gte`,
`lt`, `lte`, `empty`, `notEmpty`, the grammar of nextcloud-vue's
`evaluateVisibleWhenLocal`. `empty` and `notEmpty` take no `value`. The
evaluation mirrors the library's: `eq` compares loosely between a number and
its string form, as a form field sends strings.

## D-2: validation at schema save

`PropertyValidatorHandler::validateProperty()` asks the declaration to assert
itself: an unknown `op`, a missing `field`, a `field` the schema does not
declare, or a condition on the property itself is refused with 422 naming the
property.

## D-3: enforcement on the write events

`RequiredWhenListener` loads the object's schema, builds the declarations, and
for each property whose conditions all hold on the object's values after the
save checks that the property is not empty (null, empty string or empty
array). Refusals are collected and returned together, each as
`{ property, message: "Required when <field> <op> <value>" }`. On update, the
check uses the merged object, so a patch that does not touch either field
still passes or fails consistently.

## D-4: fail soft on the listener, closed on the rule

A schema that cannot be read logs a warning and lets the save through, like
`DependentValueListener`. A readable rule that is broken refuses.
