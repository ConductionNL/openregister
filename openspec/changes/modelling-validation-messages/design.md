# Design: modelling-validation-messages

Read at openregister development 0ca409ee04.

## D-1: the message map sits on the property

A property in a schema's `properties` JSON gains `x-error-messages`:

```json
"postcode": {
  "type": "string",
  "pattern": "^[1-9][0-9]{3} ?[A-Z]{2}$",
  "x-error-messages": {
    "pattern": {"nl": "Vul een postcode in zoals 1234 AB.", "en": "Enter a postcode such as 1234 AB."},
    "required": "Postcode is verplicht."
  }
}
```

The `x-` prefix keeps it out of JSON Schema's own vocabulary, so Opis ignores it
during validation and the schema stays a valid JSON Schema document.

## D-2: the lookup happens where the message is built today

`formatValidationError()` (`lib/Service/Object/ValidateObject.php`, the switch that
starts at `$keyword = $error->keyword()`) already knows the keyword, the data path
and the value. Before the switch, it reads the property definition at that data
path from the schema, looks up `x-error-messages[$keyword]`, and returns the
resolved message when there is one. The switch stays as the fallback. For
`required`, the property is the missing one named in `$args['missing']`, not the
data path, which points at the parent.

## D-3: the language is the one the request already resolved

`LanguageService` resolves `?_lang=`, then `Accept-Language`, then the register
default, then `nl` (`openspec/specs/i18n-api-language-negotiation/spec.md`,
requirement "Resolution precedence MUST be query then header then register-default
then 'nl'"). The validator asks it for the current language. Fallback order: the
resolved language, `nl`, the first declared language, the generated message.

## D-4: placeholders are substituted, never evaluated

`{value}`, `{property}` and `{limit}` are replaced by plain string substitution.
The value is cut to 100 characters and never rendered as markup. Nothing else in
the message is interpreted.

## D-5: validated at schema save

The schema property validator refuses an `x-error-messages` key that is not a
supported keyword, a message that is neither a string nor a language map, or a
language tag that is not BCP 47. The refusal names the property and the key.

## Declarative-vs-imperative decision

Declarative: the wording is data on the schema. The only code is the lookup in the
existing message builder.

## Risks

- A message that leaks data. The only value substituted is the submitted value,
  which the caller sent, so nothing new is revealed.
- A client that parsed the English text. The error entry keeps `keyword` and
  `property`; the release note says to match on those.
