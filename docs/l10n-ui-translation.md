# Translating a UI locale

How to take one `l10n/<lang>.js` from partial to complete. Scope is **frontend UI
strings only** — the `l10n/*.js` set read by `t()` / `n()`. Backend `l10n/*.json`
and the translatable-object-property machinery in `docs/i18n.md` are separate
concerns.

The hard rules (never `value === key`; plural arity per locale; don't overwrite
real translations) are in `CLAUDE.md`. This document covers the judgement calls
that tooling cannot make for you.

## Why `value === key` is the cardinal sin

Worth restating, because every shortcut in this work leads back to it. A missing
key falls back to the English source: the UI is correct and the gap stays
*visible*. A key written as `"Slug": "Slug"` renders identically but is
indistinguishable from finished work — so it is never revisited. Bulk-filling a
locale from the English source therefore produces a bundle that is 100% "complete"
and permanently untranslated. That approach has been tried here and rejected.

The corollary: **absent is a legitimate, deliberate state.** Cognates and
technical tokens belong absent, with the reasoning recorded in the commit.

## Per-locale workflow

1. **Measure the register** against Nextcloud core (below). Do not assume.
2. **Validate any formality detector** with must-fire and must-not-fire controls,
   then sweep core — a detector that flags core is wrong, since core defines the
   convention.
3. **Review harvested values at the call site** (below). Never bulk-apply.
4. **Split placeholders** into real translations vs deliberate identity keys.
5. **Translate in batches** of roughly 150–170 keys.
6. **Verify**: `npm run test:l10n:parity`, `node --check l10n/<lang>.js`, a runtime
   `OC.L10N.register` load, and a diff against `HEAD` classifying every key
   added / removed / changed. The number that matters is *real translations
   altered: 0* — anything else means you overwrote someone's work.
7. **Commit that one language.**

## Register is measured, never inherited

Formality is a per-language fact, and neighbouring languages disagree. Measure
by counting formal vs informal markers across core (`server/core`, `lib`,
`apps/files`, `apps/settings`, `apps/dav`, …) for that locale.

Measured results: informal for `nl`, `de`, `sv`, `da`, `nb`, `pl`, `fi`, `hu`;
formal for `fr`, `cs`, `ru`, `uk`, `tr`, `el`, `sr`, `bg`. Russian was the least
ambiguous of any: 328 formal pronouns and 164 formal imperatives against **zero**
of either informal marker in 3905 strings.

Two traps make pronoun-counting insufficient on its own:

**Formality often lives in verb morphology, not pronouns.** Spanish, Czech and
Russian all address the reader formally through the imperative ending with no
pronoun present at all. Czech formal `Vyberte` vs informal `Vyber`; Russian
formal `Выберите` vs informal `Выбери`. A pronoun-only check sees nothing.
Beware the opposite error too: Czech *infinitives* (`Zobrazit`, `Smazat`) are
register-neutral button labels and must not be "corrected" to imperatives.

**Formal pronouns are frequently homographs.** Each needs its own resolution:

| Language | Collision | Resolution |
| --- | --- | --- |
| `da` `nb` `nn` | `De`/`Dem`/`Deres` = formal *you* **and** everyday *they/them/their*; `de` is also the definite article | require a **mid-sentence capital**; exclude positions where a capital is explained by orthography (string start, after sentence punctuation, after an opening quote) |
| `pl` | `Państwo` = formal plural *you* **and** the noun *state/country* | mid-sentence capital for `Państwo`; `Pan`/`Pani` match anywhere |
| `cs` | `ty` = informal *you* **and** the plural demonstrative *those* | unsolvable by position — same case, same slot. Deliberately left unmatched; the possessives and imperatives are unambiguous, so nothing is lost |
| `ru` | `вы`/`ваш` are the ordinary polite address, not a plural-only form | not evidence of anything — don't match them at all |

Danish opens quotes with `”`, the glyph English uses to *close* one, so quote
handling must treat both directions as sentence-start.

## Harvested translations need a call-site check

Values harvested from Nextcloud core and sibling apps are a starting point, not
an answer. Observed error rate across locales: roughly 2–7 wrong per ~45
harvested. The failures are not typos — they are correct translations of a
*different* sense, and they pass every automated check.

Recurring offenders, all confirmed in this app:

- **`Right`** — a permissions-table header (`EditOrganisation.vue`, the "Special
  Rights" table listing `object_publish`). Sibling apps use the same English word
  for text alignment, so the harvest yields "right-aligned" (`ru` "По правому
  краю", `nl` "Rechts"). Needs the *legal/permission* sense: `Recht`, `Право`,
  `Uprawnienie`, `Právo`.
- **`View`** — an action button, not the noun. `ru` core gave "Режим просмотра"
  (view *mode*). Note the app also has a *saved view* noun elsewhere; check which.
- **`Open`** — an action button. Harvested from `circles` as the adjective
  ("Открытый").
- **`Search`** — a tab/field label, so the noun (`Поиск`), not core's verb
  ("Найти").
- **`Link`** — a confirm button in `LinkObjectDialog.vue`, so a verb
  (`Привязать`, `Propojit`), not the noun *hyperlink*.
- **`Subject`** — the GDPR *data subject* (`AvgIndex.vue`), not a mail subject.
- **`People`** — labels the `PERSON` entity type, so *persons* rather than humans
  in the abstract (`Персоны`, `Osoby`, `Personen`; not `Люди`, `Mennesker`).
- **`Revoke`** — for API tokens. Core has offered outright wrong senses:
  `nb` "Avslå" (*reject*), `pl` "Cofnij" (*undo*).
- **`Score`**, **`Step`**, **`Survivor`**, **`Reverse`** — quality/merge/approval
  domain terms; read `DuplicatesIndex.vue`, `ApprovalStepList.vue`,
  `MergeOperationsIndex.vue`.

False friends bite too: Polish `Data` means **date**, so leaving the `Data` tab
key as an identity would both mislabel it and collide with core's own term for
*Date*. Czech `data` is fine (its date word is `datum`).

Two precedence rules, applied consistently:

- **In-domain core wins.** `Bucket` takes core's `files_external` value even when
  it reads oddly (`pl` "Kosz", `ru` "Корзина", `nb` "Bøtte"), because that app *is*
  the S3 domain. Where core has no entry, bundle/technical convention decides
  (`da` keeps "Bucket").
- **Bundle-internal consistency outranks core.** If the bundle already says
  `Дашборд`, new strings say `Дашборд`, not core's `Панель управления`.

## Plurals

Take `nplurals` from the locale file's own header and build arrays against **that
locale's expression**. Equal counts do not mean equal boundaries:

- `ru` — `nplurals=3`, form 0 on `n%10==1 && n%100!=11`
- `pl` — `nplurals=3`, keyed on `n%10` ranges
- `cs` — `nplurals=3`, plain `1 / 2-4 / 5+`

All three are 3-form and mutually incompatible. `npm run test:l10n:parity`
catches wrong *length*; nothing can catch a Polish array pasted into Czech.

## Known source-side defect: `object{plural}`

Keys like `object{plural}`, `file{plural}`, `register{plural}` exist because a
caller interpolates a literal `"s"` or `""`. This is an English-only trick. It
degrades with morphological complexity — harmless in `es`/`pt`, a parenthetical
approximation in `da`/`nb`/`sv`, and genuinely lossy in `pl`/`cs`/`ru` where three
forms mean a parenthetical cannot cover the genitive. Current locales use
approximations like `объект(ы)`.

The real fix is in the source: use `n()` instead of interpolating a literal.
Worth a separate issue rather than more translation workarounds.

## Locale conventions already established

Per-language decisions that later work should stay consistent with, and which are
*not* derivable by analogy from a related language:

- **Domain-term capitalisation** — `da` lowercases (1:15), `sv` capitalises
  (54:2). `pl` follows `sv` but keeps `organizacja` lowercase (0:18). `cs`, `nb`
  and `ru` follow `da`.
- **Imperative accent** — `da` `Aktivér` (13:0) vs `nb` `Aktiver` (13:0), despite
  the two agreeing on capitalisation.
- **Error voice** — `da` passive; `nb` active `Kunne ikke`; `ru` `Не удалось`.
- **Ellipsis spacing** — `nb` puts a space before `...` (`Laster inn ...`); `ru`
  does not.
- **Quotes** — `ru` uses guillemets `«…»` for interpolated names; `da` opens with
  `”`.
- **Terminology** — `nb` `endepunkt` vs `da` `endpoint`; `ru` `Реестр` / `Схема` /
  `Объект` / `Дашборд` / `Поиск` / `конечная точка`, `Редактировать` for
  `Edit`-prefixed keys.

Twelve locales are complete: `nl`, `de`, `fr`, `es`, `it`, `pt`, `sv`, `da`, `nb`,
`pl`, `cs`, `ru`. Remaining high-confidence order: `uk`, `el`, `fi`, `hu`, `tr`,
`ca`, `et`, `hr`, `lt`, `lv`, `ro`, `sk`, `sl`. The nine low-resource locales
(`ga`, `mt`, `rm`, `is`, `lb`, `sq`, `mk`, `be`, `bs`) are deliberately last.

For non-Latin locales (`ru`, `uk`, `bg`, `be`, `mk`, `sr`, `el`) a script-coverage
check replaces the English-leftover check. Note it cannot distinguish an
untranslated string from a correct one built around a literal identifier — `ru`
legitimately retains 11 such values (`conversationId`, `fileCollection`,
`Zookeeper`, file paths), where every word of prose *is* translated.
