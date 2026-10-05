# Tasks: a-system-write-declares-itself

> Recorded after the fact. The code shipped in openregister#3958; these boxes
> are ticked against what is in `lib/`, not against work still to do.

## 1. The declaration

- [x] 1.1 `SystemOperationContext::assertSystem(string $what, callable $operation)`
      runs the operation inside the elevated scope and returns its value.
- [x] 1.2 `SystemContextUnavailableException` is thrown when the scope was not
      in effect while the operation ran, and its message names the write.
- [x] 1.3 The operation's own exception travels untouched, so a failing write
      is never reported as an elevation failure.

## 2. The verification

- [x] 2.1 The scope is asserted live before the operation and again after it.
- [x] 2.2 Declared writes nest, and an inner scope closing does not end the
      outer one.
- [x] 2.3 The scope closes when the write is done and when the write throws.

## 3. Tests

- [x] 3.1 `tests/Unit/Service/SystemOperationContextAssertTest.php` (7): the
      elevated run, both closes, the operation's own failure, nesting, a write
      that did not elevate, and the refusal naming what was being written.
