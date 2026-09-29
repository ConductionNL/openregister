# Tasks: api-explorer-in-the-app

## Implementation tasks

### Task 1: Bundle the explorer and drop unpkg.com
- **spec_ref**: `openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app`
- **files**: `lib/Controller/GraphQLController.php`, `webpack.config.js`
- **acceptance_criteria**:
  - no unpkg.com in the CSP
  - explorer loads from the app
- [ ] Implement
- [ ] Test (red first)

### Task 2: API page with try-it and samples
- **spec_ref**: `openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app`
- **files**: `src/views/register/`, `src/components/cards/RegisterSchemaCard.vue`
- **acceptance_criteria**:
  - linked from the register screen
  - runs as the signed-in user
  - curl and fetch sample per operation
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
