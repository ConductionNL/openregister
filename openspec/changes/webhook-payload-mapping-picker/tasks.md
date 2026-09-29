# Tasks: webhook-payload-mapping-picker

## Implementation tasks

### Task 1: Mapping select and preview
- **spec_ref**: `openspec/changes/webhook-payload-mapping-picker/specs/webhook-payload-mapping/spec.md#requirement-req-whmap-001-a-webhook-payload-mapping-is-chosen-and-previewed-on-screen`
- **files**: `src/modals/webhook/EditWebhook.vue`, `lib/Controller/WebhooksController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - mapping id saved
  - preview equals delivery
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
