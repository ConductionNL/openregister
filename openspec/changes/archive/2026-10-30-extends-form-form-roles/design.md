# Design: extends-form-form-roles

`ExtendingFormDeclaration::FORM_ROLES` maps each form role to a sentence that
says what it steers. `validate()` accepts a role that is a vocabulary key, a
source alias or a form role, and refuses anything else as before.
`forwards()` already skips a role the vocabulary does not hold, so a form role
is not counted. `toProperty()` skips form roles explicitly, so a submitted
`hideOnCreate` value never becomes a property key the property validator would
then refuse.

`GET /api/schemas/extending-forms` adds `formRoles` and `sourceAliases`. They
are static and per platform, not per schema, so they sit beside
`vocabularyKeys`.

The list is taken from the consumer: `DEFAULT_MAP` in
`src/utils/dynamicProperties.js` of `@conduction/nextcloud-vue` 2.79.0 reads
`title`, `description`, `type`, `enum`, `required`, `default` and
`hideOnCreate`, and `mapped()` is also called for `definition`, `format` and
`maxLength`. All of those but `definition` and `hideOnCreate` are vocabulary
keys; `definition` is the existing alias, `hideOnCreate` is the new form role.
A unit test pins that every role the consumer reads is accepted.

`PropertyValidatorHandler::CLOSED_MODIFIER_VALUES` is the one list: the save
path's `validateSearchProfile()` iterates it, and `PropertyVocabulary::modifiers()`
copies it onto the matching rows as `allowedValues`. A unit test asks the
validator that every published value saves and that `textarea` is refused.
