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

Measured results: informal for `nl`, `de`, `sv`, `da`, `nb`, `pl`, `fi`, `hu`,
`et`, `lv`; formal for `fr`, `cs`, `ru`, `uk`, `tr`, `el`, `sr`, `bg`, `ca`, `hr`,
`lt`, `sk`, `sl`. **`sk` is the least ambiguous of any locale measured for this app**:
1001 formal markers against 1 informal over 4991 values in 31 catalogues, beating
`lt`'s 689 vs 0 on volume. Russian is next: 328 formal pronouns and 164 formal
imperatives against zero informal.

That single Slovak informal hit is worth keeping rather than explaining away. It is
`Zruš prihlasovanie` in `core/sk.json` — a bare 2sg imperative where core uses the
infinitive for all 27 of its other short labels. One deviation in 4991 values is a
slip in core, not a second register; a detector that returned **zero** here would be
the more suspicious result, because it would mean the 2sg imperative was invisible
to it.

**Core can contradict the bundle's own pre-existing keys, and core wins.** This has
now happened twice, both times with the same shape: core measured *informal* while
the 1053 keys the file arrived with measured *formal*.

| Locale | Core | Pre-existing bundle | Outcome |
| --- | --- | --- | --- |
| `et` | informal, 420 vs 2 | **formal**, 26 vs 1 | followed core; 24 pre-existing values corrected |
| `lv` | informal, 44 vs 3 | **formal**, 85 vs 1 | followed core; 78 pre-existing values corrected |
| `ro` | **MIXED**, 124 vs 66 | formal, 84 vs 0 | followed the BUNDLE — core gave no verdict |

`ro` is why the rule is "measure core", not "obey core". Core ro is the first
genuinely mixed catalogue in this project: 124 formal markers against 66 informal
over 1078 values, with the informal strings concentrated in `core/ro.json` and
reading as newer work (`Te rugăm să alegi un fișier`, `Conectează-te la contul tău`)
while the formal ones dominate the email templates. A 2:1 split is not a verdict, so
the tiebreaker was the bundle's own 1053 keys — 25 formal pronouns and 59 formal 2pl
verb forms against **zero** informal pronouns — and, independently, the project
owner's native-speaker advice that Romanian users expect formal address in a web UI.
Note the direction: for `et` and `lv` core was one-sided and overruled the file; here
core was inconclusive and the file won. Both follow from the same rule.

The corrections are not optional cleanup — leaving them makes the bundle mix
registers inside a single dialog, which reads worse than either choice made
consistently. They go through `apply.js --allow-replace` with a reason recorded per
key in `locales/<loc>.json`, so the change is auditable rather than a silent side
effect of a bulk apply. For `lv` that means `Izvēlieties reģistru` → `Izvēlies
reģistru`, `jūsu filtriem` → `taviem filtriem`, and `Lūdzu, uzgaidiet` → the
impersonal `Lūgums uzgaidīt` that core lv itself prefers.

Latvian's evidence base is small (890 values in 9 catalogues — core ships far less
Latvian than Lithuanian) but one-sided: **zero** `jūs`-series pronouns anywhere in
core, 44 `tu`-series markers spread across `core`, `lib`, `settings`, `oauth2` and
`files_trashbin`, and the informal usage appears in UI prose (`Kuras datnes vēlies
paturēt?`) rather than only in notification email. The three formal hits are two
imperatives: `Skatiet dokumentāciju` twice, plus the tagline `Turiet savus kolēģus
un draugus vienuviet`.

**Button labels follow their own convention, and there are three patterns.**
Measure the *prose* register, then establish the button style separately from
core's own short labels — a single verdict for the locale is usually meaningless:

| Pattern | Locales | Buttons |
| --- | --- | --- |
| same register throughout | `tr`, `ru` | formal imperative |
| bare 2sg imperative, whatever the prose | `ca`, `et`, `hr`, `sl` | `Desa`, `Salvesta`, `Spremi`, `Shrani` |
| **infinitive — register-neutral** | `cs`, `lt`, `lv`, `sk` | `Zobrazit`/`Smazat`, `Įrašyti`/`Ištrinti`/`Atsisakyti`, `Saglabāt`/`Dzēst`/`Atcelt`, `Uložiť`/`Odstrániť`/`Zrušiť` |
| **verbal noun — register-neutral** | `ro` | `Salvare`/`Ștergere`/`Anulare`/`Adăugare endpoint` |

`ro` is the one locale where the button convention is a **project decision that
knowingly diverges from core**, rather than a measurement. Core ro uses the bare 2sg
imperative for 15 of its 16 short labels (`Salvează`, `Adaugă`, `Șterge`), but the
project chose no-familiar-address anywhere, so a bare 2sg imperative in label
position is a *deviation* for `ro` and `detectors/ro.js` counts it as informal — the
exact opposite of how `ca`/`et`/`hr` treat theirs. Within that, the split is by
string role, which is what the bundle already did: action labels take a verbal noun
(`Salvare`, `Adăugare endpoint`, matching core's own `Adăugare punct final`), while
anything addressing the user takes formal 2pl (`Selectați un registru`, `Sigur doriți
să ștergeți...`).

Uniform 2pl imperatives on buttons were measured to be **unavailable** without a
source fix: `Create`, `Read`, `Update` and `Delete` are single keys rendered both as
`<th>` column headers and audit-action filter labels (`AvgIndex.vue`,
`AuditTrailSideBar.vue` `actionOptions`) *and* as buttons. A header reading
`Ștergeți` — "delete!" — above a count column is wrong, so those four force the
verbal noun and the rest follow them. Same open defect class as `Url` vs `URL`.

Latvian makes the infinitive convention load-bearing for the *detector*, not just for
the translations: the Latvian infinitive ends in `-t`/`-āt`/`-at`, which is also the
2pl present ending. Every one of the 12 distinct `-at`/`-āt` words in core lv is an
infinitive or an adverb (`atjaunināt`, `saglabāt`, `turpināt`, `turklāt`) and **not
one** is a 2pl verb, so a suffix rule would score every button label in the language
as formal and invert the whole measurement.

Infinitive buttons must **not** be "corrected" to imperatives. Bare 2sg
imperatives must be **excluded from the detector**, because they are also
homographs — of the Catalan/Croatian 3sg present indicative (`uredi` = "edit!" /
"he edits") or of Estonian nouns and names (`Lisa`, `Ava`) — so counting them
would flag every button in the app. Lithuanian is the reason the table exists: its
informal count is a clean 0 precisely *because* core never produces a 2sg
imperative at all.

**`sk` is the exception, and it earns it.** Slovak's 2sg imperative is *not* a 3sg
homograph (`ulož` vs `uloží`), and its labels are infinitives, so `detectors/sk.js`
counts a bare imperative as informal where `ca`/`et`/`hr` must not. Before adding
that to any new locale, check both halves: the imperative must be distinguishable
from the third person *and* must not be the locale's own label convention. See the
`sk` section below.

**`sl` is the control that proves the rule is not about language family.** Slovenian
is Slovak's neighbour and fails *both* halves of that test — its imperative is the
label convention AND a 3sg homograph across the whole `-iti` class — so its detector
excludes what `sk`'s counts. Two adjacent Slavic locales, opposite answers, from the
same test applied honestly.

Three consecutive locales came out three different ways (`tr` formal 841:0, `ca`
formal 491:32, `et` **informal** 415:3). Carrying an answer over from the previous
locale would pass every automated check while being wrong in every string that
addresses the user.

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
| `hr` | `ti` = informal *you* **and** the masculine nominative plural of `taj` (`ti objekti` = *those objects*) — the same collision as `cs` `ty` | leave bare `ti` unmatched; the oblique forms (`tebe`/`tebi`/`tobom`) and the `tvoj-` possessive are unambiguous |
| `hr` | `si` = 2sg of *biti* **and** the reflexive dative clitic, which occurs in formal sentences (`možete si odabrati`) | leave bare `si` unmatched; use `nisi`/`jesi` |
| `sl` | `te` = accusative of informal `ti`, **and** the accusative plural of `ta` (`te datoteke` = *these files*), **and** the 2pl verb ending — three readings for two letters | leave bare `te` unmatched, like `ti`. The `tvoj-` possessive and `tebe`/`tebi`/`tabo` are unambiguous |
| `sl` | `vas` = formal *you* (acc/gen) **and** the noun *village* | kept anyway: a village is implausible in this app's domain. Recorded in `UNDETECTABLE` rather than silently accepted |
| `et` | `teist` = elative of `teie` (*of you*) **and** partitive of `teine` (*another*) — `Proovi teist otsingut` is *informal* 2sg | exclude `teist` entirely. Half of core's apparent formal signal was this one word: removing it took the count 6 → 3 |
| `tr` | 2sg possessive is spelled identically to the plural genitive (`dosyaların` = *your files* / *of the files*), and 3sg-possessive+accusative collides too (`hesabını`) | all 35 first-pass "informal" hits in core were this. Only `şifre`/`parola` are safe anchors |
| `lt` | **`gali`, `turi`, `nori` are 2sg *and* 3sg/3pl** — Lithuanian third person makes no number distinction, so these mean *you can/have/want* and *he/they can/have/want* alike. They are the three commonest modals in UI prose (`Registras gali turėti kelias schemas`) | exclude all three. Use only 2sg forms whose 3sg differs: `žinai` (3sg `žino`), `matai`, `gauni`, `esi` |

**Never use suffix patterns for these languages.** Lithuanian `-ai` ends the
nominative plural of every masculine noun (`objektai`, `failai`) *and* a large class
of adverbs (`gerai`, `automatiškai`). Croatian is the clearest case:
`-te` looks like the 2pl ending but is the accusative plural of every masculine
noun (`dokumente`, `objekte`, `atribute`), and `-š` looks like the 2sg ending but
ends `naš` (*our*) and **`vaš` (*your*-FORMAL)** — so a `-š` rule scores the formal
possessive as informal and inverts the polarity outright. Estonian `-ge`/`-ke` is
not a 2pl marker either (`selge`, `märge`). Use closed word lists.

Two JavaScript traps that silently weaken any detector: `\b` is ASCII-only, so use
`(?<!\p{L})…(?!\p{L})` with the `u` flag; and a reused `/g/` regex carries
`lastIndex`, turning later matches into misses — rebuild it per call. Turkish also
needs explicit case folding: `/i/ui` does not match `İ`, and `toLowerCase()` turns
it into `i + U+0307`.

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

### A whole catalogue can be the wrong language

Wrong-sense values are the common failure. The `sk` pass found the other kind:
`apps-custom/openbuild/l10n/sk.json` is **Croatian**, all 586 keys of it, and
offered `Dodaj shemu` and `Radnje` as Slovak. Fingerprinting every openbuild
catalogue showed one Croatian file shipped under **seven** names —
`bs cs hr mk sk sl sr` are value-identical — plus `da == sv` and `de == lb`. So
this poisons four more locales still in the queue (`sl`, `sr`, `mk`, `bs`), and it
is invisible to a call-site check because each hit *is* a plausible Slavic
translation of the right key.

`harvest.js` now drops any source whose value set matches the same app's catalogue
for a different **base** language, and prints what it dropped and what it
duplicates. Two real languages do not agree on hundreds of prose values, so this is
a measurement rather than a heuristic, and dropping only ever loses candidates.
The first cut of that check compared locale *names* and so reported core's
`et_EE.js` == `et_EE.json` as a mislabel, dropping 33 of 40 sources for `et` and
`lt` — the region-variant trap again. Compare `loc.split('_')[0]`.

## Plurals

Take `nplurals` from the locale file's own header and build arrays against **that
locale's expression**. Equal counts do not mean equal boundaries:

- `ru` — `nplurals=3`, form 0 on `n%10==1 && n%100!=11`
- `pl` — `nplurals=3`, keyed on `n%10` ranges
- `cs` — `nplurals=3`, plain `1 / 2-4 / 5+`
- `sk` — `nplurals=3`, byte-identical expression to `cs`, and the boundaries are
  **absolute, not modular**: `(n>=2 && n<=4) ? 1 : 2`. That is the load-bearing
  difference from `hr`/`ru`/`pl`/`lt`, which all key on `n%10`/`n%100`. So **22
  selects form 2 in Slovak and form 1 in Croatian** — and form 2 is right, because
  standard Slovak takes the genitive plural on compound numerals (`22 objektov`,
  not `22 objekty`). An array copied between two `nplurals=3` Slavic locales is
  wrong at every compound number, not just at the obvious 5–9 boundary. Note also
  that form 2 carries **zero** as well as 5+, so `0 objektov` must read correctly
  from the same string as `100 objektov`
- `hr` — `nplurals=3`, same expression as `ru`; the three forms are Croatian
  nominative singular / genitive singular / genitive plural
  (`1 objekt` / `3 objekta` / `7 objekata`)
- `lt` — `nplurals=3`, and **the boundaries are not Croatian's**: form 1 covers
  **2–9** (not 2–4) and 10–20 falls to form 2. Nominative singular / nominative
  plural / genitive plural (`1 objektas` / `5 objektai` / `10 objektų`). A Croatian
  array pasted here is wrong for every count 5–9 — the two locales share
  `nplurals=3` and disagree on where the forms switch

- `lv` — `nplurals=3`, and the third form is **a dedicated zero form**, not a
  "many" form: the categories are zero / numerals ending in 1 except 11 / everything
  else, which is exactly Latvian numeral agreement (genitive plural after 0,
  singular after 1 and 21, plural otherwise). See the order warning below
- `ro` — `nplurals=3` with boundaries no other locale here uses: form 0 is `n==1`
  **only**, form 1 covers **0 and 2–19**, form 2 is 20+. Zero joins the teens branch
  rather than having its own form, the mirror image of Latvian. The third form exists
  because Romanian inserts `de` before the noun from 20 up, which the runtime
  confirms: `1 email` / `2 emailuri` / `20 de emailuri`

- `sl` — `nplurals=4`, the **only** four-form locale in the finished set and the only
  one with a **dual**. Modular on `n%100`: form 0 is `n%100==1`, form 1 is `n%100==2`
  (the dual), form 2 is `n%100==3||4`, form 3 is everything else including **zero**.
  The dual is not a spelling variant of the plural — it governs noun case, adjective
  agreement **and verb number** at once, so a count of 2 needs `Objekta sta bila
  uspešno izbrisana` where the plural needs `Objekti so bili uspešno izbrisani`. In an
  accusative context the four forms are `objekt` / `objekta` / `objekte` / `objektov`;
  in the nominative, `objekt` / `objekta` / `objekti` / `objektov`. The pre-existing
  `_%n entry has no hash yet_` array already had the dual verb right (`nimata` vs
  `nimajo`) and is the model to copy

All are mutually incompatible. `npm run test:l10n:parity` catches
wrong *length*; nothing can catch a Polish array pasted into Czech. Verify the
forms are reachable by driving the real `@nextcloud/l10n`: call `unregister()` and
`setLanguage(loc)` first, because `register(app, bundle)` **ignores** a plural
function passed to it and installs the library's own `getPlural`. For `hr`,
counts 1/3/7 must select three *different* indices.

### The header and the library can disagree on ORDER, not just count

Writing an array to match the file's own `plural=` header is the obvious thing to
do, and for Latvian it is wrong at **every** count. Measured against the real
library over counts 0–1001:

| | index 0 | index 1 | index 2 |
| --- | --- | --- | --- |
| `lv.js` header (legacy gettext) | 1, 21, 101 | 2–20, 22–100 | 0 |
| `@nextcloud/l10n` (what renders) | **0** | **1, 21, 101** | **everything else** |

Same three categories, rotated. An array ordered by the header therefore renders
the singular for zero, the plural for one, and the zero form for two — while
`nplurals` matches, every array has three forms, nothing renders blank and nothing
renders English. Arity, parity and the not-English assertions all pass. It was found
only by asking which array *index* the library picks per count, which
`runtime-check.mjs` now does for every locale.

So `lv` arrays are ordered **by the library**, and `locales/lv.json` records
`"pluralOrder": "library"` to say so. Without that record the runtime check fails,
which is deliberate: the next reader's instinct will be to "fix" the order back to
the header. Among the 22 finished locales only `lv` is affected — `hr`, `lt`, `ru`,
`cs`, `pl`, `uk` and the rest agree with their headers exactly, and `tr`/`rm`/`ga`
disagree only on form *count*, where the unreachable extra forms cannot be
mis-ordered. Of the locales still to do, `is` and `mk` also disagree (at 21/101 and
at 11 respectively) — check before writing their arrays.

## Known source-side defect: `object{plural}`

Keys like `object{plural}`, `file{plural}`, `register{plural}` exist because a
caller interpolates a literal `"s"` or `""`. This is an English-only trick. It
degrades with morphological complexity — harmless in `es`/`pt`, a parenthetical
approximation in `da`/`nb`/`sv`, and genuinely lossy in `pl`/`cs`/`ru` where three
forms mean a parenthetical cannot cover the genitive. Current locales use
approximations like `объект(ы)`.

What each finished locale actually does, so the next one has a precedent to pick
from rather than reinventing it:

| Shape | Locales | Example |
| --- | --- | --- |
| keep the placeholder — the plural really is `+s` | `es`, `ca` (4 of 5) | `fitxer{plural}` |
| parenthetical | `nl` `de` `fi` `ru` `pl` `cs` `et` | `bestand(en)`, `fail(i)` |
| slash, where the stem changes | `fr`, `ca` (`schema`), `et` (`register`) | `journal/journaux`, `esquema/esquemes` |
| bare noun — no plural suffix after a numeral | `hu`, `tr` | `fájl`, `dosya` |
| the form correct for the most counts | `hr` | see below |
| genitive plural, the conventional invariant counter | `lt` | `failų`, `objektų`, `registrų` |

Catalan learned this the hard way: masculine nouns in `-a` pluralise in `-es`, so
`schema{plural}` rendered **`esquemas`** until the runtime harness caught it.
Estonian drops the placeholder for all five keys, because a numeral above one takes
the *partitive singular* (`5 faili`, not `5 failid`), so an appended `s` is wrong at
every count.

Croatian has **three** numeral cases (1 → nom.sg, 2–4 → gen.sg, 5+ → gen.pl), so no
invariant string is right everywhere. Each value picks the form correct for the most
counts, which depends on the noun's gender: `datoteka` and `shema` (feminine —
correct for 1, 0 and 5+) but `zapisnika`, `objekata`, `registara` (masculine, where
gen.sg and gen.pl coincide — correct for 2–4 *and* 5+). Always runtime-assert that
no `{plural}` residue and no stray trailing `-s` survives.

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
- **`hr`** — `trag revizije` for *audit trail* (measured 24:4 against
  `revizijski trag` in the file's own existing values), `prikaz` for the *view*
  noun but `Pregledaj` for the *View* button, `vektorska ugrađivanja` for *vector
  embeddings*, `Pravo` for the RBAC *Right*, `ispitanik` for the GDPR *data
  subject* (the term used by the official Croatian text of the regulation),
  `Razred` for a histogram *Bucket*, and `Mapiranja` for *Mappings* — **not**
  openconnector's `Mappingi`, a non-standard transliteration. Two app-internal
  conventions deliberately override core: `Tip` for *Type* (core says `Vrsta`) and
  `lozinka` for *password* (core prefers `zaporka` 195:62, but the file already
  shipped `Lozinka` and it is valid Croatian, so it was not churned).

- **`lt`** — buttons are **infinitives** (see the register section). `audito
  žurnalas` for *audit trail* (24:3 in the file's own values), `rodinys` for the
  *view* noun but `Peržiūrėti` for the *View* button, `esybė` for *entity* — chosen
  so it does not collide with `duomenų subjektas` (*data subject*) — `užtemdymas`
  for *redaction*, because the obvious `redagavimas` is the same word this app uses
  for **Edit** (`Redaguoti`). `žiniatinklio kabliukas` for *webhook*: all 40
  existing webhook keys translate it, so do not keep the English. `Ataskaitos` for
  *Reports*, distinct from `Pranešimai` (*Notifications*). `minkštai pašalinti` for
  *soft-deleted*. `Delete` → `Pašalinti` but `Remove` → `Šalinti`: the file splits
  those two senses deliberately. Bare `Name` → `Vardas`, but name-in-compound →
  `pavadinimas` (`Failo pavadinimas`). GDPR is **`BDAR`**, not `GDPR`.

**Check the existing values before coining a term.** For `hr` the audit-trail,
view, embeddings and `Tip`/`Filtri` conventions were all already present in the
1053 keys the file arrived with; deriving them from core instead would have split
the locale's own terminology. `lt` was the same, and stronger: `webhook` looked
like an obvious keep-the-English case until the file showed 40 keys translating it.

`lv` proves the rule by breaking it mid-pass, twice. The pass coined `šķautne` for
*facet* and wrote `AI aģentus`; the bundle already had `Iespējot fasetēšanu` and
`MI aģents` at HEAD. Both were reverted to the file's own terms through the audited
`--allow-replace` path — the split was invisible until the pre-existing values were
read side by side with the new ones, which is why that read belongs *before* the
first batch, not after the last. Latvian's own established terms, followed here:
`Reģistrs`, `Shēma`, `Objekts`, `Fails`, `Entītijas`, `Tīmekļa āķis` (webhook *is*
translated), `Informācijas panelis` for dashboard (not the harvest's `Vadības
panelis`), `Fragments` for chunk, and `fasete`/`fasetēšana`.

### `ro` traps

Four, and every one of them is live in this app's data:

1. **`vă` (formal you) vs `va` (3sg future auxiliary).** One diacritic apart, and
   `va` is everywhere — `Acest proces va genera embeddings` is an ordinary future
   tense, not formal address. Match only `vă`.
2. **`ai` is unusable and is excluded.** It is the 2sg of *avea*, but also the
   masculine plural possessive article (`ai tăi`), and under case folding it collides
   with the acronym **AI**, which this bundle carries in `Setări chat Fireworks AI`.
3. **CEDILLA vs COMMA diacritics.** Romanian ș/ț exist as two codepoint pairs:
   ș U+0219 / ț U+021B (correct) and ş U+015F / ţ U+0163 (legacy Turkish cedilla).
   Core ro mixes them — 362 values comma-form, 5 legacy strings cedilla
   (`contactaţi`, `fişiere`) — so `detectors/ro.js` normalises cedilla to comma in
   `fold()`, or its closed lists silently miss those strings. Write the comma form:
   this bundle is already consistent at 419 values, 0 cedilla.
4. **`-ați` / `-eți` is not a 2pl suffix.** It also ends the masculine plural of a
   large class of adjectives and nouns: `curați` (clean), `bogați` (rich), `pereți`
   (walls), `băieți` (boys). Likewise `-ești` ends `povești` (stories) as well as 2sg
   verbs. Closed lists only.

Also note the 3sg/imperative homograph that decided the correction scope: `Creează
automat o organizație implicită` is *"automatically creates"*, not *"create!"*. A
string-initial 2sg-imperative scan reports 11 hits in `ro.js`, but only the 4 short
ones are buttons; the other 7 are long toggle descriptions where the same form is
third person. The detector's 40-character label-position bound is what separates
them, and `Copiază` in a table cell would still be a false positive — see
`UNDETECTABLE` in the detector.

Terminology worth keeping: `ro` **borrows** where `lv`/`lt` translate — `Webhook` /
`Webhook-uri`, `Endpoint` / `Endpoint-uri`, `Driver`, `Token`, and `AI` (not `MI`).
GDPR is `RGPD`, and *data subject* is the official `persoana vizată`. `Bucket` became
`Segment` because `Interval` was already taken by its own key.

### `lv` homograph traps

Latvian has two *systematic* homograph classes, not a handful of exceptions, and both
make the obvious 2sg markers unusable:

1. **For `-ēt` and `-āt` verbs the 2sg form is spelled exactly like the third
   person**, because Latvian third person makes no number distinction: `meklē` is both
   "you search" and "it searches"; likewise `saglabā`, `aizver`, `atver`. Counting
   them turns ordinary third-person prose (`Sistēma saglabā izmaiņas`) into informal
   hits.
2. **Feminine nouns in `-e` have an accusative singular in `-i`, colliding with the
   2sg imperative**: `pārbaudi` is both "check!" and the accusative of `pārbaude`
   (a check); `redzi` collides with `redze` (vision), `atlasi` with `atlase` (a
   selection). `ievadi` is the same trap from the masculine side — nominative plural
   of `ievads` (an input).

What is left and genuinely unambiguous is the pronoun series plus 2sg forms whose
third person differs: `vari` (vs `var`), `zini` (vs `zina`), `esi` (vs `ir`), `spied`
(vs `spiež`). Also note `-i` is the nominative plural of masculine nouns (`faili`,
`objekti`, `lietotāji`) — the same trap Lithuanian has with `-ai` — and `-iet` is not
a 2pl marker either: of the four distinct `-iet` words in core lv, `skatiet` and
`turiet` are 2pl imperatives while `vienuviet` is an adverb and `nešķiet` is third
person.

Two consequences worth keeping: the detector's informal count for `lv` is *low*
(109 markers across 2052 values) because most correct informal Latvian is
undetectable by design, so **zero formal markers is the assertion that matters**, not
a high informal count. And the `{plural}` source hack takes the **nominative plural**
(`faili`, `žurnāli`, `objekti`, `reģistri`, `shēmas`): Latvian needs the plural after
every numeral except those ending in 1, so it is right for the large majority of
counts, and those keys render as a `countLabel` beside a figure rather than inside a
sentence.

### `sk` is the one locale where the 2sg imperative IS detectable

Every other locale so far has had to leave the bare 2sg imperative *unmatched*, for
one of two reasons: it is the correct button convention (`hr`, `ca`, `et`), or it is a
homograph of the third person (`ro`'s `Creează`, `lv`'s `meklē`, `hr`'s `uredi`).
Slovak has neither problem, and `detectors/sk.js` therefore counts it as **informal**
— the opposite of how `ca`/`et`/`hr` treat theirs. That is not a policy difference; it
follows from three measured facts:

1. the label convention is the **infinitive** — 27 of 30 short action keys in core sk
   resolve to one (`Uložiť`, `Zmazať`, `Pridať`, `Vybrať`), and **zero** to an
   imperative, so an imperative label is a deviation rather than the house style;
2. the 2sg imperative is **not** a homograph of the 3sg present — `ulož` vs `uloží`,
   `pridaj` vs `pridá`, `zmeň` vs `zmení` — so no `automatically creates` description
   can be mistaken for a command;
3. the infinitive ends in `-ť`, which no imperative does.

Because of (2), `sk` needs **no label-position bound**. `ro.js` carries a
40-character one only because Romanian's imperative *is* a 3sg homograph.

So `sk` pairs `lv`'s infinitive labels with `ro`'s formal 2pl prose — the fourth
distinct combination in this app. The role split is the same as `ro`'s: infinitive for
buttons, menu items, dialog titles and bare field captions; formal 2pl for anything
addressing the reader. The English source marks the boundary reliably here, which it
did not for `ro`: `Select backend` is a field label (`Vybrať backend`) while
`Select a branch` is a prompt (`Vyberte vetvu`), and the indefinite article is the
tell.

### `sk` traps

1. **`vy-` is the most productive verbal prefix in the language.** `vybrať`,
   `vymazať`, `vytvoriť`, `vyhľadať`, `vypnúť`, `vyčistiť`, `vypočítať` — an unguarded
   `vy` pronoun marker matches most of the buttons in this app. `Vybrať všetko` is a
   must-not-fire control for exactly this.
2. **DIACRITICS MUST NOT BE FOLDED.** Three of the detector's distinctions are
   carried by the acute alone, so `fold()` only lowercases — the opposite of `ro`,
   where `fold()` *must* normalise cedilla to comma:
   - `ti` (dative of `ty`, informal) vs **`tí`** (masculine animate nominative plural
     of `ten` — `tí používatelia` = *those users*);
   - `vyber` (2sg imperative) vs **`výber`** (*selection*, which this bundle uses in
     five keys — `Výber typu súboru`);
   - `uprav` (2sg imperative) vs **`úprav`** (genitive plural of *edit*).
3. **`-te` is not a 2pl suffix.** It is the locative singular of every hard masculine
   noun (`v dokumente`, `v objekte`, `v elemente`) and it ends **`ešte`** (*still*,
   *yet*), one of the commonest adverbs in the language — which appears in this
   bundle's own `notify_push je nainštalovaný, ale ešte nie je aktívny`.
4. **`-š` inverts polarity, exactly as in `hr`.** It looks like the 2sg ending but
   ends `váš` (*your*-FORMAL), `náš` (*our*) and `kôš` (*basket*).
5. **Bare `si` is unusable.** Besides the 2sg of *byť* it is the reflexive dative
   clitic, which is at its most common in *formal* prose — this bundle's own
   `Pred rozhodnutím si záznam prečítajte` and `môžete si vybrať`.

Terminology: `sk` sits between `ro` (borrow) and `lv`/`lt` (translate). It keeps
`Webhook`/`Webhooky`, `Slug`, `Audit`, `Avatar` and `Register` (which genuinely *is*
the Slovak word — `ID registra`, `Všetky registre`), but translates `Driver` →
`Ovládač`, `Mappings` → `Mapovania` (**not** openconnector's `Mappingy`), `Right` →
`Právo`, and `Bucket` → `Pásmo`, since `Interval` was already taken by its own key —
the same collision `ro` resolved with `Segment`. `audítny záznam` for *audit trail*,
`záznam auditu` for an *audit entry*, `úsek` for *chunk*, `vloženie` for *embedding*,
`nástenka` for *dashboard*, `tok` for *flow* but `pracovný postup` for *workflow*,
`riešiteľ` for a DSAR *handler* (not `spracovateľ`, which is the GDPR *processor* and
would collide), and `Osoby` for `People` — core sk says `Ľudia`, but the bundle's own
`Person` → `Osoba` wins on lexicon. The `{plural}` hack reuses the bundle's own
pre-existing parenthesised style: `register(s)` was already `register(-tre)` and
`schema(s)` already `schéma(-y)`, so those two keys are literally the existing values.

### `sl` looks like `sk` on the map and behaves like `hr` in the data

The two are neighbours, both West-adjacent South Slavic, both formal in core. They
agree on nothing else that matters here, and taking `sk`'s answers across would have
been wrong on every count:

| | `sk` | `sl` |
| --- | --- | --- |
| Button labels | infinitive (`Uložiť`) | bare 2sg imperative (`Shrani`) |
| 2sg imperative in the detector | counted as informal | **excluded** |
| Plural forms | 3, absolute boundaries | **4**, modular, with a dual |
| Borrowing | keeps `Webhook`, `Slug`, `Port`, `Audit` | translates all four |

Core sl uses the bare 2sg imperative for 23 of its 26 short labels, with **zero** 2pl
and zero infinitive; the remaining three are nouns (`Souporaba`, `Izbor`) or the
adverb `Nazaj`. So `sl` joins `ca`/`et`/`hr` in the imperative-buttons row, and its
imperative must be **excluded** from the detector for the ca/et/hr reason *plus* one
of its own: across the whole `-iti` verb class the 2sg imperative is spelled exactly
like the 3sg present indicative — `uredi`, `shrani`, `osveži`, `obnovi`, `posodobi`,
`preveri` are each both "do X!" and "he does X", and all six are button labels in
this bundle. (`-ati` and `-irati` verbs do differ — `dodaj` vs `doda` — but the
collision class is far too large to carve out.)

### `sl` traps

1. **`ti` and `te` both point in two directions.** `ti` is informal *you* **and** the
   masculine nominative plural of `ta` (`ti objekti` = *those objects*) — the `cs`/`hr`
   collision again. `te` is worse: the accusative of informal `ti`, **and** the
   accusative plural of `ta` (`te datoteke` = *these files*), **and** the 2pl verb
   ending. Both are left unmatched; the oblique forms (`tebe`/`tebi`/`tabo`) and the
   `tvoj-` possessive carry the signal instead.
2. **Bare `si` is the reflexive dative clitic**, commonest in *formal* prose
   (`lahko si izberete`), as in `hr` and `sk`.
3. **`-š` inverts polarity**, exactly as in `hr` and `sk`: `vaš` (your-FORMAL) and
   `naš` both end in it. Note the asymmetry the closed lists exploit — the 2sg
   *present* (`spremeniš`, `shraniš`) is safe to enumerate because the 3sg drops the
   `-š`, while the 2sg *imperative* is not, because it collides with the 3sg.
4. **`vas` is also the noun "village".** Kept as a formal marker anyway, since a
   village is implausible in this app's domain, but it is a real false-positive path
   and is recorded in `UNDETECTABLE`.
5. **The dual is a third address form** (`vidva želita`) that no other locale here
   has. It addresses exactly two people, never appears in UI prose, and is not
   matched — but it is the reason `nplurals=4`.

Terminology: `sl` translates where `ro` and `sk` borrow — `spletni kljuk` for webhook,
`koristni tovor` for payload, `Vrata` for Port, `Oznaka` for Slug, `žeton` for token,
`del` for chunk, `vložitev` for embedding, `zgoščena vrednost` for hash, `nadzorna
plošča` for dashboard, `revizijska sled` for audit trail against `revizijski vnos` for
an audit entry, `potek dela` for workflow but `tok` for flow. **AI is rendered `UI`**
(*umetna inteligenca*) — so generic AI strings take `UI` while product names keep the
English (`Fireworks AI`, `OpenAI`, `Dolphin AI`), following the pre-existing values.
Two deliberate divergences from the harvest: `Revoke` is `Odvzemi`, not core's
`Prekliči`, because this bundle already uses `Prekliči` for **Cancel** and the two
share the account screen; and `Right` is `Pravica`, not core's `Desno`. `Quota` takes
core's `Količinska omejitev` even though it is long, because settings *is* the quota
domain. Ellipses take a **space before** in both spellings (`Poteka nalaganje ...`,
`Preverjanje …`), and progressive states use the impersonal `Poteka X ...`.

Two pre-existing values were corrected, both single occurrences against a large
consistent majority, and both worth noting as a *class*: `Audit trail #{id}` read
`Revizijski trag` — **`trag` is Croatian**, Slovenian is `sled`, which the bundle's
own 14 other audit-trail keys already used — and one confirm dialog opened with
`Predmeti` against 74 values using `objekt`. Given that openbuild ships a Croatian
catalogue under `sl.json`, a Croatian word turning up in a Slovenian bundle is not a
coincidence to shrug at. A cheap check that caught nothing else: scan the finished
bundle for `ć đ ě ř ů ą ę ł ń ś ź ż`, none of which exist in Slovenian orthography.

Twenty-five locales are complete: `nl`, `de`, `fr`, `es`, `it`, `pt`, `sv`, `da`,
`nb`, `pl`, `cs`, `ru`, `uk`, `el`, `fi`, `hu`, `tr`, `ca`, `et`, `hr`, `lt`, `lv`,
`ro`, `sk`, `sl`. Remaining high-confidence order: `bg`, `sr`. The nine
low-resource locales (`ga`, `mt`, `rm`, `is`, `lb`, `sq`, `mk`, `be`, `bs`) are
deliberately last — **ask before starting them.**

For non-Latin locales (`ru`, `uk`, `bg`, `be`, `mk`, `sr`, `el`) a script-coverage
check replaces the English-leftover check. Note it cannot distinguish an
untranslated string from a correct one built around a literal identifier — `ru`
legitimately retains 11 such values (`conversationId`, `fileCollection`,
`Zookeeper`, file paths), where every word of prose *is* translated.
