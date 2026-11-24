# Custom Validation Messages

**Status**: Implemented (backend). The property editor field in Open Register and the nextcloud-vue form display are separate tasks.

## Overview

When an object fails validation, OpenRegister builds a technical message such as
"Property 'postcode' should match pattern '^[1-9][0-9]{3} ?[A-Z]{2}$' but 'ABCD' does not". That is
accurate, but it is not a sentence a caseworker or a resident can act on. A schema property may
declare its own message per validation rule, in one or more languages, under `x-error-messages`.

The declaration is data on the schema. A schema without `x-error-messages` returns exactly the
messages it returned before.

## Declaring messages

`x-error-messages` maps a validation keyword to a message. The message is either a plain string or a
map from BCP 47 language tags to strings.

```json
"postcode": {
  "type": "string",
  "pattern": "^[1-9][0-9]{3} ?[A-Z]{2}$",
  "x-error-messages": {
    "pattern": { "nl": "Vul een postcode in zoals 1234 AB.", "en": "Enter a postcode such as 1234 AB." },
    "required": "Postcode is verplicht."
  }
}
```

The keywords a message can answer are `required`, `type`, `enum`, `const`, `pattern`, `format`,
`minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `minLength`, `maxLength`,
`minItems`, `maxItems` and `uniqueItems`.

A `required` message belongs on the property that is required, not on the object around it: when
`postcode` is missing, the message shown is the one `postcode` declares.

### What the schema save refuses

The schema save checks the declaration and refuses it, naming the property and the key, when:

- a key is not one of the keywords above (a message nothing can trigger would never be shown);
- a message is not a non-empty string or a non-empty language map;
- a language tag is not a BCP 47 tag, or a translation is empty;
- `x-error-messages` is a list rather than an object keyed by keyword.

## Which language is shown

The message is chosen in this order:

1. the language the request resolved to (`?_lang=`, then `Accept-Language`, as for all
   [language negotiation](../i18n.md)), also trying its primary subtag (`en` for `en-GB`);
2. `nl`;
3. the first language the declaration lists.

When the failing property declares nothing for the failed rule, the generated message is returned.

## Placeholders

A message may use three placeholders:

| Placeholder | Replaced by |
|---|---|
| `{value}` | the submitted value, cut to 100 characters |
| `{property}` | the property name |
| `{limit}` | the rule's limit: the minimum or maximum, the pattern, the format or the expected value |

```json
"omschrijving": {
  "type": "string",
  "maxLength": 200,
  "x-error-messages": { "maxLength": "De omschrijving is te lang: hooguit {limit} tekens." }
}
```

Substitution is plain text in one pass. Nothing else in the message is interpreted, a submitted value
is never treated as markup, and a placeholder inside the submitted value is not replaced again.

## Related Features

- [Registers & Schemas](registers-and-schemas.md): where properties are defined
- [Property validation](../technical/property-validation.md): the validation rules themselves
