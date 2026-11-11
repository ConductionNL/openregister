# Tasks: property-source-vocabulary

## 1. The key

- [x] 1.1 `x-openregister-property-source` is published by `vocabularyKeys()`.
- [x] 1.2 `PropertySourceDeclaration` refuses what cannot be honoured.
- [x] 1.3 The mode defaults to `live`; `default` is asked for by name.
- [x] 1.4 Carrying both source keys is refused rather than ranked.

## 2. What it does not do

- [ ] 2.1 Serve the values. Openregister declares WHERE a field's values come
      from; integriq's resolver fetches them. Nothing in openregister calls a
      provider, and nothing here should: a second fetcher would be a second
      answer to "what is this field's value".
- [ ] 2.2 The form surface that reads the key and offers the suggestions. That
      is the consuming app's half, and it is what `registry-backed-field-source`
      hands dossiq.
