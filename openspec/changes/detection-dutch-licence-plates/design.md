# Design: detection-dutch-licence-plates

Read at openregister development c53dd0685c.

## D-1: one new type, named internationally

`EntityRecognitionHandler` gains `ENTITY_TYPE_LICENSE_PLATE = 'LICENSE_PLATE'`
beside the constants at `lib/Service/TextExtraction/EntityRecognitionHandler.php:66-75`.
The name is international (hydra ADR-001: Dutch names sit behind a mapping). The
Dutch word is the translation: `l10n/nl.json` gets `"LICENSE_PLATE": "KENTEKEN"`,
the same way `"SSN": "BSN"` already reads (`l10n/nl.json:1995`).

The type is added to every list that enumerates types, so it is not a second-class
type that works in one place and falls through in another:

- `getCategoryForType()` (`EntityRecognitionHandler.php:1019-1031`): personal data.
- `RiskLevelService::ENTITY_RISK_MAP` (`lib/Service/RiskLevelService.php:77-88`):
  medium. A plate identifies a person through the RDW register, like a phone
  number does, so it sits with `PHONE`.
- `DocumentProcessingHandler::LOCALIZABLE_ENTITY_TYPES`
  (`lib/Service/File/DocumentProcessingHandler.php:77-88`): so the placeholder on
  a Dutch instance reads `[KENTEKEN-1]`, not `[LICENSE_PLATE-1]`.

## D-2: jurisdiction pattern sets, the rule in lib/Formats

A new interface `OCA\OpenRegister\Service\TextExtraction\PatternSet\JurisdictionPatternSet`
with `getCode(): string` (ISO 3166-1 alpha-2, lower case) and
`detect(string $text): array` returning the same entity shape
`detectWithRegex()` builds (`EntityRecognitionHandler.php:478-485`). The first
implementation is `NlPatternSet` (`nl`). The generic patterns in
`getRegexPatterns()` (`:505-533`) stay as they are and keep running everywhere.

`NlPatternSet` finds candidates with a regex and then asks a validator. The
validators live in `lib/Formats/` (openregister ADR-008 Rule 1):

- BSN: candidates are nine-digit tokens (with optional dot or space groups
  3-2-4 or 4-2-3 removed before the check), confirmed by the existing
  `lib/Formats/BsnFormat.php` `validate()`. No second elfproef is written.
- Licence plate: a new `lib/Formats/LicensePlateNlFormat.php` implementing
  `Opis\JsonSchema\Format`, so a schema can also declare `format: license-plate-nl`
  (registered beside `bsn` at `lib/Service/Object/ValidateObject.php:2016`). It
  holds the fourteen sidecodes as data:

| sidecode | pattern | sidecode | pattern |
|---|---|---|---|
| 1 | XX-99-99 | 8 | 9-XXX-99 |
| 2 | 99-99-XX | 9 | XX-999-X |
| 3 | 99-XX-99 | 10 | X-999-XX |
| 4 | XX-99-XX | 11 | XXX-99-X |
| 5 | XX-XX-99 | 12 | X-99-XXX |
| 6 | 99-XX-XX | 13 | 9-XX-999 |
| 7 | 99-XXX-9 | 14 | 999-XX-9 |

Source: https://nl.wikipedia.org/wiki/Nederlands_kenteken, read 2026-09-27. The
builder checks the list against the RDW before merging and records the check in
the class docblock with its date. Letters: C and Q never appear (same source:
"The letters C and Q do not appear on Dutch plates"); from sidecode 7 on, vowels
are excluded too. The format returns the sidecode it matched, so a test can
assert the sidecode and not only a yes or no.

## D-3: the false-positive guard

A plate is six characters in three groups, which is also the shape of a lot of
other things. The guard is part of the requirement, not a tuning knob:

1. A hyphenated candidate counts only when it matches one sidecode exactly and
   stands alone as a token: not preceded or followed by a letter, digit or
   hyphen. So `ZK-12-AB-34` or `2024-12-AB` yields nothing.
2. An unhyphenated candidate (`12GBK3`) counts only when one of the context
   words sits within 30 characters before it: `kenteken`, `kentekenplaat`,
   `voertuig`, `license plate`, `licence plate`, `registration`. The list is a
   constant on `NlPatternSet`.
3. Letters outside the allowed set for that sidecode reject the candidate.
4. A span already claimed by a BSN or IBAN match is not a plate.

Confidence: 0.8 for a hyphenated match, 0.6 for an unhyphenated match with
context. Both clear the default 0.5 threshold
(`EntityRecognitionHandler::processSourceChunks()` options).

## D-4: jurisdiction sets run under every method

Today every method except a working Presidio or OpenAnonymiser uses the regex
set, and those two may not know plates at all (Presidio sends `SSN` as `US_SSN`,
`:869`). So the enabled jurisdiction sets run after whichever method
`detectEntities()` (`:393-429`) picked, and their matches merge in. On overlap
the backend's entity wins and the set's is dropped, so a backend that already
found a span keeps its type and confidence. This keeps "which backend" and
"which country" independent choices.

## D-5: which sets are on

A new file setting `entityPatternSets` (array of codes) in
`lib/Service/Settings/FileSettingsHandler.php`, read at `:127-135` and written at
`:215-221`. When the key is absent, the default is derived once from Nextcloud's
system config `default_phone_region`: `NL` gives `["nl"]`, anything else gives
`[]`. An admin changes it on the file configuration section
(`src/views/settings/sections/FileConfiguration.vue`) through the existing
`PATCH /api/settings/files`. An unknown code is refused with 400 naming the
code. So an instance outside the Netherlands never matches a Dutch plate by
accident.

## Declarative-vs-imperative decision

Not applicable. This touches detection, not lifecycle, aggregations,
calculations, notifications, relations or widgets.

## Risks

- Security (hydra ADR-005): a plate and a BSN are personal data. Log lines carry
  counts and types only, never the value, the same rule
  `DocumentProcessingHandler` already follows.
- False positives: the guard in D-3 is tested with a list of plate-shaped
  non-plates (case numbers, dates, postcodes such as `1234 AB`, product codes).
  A false positive costs a needless redaction, which is the safe side; a false
  negative leaks, so the letter rules are not made stricter than the source.
- Performance (openregister ADR-009): each set compiles its patterns once per
  handler instance. Detection runs per chunk in a background job, never on an
  object write.
- Multitenancy (openregister ADR-002): detected entities keep the organisation
  scoping the entity rows already have. Nothing here changes who may read them.
