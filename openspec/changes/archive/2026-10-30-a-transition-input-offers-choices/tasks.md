# Tasks

## 1. The change

- [x] 1.1 `publishedInputs()` carries a declared `label` and `options` through,
      for static, graph and provider actions.
- [x] 1.2 An option without a usable value is dropped; one without a label is
      labelled by its value; an input declaring neither keeps `{field, required}`.

## 2. Verification

- [x] 2.1 Unit test on the provider path: label and usable options published,
      malformed options dropped, a bare input unchanged.
- [x] 2.2 The existing normalisation test still proves an unknown key is not
      published.

## 3. Not in this change

- Refusing a posted value that is not among the declared options. The writer
  already validates it (the save path, or the provider); a second check here
  would be a second authority.
