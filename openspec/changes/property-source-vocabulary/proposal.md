---
kind: code
---

## Why

Integriq's `registry-backed-field-source` built a property-source resolver:
`suggest`, `resolve`, `describe`, provider discovery by DI tag, provenance on a
resolved value. All of it tested. It has been waiting on openregister to carry
the key that declares, on a schema property, which provider a field's values come
from.

**A correction to what the waiting was.** The integriq tasks file, and my own
first measurement, said the key would fail a schema save. It would not:
`assertKeysAreInTheVocabulary()` skips every `x-` prefixed key, so
`x-openregister-property-source` has always saved. What it could not do is be
DISCOVERED, because `vocabularyKeys()` never published it, and nothing checked
its shape, so `{"provider": 7}` or `{"mode": "livee"}` saved just as cleanly as
the real thing.

That is the same failure `property-code-list-from-concept-scheme` hit: a binding
accepted for months that could not be forwarded because nothing published it.

## What Changes

- `x-openregister-property-source` joins the published vocabulary, so a form or
  an extending app can discover it instead of knowing it by folklore.
- `PropertySourceDeclaration` refuses a declaration that cannot be honoured:
  no provider, a provider id that is not an identifier, a mode nobody knows, a
  config that is not an object.
- **The mode defaults to `live` and `default` is asked for by name.** A
  registry-backed field exists so the value is looked up when it is used;
  `default` is the weaker promise, that the provider only supplies a starting
  value a person may change. Those are different promises to whoever reads the
  record later, so the weaker one is never a guess.
- **A property carrying both `x-openregister-property-source` and
  `x-openregister-object-source` is refused rather than ranked.** They differ by
  one word and by their entire blast radius: the first binds one property, the
  second serves a whole schema's objects from a provider. Picking one would be
  right about half the time and silent the rest, and the wrong half serves an
  entire register from somewhere unexpected.

**The shape is integriq's, adopted rather than invented.** `provider`, `config`,
`mode`, taken from `registry-backed-field-source`'s proposal, which is where the
meaning was defined.

## Capabilities

### Modified Capabilities

- `schema-vocabulaire`: the published property vocabulary gains the
  property-source binding and the refusals that keep it honest.
