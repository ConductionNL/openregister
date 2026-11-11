# Design: records-validate-without-saving

Read at openregister development 555af7212.

## Context

- `ValidateObject::validateObject(array $object, Schema|int|string|null $schema, ...)`
  (`lib/Service/Object/ValidateObject.php:1694`) returns a `ValidationResult`
  from the JSON schema check, including unique-field validation.
- Other acceptance rules run as listeners on `ObjectCreatingEvent` and
  `ObjectUpdatingEvent`: `CodedValueValidationListener` and
  `DependentValueListener` (`lib/AppInfo/Application.php:3366-3373`), each
  refusing with `setErrors()` and `stopPropagation()`. A validate call that
  runs only `validateObject()` would say "valid" to a record the save refuses.
- `ObjectServiceInterface` (`lib/Contract/ObjectServiceInterface.php`, 703
  lines) has save, find, search, delete, lock and patch methods, and no
  validate.
- `objects#validate` (`appinfo/routes.php:329`, `ObjectsController::validate()`
  at `:6185`) takes `register`, `schema`, `limit` and `offset` and
  re-validates stored objects.

## D-1: the save rules become callable checks

A small interface `ObjectSaveCheck` with
`check(array $object, Schema $schema, ?ObjectEntity $existing): list<array{property, message, rule}>`.
`CodedValueValidationListener` and `DependentValueListener` implement it and
call their own `check()` from `handle()`, so the listener and a dry run share
one code path. Later guards (for example the required-when listener) join the
same interface. A registry collects them in registration order.

## D-2: the verdict

`ObjectService::validateObject()` merges the sample onto the existing object
when an `id` is given, runs `ValidateObject::validateObject()`, then every
`ObjectSaveCheck`, and returns `ValidationVerdict { valid, errors }` with one
entry per failing property and the rule that failed (`schema`, `unique`,
`coded-value`, `dependent-value`, and so on). It writes nothing and dispatches
no event.

## D-3: the route

`POST /api/objects/{register}/{schema}/validate` with the sample as the body
and an optional `id` query parameter. It needs `create` rights on the schema
(or `update` on the object with `id`), because a verdict can reveal whether a
unique value exists. It answers 200 with the verdict. The route sits before the
wildcard `{id}` routes so it is not read as an object id.

## Risks

- A listener that does more than check (for example fills a field) must not be
  put behind `ObjectSaveCheck`. The interface's docblock says checks are pure,
  and the test asserts no write happens during a validate call.
