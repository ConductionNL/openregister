# A configuration import 500s on a descriptor with objects

## What was wrong

`POST /api/configurations/import` answered **HTTP 500 with Nextcloud's HTML
error page** for any register descriptor that carries seed objects.

`ConfigurationsController::import()` linked the result to the configuration with

```php
$objectIds = array_map(static fn ($obj) => $obj->getId(), $result['objects']);
```

`$result['objects']` is not uniformly entities. `ImportHandler` appends an
`ObjectEntity` in two places and a **bare id** in two others
(`$result['objects'][] = $existingObject->getId()`). On the id path
`$obj->getId()` is a call on an int, which is a `TypeError`.

A `TypeError` is an `Error`, not an `Exception`, so the method's
`catch (Exception $e)` did not see it either. The endpoint documents a JSON 400
for a failed import; what a caller actually got was a 500 and an HTML page, with
nothing to distinguish a malformed descriptor from a crash.

## How it was found

Not by reading the code. The fleet's schema-slug collision inventory had been
measured three times from descriptors and been wrong three times, so it was
re-measured by importing every app's register through this endpoint and reading
the schema rows back.

Seventeen of eighteen apps imported. stackiq's returned 500, and its register is
the one that ships seed objects.

## The change

`idsOf()` accepts an entity or a bare id and drops anything that is neither, so
the configuration's id list never holds a hole. The catch becomes `Throwable`,
so a bug in this method answers in JSON like every other failure rather than
falling through to the error page.

Verified against the live instance: the same descriptor that returned 500
returns `200 Import successful` and links its register.

## Not fixed here

`ImportHandler`'s return shape is still inconsistent — two of its four append
sites push entities and two push ids. Normalising it there is the better fix and
a wider one: other callers read the same array and would have to move together.
The controller no longer depends on which of the two it gets, which is what
unblocks the import.
