# Translating a UI locale

How to take one `l10n/<lang>.js` from partial to complete. Scope is **frontend UI
strings only** — the `l10n/*.js` set read by `t()` / `n()`. Backend `l10n/*.json`
and the translatable-object-property machinery in `docs/i18n.md` are separate
concerns.

The hard rules (never `value === key`; plural arity per locale; don't overwrite
real translations) are in `CLAUDE.md`. **The workflow — the pass in order, what each
gate refusal means, and the traps catalogue — is `docs/l10n-workflow.md`; read that
first.** This document is the per-locale *linguistic* reference behind it: the
judgement calls that no tooling can make for you, and what each finished locale
decided.

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
`et`, `lv`, `ga`, `mt`, `is`; formal for `fr`, `cs`, `ru`, `uk`, `tr`, `el`, `sr`, `bg`, `ca`, `hr`,
`lt`, `sk`, `sl`, `rm`.

**`cs` is measured as of this pass, not inherited.** It sat in the formal column from before
any of the tooling existed (it is one of the sixteen pre-rule locales), and re-measuring it
gave **828 formal markers against zero informal** over core's 32 catalogues / 5005 values,
plus 243-vs-0 in the bundle itself. It is also the *plainest* entry in either column: Czech
has a live `vy`/`ty` distinction in ordinary current use, so unlike `ga`, `mt` and `is` there
is no structural story behind the verdict. Those three were the exceptions; `cs` is the
baseline they were exceptions to.

**"Informal" does not mean the same thing in every row of that list**, and the three
low-resource locales done in sequence make the point better than any argument: they
measured identically and are three different situations. `ga` — Irish never had a T-V
distinction, so the label names the only address form that exists. `mt` — Maltese has one
and it is current (`intom` as a polite singular, `Is-Sinjur` with third-person agreement);
it is simply unused, so the verdict is an ordinary choice. `is` — Icelandic *had* one
(`þér` plus a 2pl verb, possessive `yðar`) and abandoned it during the 20th century, so its
V-forms are archaic rather than absent or merely unfashionable. That third state changes
what a slip looks like: for `is` the realistic error is not `yðar` at all but the plain
modern plural `þið`/`ykkar`, because a translator importing a politeness plural from
de/fr/nl reaches for the form that is actually current. Never copy a verdict without its
reason. **`sk` is the least ambiguous of any locale measured for this app**:
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

**Button labels follow their own convention, and there are four patterns.**
Measure the *prose* register, then establish the button style separately from
core's own short labels — a single verdict for the locale is usually meaningless:

| Pattern | Locales | Buttons |
| --- | --- | --- |
| same register throughout | `tr`, `ru` | formal imperative |
| bare 2sg imperative, whatever the prose | `ca`, `et`, `hr`, `sl`, `sr`, `ga`, `mt` | `Desa`, `Salvesta`, `Spremi`, `Shrani`, `Сачувај`, `Sábháil`, `Issejvja` |
| **infinitive — register-neutral** | `cs`, `lt`, `lv`, `sk`, `rm`, `is` | `Zobrazit`/`Smazat`, `Įrašyti`/`Ištrinti`/`Atsisakyti`, `Saglabāt`/`Dzēst`/`Atcelt`, `Uložiť`/`Odstrániť`/`Zrušiť`, `Memorisar`/`Stizzar`/`Annullar`, `Vista`/`Eyða`/`Hætta við` |
| **verbal noun — register-neutral** | `ro`, `bg` | `Salvare`/`Ștergere`/`Anulare`/`Adăugare endpoint`; `Запазване`/`Изтриване`/`Отказ`/`Добавяне на крайна точка` |

`bg` reaches the same form as `ro` by the ordinary route rather than by divergence, and
for a reason no Latin-script locale here has: **Bulgarian has no infinitive at all.** It
lost the form, so the verbal noun (отглаголно съществително, `-не`) is the only
register-neutral option available — it does the job the infinitive does in
`cs`/`lt`/`lv`/`sk`, and the "infinitive" row is simply not reachable for this language.
Core `bg` is genuinely mixed (44 catalogue-weighted verbal nouns, 23 bare 2sg
imperatives, 4 formal 2pl `Потвърдете`, 4 plain nouns), so the measurement alone does not
decide it; the file does, with 1053 pre-existing values that are verbal nouns or plain
nouns and not one imperative label.

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

- `bg` — `nplurals=2; plural=(n != 1)`, the simplest header in the set, and the one
  locale where the **arity is not the problem**. Bulgarian masculine non-person nouns
  have a separate counting form (числителна форма, `-а`/`-я`) used after a numeral,
  distinct from the ordinary plural: `обект` → `обекти` bare but `обекта` after a
  number, likewise `запис`/`записа`, `файл`/`файла`, `регистър`/`регистъра`. So the
  noun form is chosen by **the sentence, not the form index** —
  `_Delete {count} object_` takes `Изтриване на {count} обекта` because a numeral
  precedes, while `_Object successfully deleted_` takes `Обектите са изтрити успешно`
  because none does. Two keys, one form index, two different words. Masculine person
  nouns keep the plural (`{count} членове`); feminine and neuter have no count form
  (`схема` → `схеми` either way)

- `rm` — `nplurals=2; plural=(n != 1)` in the header, but `@nextcloud/l10n` has **no
  entry for Romansh at all**, so its `getPlural` returns index 0 at *every* count
  (verified over 0, 1, 2, 3, 5, 11, 21, 100, 101). Form 1 is unreachable. The runbook
  files this with `tr` and `ga` as a harmless NOTE, and **for `rm` it is not harmless**:
  the reason one form is fine for Turkish is that Turkish does not pluralise after a
  numeral, whereas Romansh pluralises regularly with `+s`, so a bare singular in form 0
  renders `5 datoteca`. Form 0 therefore has to be acceptable at every count. The answer
  is the `(s)` parenthetical the bundle already used for its own `(s)` sibling keys —
  `Stizzar {count} object(s)` — which is never wrong at any count. The one plural key
  with no numeral takes a **number-neutral** phrasing instead (`Stizzà cun success`),
  because a parenthetical cannot be spread across three agreeing words. Form 1 is still
  written as the true plural so the array becomes correct if the library ever gains an
  `rm` entry. This is the mirror of the `lv` problem in §"The header and the library can
  disagree on ORDER": there the library reorders the forms, here it collapses them

- `ga` — `nplurals=5`, **the largest declared form count in the set**, and the library
  uses only **three** of them: measured over counts 0–120, index 0 takes `n==1`, index 1
  takes `n==2`, and index 2 takes `0` and every `n>=3`. Forms 3 and 4 are unreachable, so
  the header and the library disagree at every `n>=7`. Unlike `rm`, that collapse **is**
  harmless, and it is measured rather than assumed: across core `ga`'s 101 fully-translated
  five-form arrays, form 2 differs from form 3 in **zero** cases and form 3 from form 4 in
  **zero** cases — core writes the last three identically without exception. What actually
  decides these arrays is not the form index but the **counted-noun rule**: Irish takes the
  *singular* after a numeral (An Caighdeán Oifigiúil — numerals 1–19 govern the singular),
  so a value containing `{count}`/`%n` needs the same counted singular in all five forms
  while a value with **no** numeral needs a real singular/plural split. Core's own data
  separates on exactly that axis — of 73 numeral-bearing arrays, 53 keep the singular
  throughout against 20 that pluralise (the calqued minority), while **28 of 28** arrays
  without a numeral pluralise. Hence five of this bundle's seven arrays are all-forms-
  identical (`Scrios {count} réad`, the `hu`/`tr` shape) and only
  `_Object successfully deleted_::_Objects successfully deleted_`, which carries no
  numeral, splits (`Scriosadh an réad` / `Scriosadh na réada`). **Initial mutation is not
  applied after a digit**, which is also core's measured practice and not a guess: core
  writes form 0 as `%n comhad`, `%n beart`, `%n carachtar`, `%n fógra`, `%n soicind` —
  never `chomhad`/`bheart`/`charachtar` — even though `aon` lenites in a spelled-out
  numeral phrase. A **fixed** numeral in a label is different and does take the mutation
  (`Last 3 months` → `3 mhí anuas`, from `trí mhí`); it is only the `{count}` placeholder,
  whose value is unknown, that stays unmutated

- `mt` — `nplurals=4`, and **the header and the library agree exactly**, verified
  index-by-index over counts 0–130 with all four forms reachable. That makes it the first
  low-resource locale here with no plural surprise at all: no rotation as in `lv`, no
  collapsed form count as in `rm`/`ga`/`tr`. The arrays are still the hardest in the set,
  because Maltese counting is **Semitic rather than European**: the noun is PLURAL only
  after 2–10 (and 0), and SINGULAR after 1, after 11–19 (`ħdax-il ktieb`) and after 20+
  (`għoxrin ktieb`). So forms 0, 2 and 3 normally carry the same string and only form 1
  differs — which reads as three duplicated forms and is correct. §2.3 had flagged this
  bundle's one pre-existing array as *suspect, forms 2 and 3 fall back to the singular*;
  verification cleared it and it was left untouched. The harvest corroborated the rule
  independently: `Last 7 days` → `L-aħħar 7 ijiem` (plural) against `Last 30 days` →
  `L-aħħar 30 jum` (singular). One further trap: **the whole predicate agrees, not just the
  noun** — `%n entrata għadha m'għandhiex hash` against `%n entrati għadhom m'għandhomx
  hash` — so an array cannot be built by swapping the noun alone

All are mutually incompatible. `npm run test:l10n:parity` catches
wrong *length*; nothing can catch a Polish array pasted into Czech, and nothing at all
catches the Bulgarian count form or a Romansh form 0 that only reads correctly at 1. Verify the
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
the header. `hr`, `lt`, `ru`, `cs`, `pl`, `uk` and the rest agree with their headers
exactly, and `tr`/`rm`/`ga` disagree only on form *count*, where the unreachable extra
forms cannot be mis-ordered.

**But `lv` is only one of two kinds of disagreement, and the other one takes the opposite
remedy.** `lv`'s is a **permutation**: the header and the library carve the counts into the
same three groups and merely label them differently, so reordering the arrays makes the
locale completely correct. A **boundary** disagreement puts the lines in different *places*,
and then no reordering exists that helps — you choose which counts to be correct for and
record the residue. `runtime-check.mjs` now classifies which one you have and names the
right field: `pluralOrder: "library"` for a permutation, `pluralBoundary: "library"` for a
boundary.

Both remaining flagged locales turned out to be boundary cases, **in opposite directions**:

- `is` — the library files Icelandic under its coarse `number === 1 ? 0 : 1` group, so form 0
  is reachable only at exactly 1. The header is correct CLDR Icelandic
  (`n%10!=1 || n%100==11`), under which 21, 31 … 191 also take the *singular* (`21 hlutur`).
  So 17 counts in 0–200 render a plural where the language wants a singular. Accepted rather
  than worked around, and the reasoning is the mirror of `rm`'s: form 1 is correct for 0 and
  2–20, the overwhelming majority of real counts, so contorting it into a number-neutral
  shape would trade 17 wrong counts for roughly 180 unidiomatic ones. `rm` needed the
  contortion because its collapsed form was wrong at *every* count but 1.
- `mk` — same modular header, but the library implements the modular rule and merely **drops
  the `n%100 != 11` guard** (`number % 10 === 1 ? 0 : 1`), so only 11 and 111 disagree, and
  they go the other way: the library picks the *singular* where Macedonian takes the plural.

The general signal: **a two-form header whose expression is modular rather than `n != 1`**
is the shape to check, because the library's coarse groups are mostly written `n === 1`.
`lb` and `sq` carry plain `n != 1` and agree exactly; `bs` and `be` are three-form Slavic
and also agree.

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
  and `ru` follow `da`. `sr` is the strongest case and the only one that splits by
  *term*: six concepts capitalised, everything else lowercase — see the `sr` section.
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
the same collision `ro` resolved with `Segment`. **`auditná stopa` for *audit trail*
(the re-audit re-coined this from `audítny záznam` — see below)**, `záznam auditu` for
an *audit entry*, `úsek` for *chunk*, `vloženie` for *embedding*,
`nástenka` for *dashboard*, `tok` for *flow* but `pracovný postup` for *workflow*,
`riešiteľ` for a DSAR *handler* (not `spracovateľ`, which is the GDPR *processor* and
would collide), and `Osoby` for `People` — core sk says `Ľudia`, but the bundle's own
`Person` → `Osoba` wins on lexicon. The `{plural}` hack reuses the bundle's own
pre-existing parenthesised style: `register(s)` was already `register(-tre)` and
`schema(s)` already `schéma(-y)`, so those two keys are literally the existing values.

### `sk` is the first Tier 2 locale re-audited, and a `0` count meant unverified, not clean

`sk` had a measured register, a detector, a reviewed cognate set and a terminology
record, but `corrections` was **empty**. The audit of all 2052 values found **57**
defects — so a zero means nobody looked, exactly as the re-audit handoff assumed. But
it does **not** mean a quarter of the file is waiting: 2.8% sits below `cs`'s 5.5% and
far below `is`'s 22%, with **zero** agreement failures, **zero** case errors and
**zero** wrong plural arrays. `sk` is the closest sibling to `cs` in the set, which was
why it was picked, and the two agree: the defect rate tracks upstream health, not the
length of time un-audited. **Tier 2 is cheaper than the `is` numbers made it look.**

**37 of the 57 were one term.** *Audit trail* was `audítny záznam` — "audit record" —
which forced *audit trail entry* to render `záznam audítneho záznamu`, "record of the
audit record", in 8 keys. Escalated (first-class entity noun, 30+ keys, no core
authority) and re-coined by the owner to **`auditná stopa`**, so *trail* is `stopa`,
*entry* is `záznam`, and the stutter dissolves by construction. The adjective was also
misspelled in all 35 keys carrying it: Slovak adds `-ný` to the stem with no vowel
change (`kredit → kreditný`, `limit → limitný`), and the long `í` of `audítny` belongs
to the separate lexeme `audítor` (Latin *audītor*, adjective `audítorský`). The bundle
already had the distinction — it writes `audit`, `auditu`, `auditovanie`, `auditujte`
short, matching core's `Auditovanie`, and `Audítori` long — and applied it correctly to
the noun and the verb while getting the adjective wrong. `Audítori` is preserved.

**A NEW DEFECT CLASS: an opposite-direction key pair carrying each other's meaning.**
`Uses` → `Používa sa` ("it is used") and `Used by` → `Používa` ("it uses") were
**inverted**. Both are `AppTab` titles, one over outgoing relations and one over
incoming. Neither value is ill-formed Slovak and neither is empty, identical or
wrong-arity, so nothing but reading the pair against its call sites finds it. Every
other locale marks the direction (`cs` `Použití` / `Používáno v`, `de` `Verwendungen` /
`Verwendet von`, `pl` `Używa` / `Używane przez`), which is what made `sk` visibly the
outlier. **Look for this wherever the English ships a converse pair** — Uses/Used by,
Parent/Child, Source/Target, Merged from/Merged into. Fixing `Uses` also resolved its
byte-identical collision with `In use`, whose `Používa sa` is correct as a card badge.

**`Delete`/`Remove` was escalated and deliberately LEFT.** Both render `Odstrániť`
across 122 values (88 Delete, 41 Remove). Core `sk` splits them — Delete → `Zmazať`(6)
or `Vymazať`(3), **never** `Odstrániť`; Remove → `Odstrániť`/`Odobrať` — and `Zmazať`
was the only free slot, because the bundle has already spent `Vymazať` on *Clear* and
on the GDPR *Erase*/`vymazanie` family, where it is the standard term for the Art 17
right and cannot move. The owner chose to leave it: both are valid Slovak, the
collision is soft, and §3.8 protects taste. `locales/sk.json` records it as a
**deliberate** divergence with the counts, so it is not re-litigated or mistaken for an
oversight. This is the second worked example of the escalation shape after `cs`'s
`Configuration`, and the first where the answer was "change nothing".

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

### `sr` capitalises its domain terms, and that is the convention most easily broken

Serbian is the `hr`/`sl` shape on register and buttons — formal 2pl prose, bare 2sg
imperative labels, split by string role — so the interesting part of the pass is elsewhere.
This bundle capitalises the **six first-class register concepts** mid-sentence, like proper
nouns, and lowercases everything else. Measured rather than assumed:

| Capitalised mid-sentence | Lowercase |
| --- | --- |
| `Шема` 33:1 · `Регистар` 30:0 · `Објекат` 62:0 · `Својство` 25:0 · `Датотека` 43:0 · `Извор` 5:1 | `приказ` 0:41 · `ентитет` 0:13 · `ток` 0:25 |

So `Обриши све Објекте у овој Шеми` but `Обриши приказ`, and `Управљајте вашим Шемама
података и њиховим Својствима`. It touches roughly a third of all values, no other locale
here does it, and **nothing automated checks it** — which makes it the single most likely
thing to get wrong at scale. Measure it before the first batch, the same way you measure
register: count capitalised-mid-sentence against lowercase per term. The two 1-of-N outliers
(`Додај извор`, `по овој шеми`) were normalised rather than left to explain later.

Register is a clean **formal**: 911 markers against **zero** informal over 4631 values in 32
catalogues, one-sided like `lt`, `sk` and `sl`. That zero is what made the two register
corrections unarguable rather than a judgement call — core never produces a 2sg present at
all, so `имаш` and `сачуваш` in the `Select …` family had no defence. The split point matters
though, and the bundle had already drawn it: instruction sentences take 2pl (`Изаберите које
Својство…`), bare labels and dropdown placeholders take 2sg (`Изабери све`, `Изабери грану`).
The corrections moved two long strings from the label side to the sentence side, which is
where `sl` puts the same strings.

**The trap that nearly produced a wrong correction.** A third value in that same family,
`Изабери модел или унеси прилагођени назив модела`, looks identical in kind and is
**correct**: `унеси` is a 2sg *imperative*, so it is the label convention, where its siblings
carried 2sg *presents*. Serbian spells the 2sg present with a `-ш` the 3sg drops (`сачуваш`
vs `сачува`), so the presents are safe to enumerate in a detector while the imperatives are
not — and that asymmetry is exactly what separates a style choice from an address slip here.
Recorded in `UNDETECTABLE` in `detectors/sr.js` rather than "fixed".

Terminology: `ревизорски траг` for audit trail (24 uses; the three `ревизијски` outliers were
normalised — both adjectives are formable Serbian, so this is consistency, not a fix),
`приказ` for view, `део` for chunk, `уграђивање` for embedding, `веб-кукица` for webhook,
`ток` for flow against `радни ток` for workflow, `корисни терет` for payload, `ентитет` for
entity, `контролна табла` for dashboard, `привремена меморија` for clipboard, `печат` for
seal, `поузданост` for confidence, `предмет` for a DSAR case, `субјекат података` for the
GDPR data subject, `Рок чувања` for `Bewaartermijn`, `активност обраде` for
`verwerkingsactiviteit`, `Одговорност` for `Verantwoording`. Initialisms are **not** handled
uniformly and that is deliberate pre-existing practice: `ID` → `ИД` and `Slug` → `Слаг` are
transliterated, while `URL`, `API`, `HTTP`, `LLM`, `PDF`, `CSV`, `RBAC`, `JSON` and `OAS`
stay in Latin and take a hyphenated Cyrillic case ending where they inflect (`URL-у`,
`OpenRegister-у`). Two coinages avoided collisions: **Revoke** → `Повуци` so that `Опозови`
stays free for **Reverse** (a merge), and **Bucket** → `Сегмент`, never "интервал", since
`Interval` is its own key.

### `bg` is the first locale where the imperative is detectable in *part* of the verb system

Every locale before this one was all-or-nothing about the bare 2sg imperative: `sk`
counts it, `ca`/`et`/`hr`/`sl` exclude it. Bulgarian splits by conjugation class, and
`detectors/bg.js` is the first detector to enumerate half a paradigm:

- **и-conjugation (`-я`/`-иш`) imperatives are unusable, for two reasons rather than
  one.** `запази` is simultaneously the 2sg imperative, the 3sg present (`може да
  запази`) and the 3sg **aorist** (`той запази`). The aorist collision is the novel
  part — no other locale's imperative homograph reaches into the *past* tense — and it
  is live in this bundle's own prose: `Анализът завърши:` and `Изтриването завърши` are
  aorists spelled exactly like the imperatives of `завърша`. Same for `провери`,
  `добави`, `отвори`, `потвърди`.
- **а-conjugation (`-ай`/`-вай`/`-й`) imperatives are unambiguous**, because the 3sg
  present of that class ends in `-а`/`-ва`: `опитай` vs `опитва`, `изпълнявай` vs
  `изпълнява`, `записвай` vs `записва`, `копирай` vs `копира`.

That split earned its keep immediately. The two informal values this bundle already
shipped — `Изпълнявай надзорни проверки преди всяка стъпка` and `Записвай одитен запис
за всяка стъпка`, both settings-toggle labels the English source phrases imperatively —
are **both** а-conjugation, so excluding the whole paradigm would have found neither.
Corrected to the verbal nouns `Изпълняване на…` and `Вписване на…`; `вписване` also
removes the `Записвай … запис` repetition that the direct fix would have kept.

One thing to say out loud about counting any imperative: the detector measures against
**this bundle's** label convention, not core's. Core `bg` uses `-й` imperatives as
labels in 29 places, so pointed at core it would report noise — which is also why the
core register scan's raw informal figure needs splitting before it is read at all (see
below).

### `bg` traps

1. **`-те` is the definite plural article** as well as the 2pl verb ending, and the
   article is the single commonest morpheme in the language. `файловете`, `обектите`,
   `потребителите`, `Членовете`, `настройките` are all nouns. A `-те` suffix rule scores
   essentially every plural noun phrase in the app as formal prose and the measurement
   becomes noise — a *louder* version of the same failure `hr`, `sk` and `sl` have.
2. **Bare `те` is the 3pl pronoun *they*,** not just the 2sg accusative clitic. This
   bundle says `Те могат да бъдат възстановени по-късно` — *of objects*. Left unmatched.
3. **Bare `си` is the reflexive possessive clitic**, commonest in formal prose
   (`за да прецизирате търсенето си`), as in `hr`/`sk`/`sl`.
4. **`-ш` inverts polarity**, the third locale in a row: `ваш` (your-FORMAL) and `наш`
   both end in it.
5. **`трябва` is not a person marker.** It is impersonal 3sg; in `Трябва да сте влезли`
   the register is carried by `сте`. Matching `трябва` would score impersonal
   requirement text as formal address.
6. **The useful negative: bare `ти` IS usable here.** Bulgarian lost its case system and
   its plural demonstrative is `тези`/`тия`, so `ти` has none of the demonstrative
   reading that makes it unusable in `cs`, `hr` and `sl`. Do not port that exclusion
   across the family by analogy — it costs real recall for nothing.
7. **`брой` is the noun *count* as well as an imperative**, and it is a noun in every
   place this bundle uses it (`Брой обекти за обработка`, `Максимален брой резултати`).

**Split the core informal count by what each hit is.** `bg` measured 699 formal markers
against 43 informal over 3451 values in 27 catalogues — but 29 of the 43 are core using a
2sg imperative as a *button label* (`Копирай`, `Актуализирай`, `Преименувай`, `Запиши`),
which is a label-style choice, not prose address. Only 11 values carry informal **prose**,
and they cluster in `core/bg.json` and `encryption/bg.json` (`Можеш да затвориш този
прозорец`, `старата ти парола`). So the prose ratio is 699:11. Unsplit, 43 reads like a
minority position worth weighing; split, it is a style choice plus eleven legacy strings.
Worth doing wherever the scan comes out close — `ro`'s MIXED 124 vs 66 is exactly that
shape.

**The count form is a plural hazard no gate can see.** `bg` has the simplest header in the
project (`nplurals=2; plural=(n != 1)`) and still needs care, because Bulgarian masculine
non-person nouns take a separate counting form (числителна форма, `-а`/`-я`) after a
numeral: `обект` → `обекти` as a bare plural but `обекта` after a number, likewise
`запис`/`записа`, `файл`/`файла`, `регистър`/`регистъра`, `имейл`/`имейла`. Which form a
plural array needs therefore depends on **whether the string contains a numeral**, not on
the form index — `_Delete {count} object_` needs `Изтриване на {count} обекта` while
`_Object successfully deleted_` needs `Обектите са изтрити успешно`. Masculine *person*
nouns keep the plural (`{count} членове`); feminine and neuter have no count form at all
(`схема` → `схеми` either way). The pre-existing `_%n entry has no hash yet_` array already
had `%n записа` and was the model to follow.

Terminology: `bg` translates domain prose fully but keeps Latin-script initialisms
unchanged rather than transliterating them into Cyrillic — `CSV`, `PDF`, `RBAC`, `URL`,
`ID`, `DSAR`, `JSON`, `OAS`, `Slug`, and `push` in `Push известия` (all pre-existing
practice). Coinages: `уебхук` for webhook, `полезен товар` for payload, `фрагмент` for
chunk, `вграждане` for embedding, `одитна следа` for audit trail, `изглед` for view,
`табло` for dashboard, `запечатване` for seal, `достоверност` for confidence, `меко
изтрит` for soft-deleted, `Срок на съхранение` for `Bewaartermijn`, `дейност по
обработване` for `verwerkingsactiviteit`, `Отчетност` for `Verantwoording`.

Three collisions the language forced, all resolved in favour of the pre-existing value:
**Connections** → `Свързвания`, because `Връзки` was already **Relations** (and the
`Links` entity type); **Subject** (the GDPR data subject) → `Субект на данните` spelled
out, because bare `субект` was already **entity** in twenty-odd keys — the `lt` fix of
coining a different word for *entity* was not available, since that value had shipped;
and **Bucket** → `Сегмент`, never "интервал", because `Interval` is its own key. One
collision was left standing deliberately: **Cancel** and **Denial** are both `Отказ`,
which is the right Bulgarian word for each (the dialog button, and the GDPR refusal of a
request — and what core `bg` uses for Cancel in ten catalogues). They never share a
screen, and forcing a distinction would have made one of them wrong.

Orthography: ellipsis follows the **key's own** punctuation (`...` where the source has
`...`, ` …` where it has ` …`), ranges take a plain hyphen matching the source, long prose
takes an em dash with spaces, and the dative clitic is written `ѝ` with the grave accent —
never `и`. That last one is a marker of careful Bulgarian and the pre-existing bundle
already got it right (`може вече да не ѝ съответстват`).

### `rm` is the first locale with no core evidence at all

Romansh is where the standard procedure runs out of inputs. Nextcloud ships **zero `rm`
catalogues** — none in `core/l10n`, none in `lib/l10n`, none in any bundled app — so
`coreCatalogues('rm')` throws by design and §5 step 2 cannot be run as written. The
§6.4 fallback applies and the verdict comes from the bundle's own pre-existing half:
**81 formal markers against 0 informal across 995 translated values.** That is
one-sided enough to settle it without core.

Check this before assuming a locale is measurable. Four of the nine low-resource
locales cannot be decided from core: `rm` and `mt` have **no catalogues**, and `bs` and
`lb` have one each carrying 55 and 72 values, which is not evidence of anything.

Romansh has a real T–V distinction, so unlike Russian the polite pronoun **is** a
register marker: `Vus` / `voss` (the German `Sie` model) against `ti` / `tiu`. Buttons
are **infinitives** — `Memorisar`, `Stizzar`, `Annullar`, `Modifitgar`, `Crear`,
`Tscherner` — the register-neutral `cs`/`lt`/`lv`/`sk` pattern, which also defuses the
dual-role `Create`/`Read`/`Update`/`Delete` keys that forced `ro` onto verbal nouns.
Progressive states are the bare infinitive plus an ellipsis (`Analyzing...` →
`Analisar...`).

**Two whole paradigms are undetectable, and `rm` is the exact mirror of `sk`.** Both
languages label buttons with the infinitive, so §6.5 test 1 comes out the same for
both; they diverge on test 2:

- the **2sg imperative** of every `-ar` verb is spelled identically to the 3sg present
  *and* the feminine singular past participle. `stizza` is "delete!", "it deletes" and
  "deleted-f.sg" at once, and the 3sg reading is live in this bundle's own prose
  (`Quai stizza las endataziuns`, `Ferma mintga flux`, `Elavurescha ils chunks`). Where
  Slovak has `ulož` ≠ `uloží`, Romansh has `stizza` = `stizza`. Same button convention,
  opposite detector decision — which is why §6.5 insists both tests be run per locale.
  Unlike `bg` there is no conjugation class to carve out: every `-ar` and `-escha` verb
  collides.
- the **2sg present of regular verbs** ends in `-as`, which is also the feminine plural
  of every noun and adjective — the commonest inflection in the language. `controllas`
  is "you check" *and* the noun "checks" (`Controllas da surveglianza` is a real value);
  `empruvas` is "you try" *and* "attempts" (`Max empruvas`); `tschernas` is "you choose"
  *and* the plural of the noun `tscherna`. Detection therefore rests on the ten
  irregular verbs, whose 2sg ends in a bare `-s`: `has`, `es`, `pos`, `stos`, `vuls`,
  `sas`, `vas`, `fas`, `das`, `vegns`.

The useful **negative**: bare `ti` **is** usable, unlike `cs` `ty`, `hr`/`sl` `ti` and
`sr` `ти`, because Romansh demonstratives are `quel`/`quest`, so there is no
demonstrative reading to collide with. Same result as `bg`, and again reached from the
data rather than from the family.

### `rm` traps

Suffix rules fail in both directions here, which is why the lists are closed:

- **`-ai`** looks like the polite 2pl imperative, and mostly is — but `quai`
  ("this/that") ends in it and occurs **23 times**, the single most common word such a
  rule would hit, along with `perquai` ("therefore"), `mai` ("never"), bare `ai`
  (a + ils) and `hai`/`sai` (1sg "I have"/"I know").
- **`-ais`** looks like the 2pl present, and mostly is — but `mais` is "months"
  (`Mintga mais`) and the nationality adjectives `ollandais`/`englais`/`franzais` end
  in it.
- **`-as`**: see above. Fatal in the other direction.

**Diacritics must not be folded.** `tscherni` is the 2pl imperative ("choose!", a
formal marker) while `tschernì` is the past participle "selected" — this bundle's value
for both `Selected` and `register(s) selected`. They differ by the grave accent and
nothing else, so folding would score every `Tschernì` label as polite address. Same for
`e` ("and") against `è` ("is"). `fold()` only lowercases.

Capitalisation is the `sr`-shaped convention with a **different set**, which is the
point: the practice is per-locale and so is the list. `rm` capitalises exactly three
domain terms mid-sentence — `Schema` 34:1, `Register` 30:0, `Datoteca` 43:0 — and
lowercases every other one, including `object` at 0:63, plus `vista`, `webhook`,
`colliaziun`, `tschertga`, `endataziun`, `caracteristica`, `utilisader`, `chunk`,
`flux`, `entitad`, `gruppa`, `funtauna`, `roll`, `token`. Both rules apply inside one
value: `naginas relaziuns cun objects u Datotecas`. The polite pronoun and possessive
are **always** capitalised mid-sentence (`Vus` 13:0, `Voss*` 21:0), on the German
`Sie`/`Ihr` model.

Terms, all taken from the bundle rather than coined: audit trail → `colliaziun
d'audit` (literally "audit link", a loose rendering of *trail* but the file's own
consistent term in 25 values — three `tratga da revisiun` outliers were normalised to
it), view → `vista`, property → `caracteristica`, source → `funtauna`, file →
`Datoteca`, field → `chomp`, workflow → `process da lavur`, flow → `flux`, log →
`protocol`, clipboard → `archivet provisoric`, dashboard → `panel`, owner →
`possessur`, password → `pled-clav`, lock → `serradira`, `Bewaartermijn` → `Temp da
conservaziun`, `Rechtsgrond` → `Basa giuridica`, `Verantwoording` → `Rendaquint`,
`AVG / Verwerkingsregister` → `RGPD / Register da las activitads da tractament`,
**Subject** (the GDPR data subject) → `Persuna pertutgada`, **Golden record** →
`Endataziun da referenza`, **Bucket** → `Segment` (never "interval", because
`Interval` is its own key). Technical terms are borrowed throughout — webhook, chunk,
embedding, payload, token, hash, batch, trigger, backend, endpoint, `Slug`, `Branch` —
which is why `Slug` is a recorded cognate rather than a coinage even though core `lt`
and `sl` translate it.

Three near-synonyms are kept deliberately apart, the §8.5 pattern: the pre-existing
`revocar` is **undo** (`na po betg vegnir revocada`, 5 values), `Inversar` is
**reversing a merge** (`ro` uses `Inversare` likewise; `Annullar` was unavailable
because it is already **Cancel**), and `Revocar` is **revoking a token**, which is what
`ca`, `es`, `fr`, `it` and `ro` all use. The last two share a stem — an unavoidable
overlap recorded rather than worked around, since a token row action and a
delete-confirmation dialog never share a screen.

One trap worth stating because it would have been silent: **`Handler` is a person.**
Every locale renders it `Responsable`/`Gestor`/`Bearbeiter`, and the call site confirms
it — a `<th>` in the DSAR cases table beside `Type`, `Status`, `Deadline`, with
`handlerFilterOptions` mapping people's names. It is not an event handler. `rm` →
`Respunsabel`.

Twelve pre-existing defects were corrected, spanning six classes: three
`tratga da revisiun` terminology outliers and three `endataziun da revisiun` ones;
`siwa` for *follows* (**the only non-loanword `w` in the bundle**, and `w` is not a
letter of Romansh — corrected to `suonda`); `d'spetga`, the only `d'` elided before a
consonant; `lescha` where the 3sg of *leger* is `leja` (`lescha` is the noun "law");
`endataziuns sigillads` with a masculine participle on a feminine noun; one lowercase
`quest schema`; and a `…` ellipsis with a missing article against 20 consistent
siblings. A thirteenth was the plural array above.

### `ga` is the first locale with no T-V distinction at all

Every locale before this one had a register *choice* to measure. Irish does not.
`sibh` is strictly the second-person **plural** in modern standard Irish — it is not a
polite singular the way German `Sie`, Romansh `Vus` or Croatian `Vi` are — so there is
exactly one way to address a user, and it is `tú`.

The measurement is more one-sided than any other locale in the set, and in a different
way: **434 second-person singular markers against 0 plural** across core's 33 `ga`
catalogues (5395 values). Not one occurrence of `sibh`, `sibhse`, `bhur`, `agaibh`,
`daoibh`, `libh`, `oraibh`, `uaibh`, `chugaibh`, `díbh`, or an `-aigí`/`-igí` 2pl
imperative — confirmed by raw grep as well as by the detector, and the bundle's own
1036 pre-existing values agree (15 singular, 0 plural).

`locales/ga.json` records `"register": "informal"`, and that needs reading correctly:
it does **not** mean Irish core preferred the familiar register over a polite one. It
sets the gate's polarity so `patchcheck` refuses second-person *plural* address. That
is the one register defect this locale can have, and it is a live risk rather than a
theoretical one — a translator working down a list of European locales imports the
politeness plural by analogy from de/fr/nl and writes `An bhfuil sibh cinnte…` for a
single-user dialog. Nothing else in the project can see that.

The corollary is that **a low informal count is not evidence of a problem here.** Most
of this bundle is written with the autonomous/impersonal verb (`Scriosadh an rian
iniúchta`, `Níor aimsíodh aon chláir`), which addresses nobody and scores zero in both
directions. The load-bearing figure is that the *formal* count is zero.

### `ga` traps

**The 2sg imperative fails both §6.5 tests at once**, so it is excluded — the first
locale where the two tests agree rather than pulling apart. It is the label convention
(core: `Sábháil`, `Scrios`, `Cealaigh`, `Cruthaigh`, `Deimhnigh`, `Cóipeáil`,
`Roghnaigh`, `Bain`, `Dún`, `Bog`, `Athnuaigh`, `Athchóirigh`), **and** for the whole
`-áil` class it is spelled identically to the verbal noun, which is live in this
bundle's prose as the progressive: `Ag sábháil...`, `Ag cóipeáil sonraí...`,
`Ag tástáil...`, `Ag próiseáil...` are all real values whose second word is the
imperative form. Several stems are ordinary nouns besides — `Scrios` is also
"destruction", `Dún` also "a fort".

**`do` is the single largest recall loss in any detector here, and it is unavoidable.**
It is at once the 2sg possessive "your", the preposition "to/for", the past-tense verbal
particle, and half of `le do thoil` ("please"). It occurs in **527 of 6431** corpus
values, overwhelmingly not as a possessive, so it is excluded wholesale — which means
`D'eochair API OpenAI` ("your OpenAI API key") and `do chuardach` ("your search") carry
no detectable marker. Note the polarity: the loss is on the *correct*-register side, so
it thins the evidence without ever producing a false formal hit.

**`-ibh` must never be a suffix rule.** `díbh` is "off you (pl)" and `díobh` is "off
them" — one letter apart, and it is the **third**-person one that occurs in this corpus,
twice, both times genuinely "of them" (`gach ball díobh seo`, `gach ceann díobh a
shárú`). Same for `-igí`: it is the 2pl imperative ending and also the plural of every
noun in `-ig` (`oifig` → `oifigí`). No such noun happens to occur in the 6431-value
corpus, which is precisely why a suffix rule would have looked safe and shipped.

**Casing has no independent convention here — it mirrors the English source.** This is a
third outcome for the §8.10 trap, distinct from `sr` (six terms capitalised
mid-sentence) and `rm` (three). Measured over 14 domain terms: where the English key is
title-cased the `ga` value capitalises **76 times against 1**; where the English key is
prose it capitalises **0 times against 193**. The lone apparent exception is not one —
`Update register OAS: ...` → `Nuashonraigh OAS cláir: ...` has lowercase `register` in
the English too. So follow the source per key. This also explains why a naive per-term
count reads MIXED for every single term (`clár` 3:5, `scéimre` 9:21, `réad` 11:19,
`comhad` 6:11, `amharc` 3:22) and would have looked like "no convention" — the
convention is real, it is just conditioned on the source rather than on the term.

Ellipsis mirrors the source glyph too: 41 of 42 keys carrying `...` keep `...`, and 2 of
2 carrying `…` keep `…`. The bundle has 8 em dashes, **no** en dash, no non-breaking
space, no guillemets or curly quotes, and `%` never appears outside a placeholder.

Terminology, fixed by the pre-existing half and kept: Register `clár`/`cláir`, Schema
`scéimre`/`scéimrí`, Object `réad`/`réada` (gen sg `réid`), Property `airí` (gen pl
`airíonna`), File `comhad`/`comhaid`, View `amharc`/`amhairc`, Audit trail
`rian iniúchta`, Flow `sruth`, Workflow `sreabhadh oibre`, Entity `aonán`, Chunk
`smután`, Embedding `leabú`/`leabuithe`, Endpoint `críochphointe`, Token `comhartha`,
Hash `hais`, Chain `slabhra`, Seal `séala`, Dashboard `deais`, Repository `stórlann`,
soft delete `boigscriosadh`. Four deliberate divergences from core: **Webhook** stays the
English loan (28 pre-existing uses) where core's `webhook_listeners` has
`Crúcaí gréasáin` — the file wins on lexicon, as `hr` kept `lozinka`; **Flow** is `sruth`
and Workflow `sreabhadh oibre`, where core's `Sreabhadh` for Flow would collapse two
concepts the app distinguishes; **Update** is the imperative `Nuashonraigh`, not core's
verbal noun `Nuashonrú`; and **Revoke** is `Cúlghair`, not core's `Chúlghairm`, which
carries a stray lenition on a citation form and is a typo of the kind to leave alone
(cf. core `tr`'s `Yenlle`).

Two §8.4 wrong-sense traps resolved at the call site rather than from the harvest:
**Bucket** is a histogram bin in `QualityIndex.vue` (a `<th>` beside `Count`), so
`Banda` — never core's `Buicéad`, a literal pail — kept distinct from `Interval`
`Eatramh` and `Range` `Raon`; and **Labels** is the file-tag column in
`UploadFiles.vue`, so `Clibeanna`, while the singular **Label** is a facet range caption
in `EditSchemaProperty.vue` and takes `Lipéad`. That is the split §8.4 predicts for that
pair, and here it is real. `Right` is the RBAC `<th>` in `EditOrganisation.vue`'s
`special-rights-table`, so `Ceart`, not `Deas` (right-hand side). `Apply` is
`Cuir i bhFeidhm`, not core's `Cuir iarratas isteach`, which is a *job* application.

One pre-existing defect was corrected: `Loading organisations...` →
`Eagraíochtaí á luchtú…` was the only one of the bundle's 35 `Loading X...` values not
built as `Ag <verbal noun> <object>...` (the other 34 are), and it also carried `…`
against the source's `...`. Normalised to `Ag luchtú eagraíochtaí...`.

### `mt` has a politeness system and simply does not use it

`ga` came out 2sg-only because Irish has no T-V distinction at all. `mt` came out 2sg-only
for a completely different reason, and the two sitting next to each other is exactly the
trap: **Maltese does have the politeness options.** `intom` serves as a polite singular the
way French `vous` does, and `Is-Sinjur` / `Is-Sinjura` with third-person agreement is the
deferential register. Both are available; both are measured absent. So `mt`'s `informal` is
an ordinary measured choice like `nl`'s, and its gate catches genuine deference rather than
an impossible form. Two consecutive locales measuring the same way is not evidence they are
the same case.

Core ships **zero** `mt` catalogues, so this is the §6.4 fallback — the second locale after
`rm` with no core evidence at all. The fallback was widened to the sibling apps' **frontend**
`mt.js` files, which tripled the corpus from 1015 values to 3422 and turned a thin 26-vs-0
into **128 vs 0**. Two constraints made that sound: only `.js` bundles, because the backend
`.json` is a separate catalogue with a separate consumer — openregister's own `mt.json`
contains an `int` that would otherwise have been miscredited to the frontend — and the
sibling scan excludes byte-identical mislabelled catalogues the way `harvest.js` does.

The markers split as 75 `tiegħek`, **35 `jekk jogħġbok`**, 15 `int`/`inti` and 4
prepositional pronouns. That middle figure is the transferable finding: Maltese's politeness
formula carries a 2sg **object suffix**, which makes it a real address marker rather than a
courtesy word, and at 35 uses it was the second commonest marker in the bundle. The first
draft of the detector omitted it and a must-fire control caught the omission. Any locale
whose "please" inflects for the addressee has the same free signal — `ga`'s `le do thoil`
does not, so check rather than assume.

One method note worth keeping: the first probe run scored **zero** for the pronoun and would
have gone into the record that way. It omitted the `/i` flag, and all three of the bundle's
`Inti ċert li trid…` values are sentence-initial. A measurement that comes out at exactly
zero deserves a second look before it is written down.

### `mt` traps

**Two whole paradigms are undetectable**, both ordinary Maltese morphology, and between them
they cost most of the theoretical recall:

- **The `t-` prefix.** The 2sg imperfect and the 3sg **feminine** imperfect are spelled
  identically across the entire verb system, so `tista'` is at once "you can" and "she/it
  can" — and both readings are live here: `Hawn tista' tara jekk dik il-katina hijiex sħiħa`
  (2sg) against `Il-Proprjetà tista' tittejjeb`, `Din l-analiżi tista' tieħu ftit ħin` and
  `Qabel ma tista' taħdem il-vettorizzazzjoni` (3sg f). 23 occurrences of `tista'` split both
  ways, plus 24 of `trid` that are mostly genuine 2sg and still cannot be counted. This is
  the Latvian shape — systematic, not exceptional.
- **The `-u` ending.** The 2pl imperative and 2pl present both end in `-u`, and so does the
  3pl of everything. `nstabu` ("they were found") occurs 24 times in `Ma nstabu l-ebda X`,
  `għandhom` 19 times, plus `jistgħu` and `jappartjenu`. A `-u` rule would score the
  commonest sentence shape in the file as deference, so 2pl imperatives are excluded and the
  formal side rests entirely on the pronoun, the possessive, the `-kom` prepositional
  pronouns and `Sinjur` — narrow, but unambiguous.

Note the polarity of both losses: they fall on the **correct**-register side, so they thin
the evidence without ever producing a false formal hit. And because most of this bundle is
written with impersonal or passive verbs (`It-traċċa tal-awditjar tħassret`, `Ma nstabu
l-ebda Reġistri`), which address nobody, a low informal count is not evidence of a problem
here. The load-bearing figure is that the formal count is zero.

Also: `tagħhom` (3pl "their") is one paradigm slot from `tagħkom` (2pl "your"), and it is
the 3pl that occurs — `mar-ringieli tagħhom`, `il-konfigurazzjonijiet tagħhom`. Match only
`tagħkom`. And `à è ì ò ù` **are** Maltese, marking stress on Romance-derived nouns
(`attività`, `kwalità`, `entità`, `Proprjetà`) — 92 legitimate uses, so a foreign-diacritic
sweep that flags them is measuring its own list, not the data.

**This bundle keeps English IT loans**, consistently, and that is the house style rather
than laziness: Logs, Repository, Path, Branch, Email, Format, Headers, Password, Username,
Timestamp, Status, Settings, Total, Serial, Parallel, Port, Slug, Webhook, Dashboard,
Endpoint, token, triggers, payload, timeout, clipboard, hash, embedding, soft delete. The
siblings disagree on two and **the file wins** (§3.5): they render Repository as
`Repożitorju` and Logs as `Reġistri`, but this bundle writes `Repository` and `Path
fir-repository`, and keeps `Logs` in four independent places. `Reġistri` is unavailable for
Logs in any case — it is already this bundle's plural of Register, which is the §8.5
collision pattern showing up in a loan decision.

Terminology: Register `Reġistru`/`Reġistri`, Schema `Skema`/`Skemi`, Object
`Oġġett`/`Oġġetti`, Property `Proprjetà`/`Proprjetajiet`, File `Fajl`/`Fajls`, View
`Veduta`/`Vedute`, Source `Sors`/`Sorsi`, Flow `Fluss`/`Flussi`, Entity
`Entità`/`Entitajiet`, Chunk `Biċċa`/`Biċċiet`, Audit trail `Traċċa/Traċċi tal-Awditjar`,
Chain `Katina`, Seal `Siġill`, Count `Għadd`, Field `Kamp`, Error `Żball`, Owner `Sid`.
Phrase patterns: `No X found` → `Ma nstabu l-ebda X`, `Failed to X` → `Naqas milli X`,
progressives → `Qed jitilgħu l-X...`, `Please X` → `Jekk jogħġbok X`.

Domain-term capitalisation is a **partial, per-term list** — the `sr`/`rm` shape, not `ga`'s
mirror-the-source. Four families are capitalised mid-sentence (`Oġġett` 56:2, `Reġistru`
55:0, `Proprjetà` 21:0, `Fajl`/`Fajls` 71:0) and the rest are lowercase (`skema` 4:17,
`iskema` 6:49, `veduta` 3:26, `entità` 2:7, `biċċiet` 3:14, `utent` 4:14). The asymmetry to
notice is that `Reġistru` is capitalised while `skema` is not, though they are the app's two
paired core concepts — so the list cannot be inferred from what a term means, even within
one locale. **This pass broke that convention and the check caught it:** measuring the
pre-existing half separately from the newly written half showed HEAD capitalising
`Fajl`/`Fajls` 43:0 while the new values had lowercased it 24:4, and 21 values were
normalised. Whole-bundle counts read "CAPITALISED" either way, because the pre-existing
majority outvoted the drift — split the corpus at `HEAD` and compare the two columns.

**Nine pre-existing defects were corrected, in three classes.** Six were the same one:
values whose English key says AUDIT but whose Maltese said `verifika` (verification) — a
different concept in this app that appears on the same screen (`Verifika tal-katina`,
`Ivverifika l-katina`). 24 values use `awditjar` for audit against those 6, so the 6 were
normalised. A seventh, `Audit trail #{id}`, had been left as untranslated English (`Audit
Trail #{id}`, merely re-cased) — the same key that was the outlier in `sr`, where it was
wrong Croatian in the wrong alphabet. An eighth, `Log integrity`, read `Integrità
tar-reġistru`, i.e. "integrity of the REGISTER", on a screen that lists Reġistri. And
`Loading organisations...` was the 1-of-19 outlier on both its verb and its ellipsis glyph —
the same key that was the 1-of-35 outlier in `ga`, on the same two counts.

One further inconsistency was **recorded rather than changed**: the bundle renders
`entry/entries` as `entrata/entrati` 19 times and `annotazzjoni/annotazzjonijiet` 18 times
for the same rows. It splits by screen rather than randomly, `entrata` is the accurate word
but `annotazzjoni` may well be idiomatic for a log row in Maltese IT, and normalising 18
values is a larger intervention than the pass warranted (§6.9). New keys follow the adjacent
existing value on their own screen, so no screen was made self-inconsistent.

### `is` had a T-V distinction and abandoned it

Icelandic is the third state of the three (see "Register is measured, never inherited"
above): it once had `þérun` — nominative `þér` with a 2pl verb, possessive `yðar` — and
dropped it during the 20th century. The forms survive in legal, liturgical and deliberately
archaic register, so unlike Irish 2pl-as-polite they are not *impossible*; they are merely
obsolete. `Hafið þér aðgang?` in a 2026 admin UI reads as parody.

Measured: **626 informal (2sg) markers against zero formal**, over core's 28 `is` catalogues
(3610 values), with the bundle's own 1054 pre-existing values adding zero formal. Because
that is a zero, it was re-checked by raw grep over the 28 catalogues rather than trusted from
the detector alone — `yður`, `yðar`, `yðvar`, `yðr`, `þið`, `ykkur`, `ykkar`, `þéra` and
`þérun` are all literally absent, nine tokens at zero occurrences.

`detectors/is.js` therefore gates on **two different defects** under one polarity, and they
are worth keeping apart when you read a hit:

1. **archaic deference** — `yður`/`yðar`, or nominative `þér` with a 2pl verb;
2. **plain wrong number** — `þið`/`ykkur`/`ykkar`, the ordinary modern 2nd person plural,
   entirely correct Icelandic for several addressees and simply wrong for one user.

The second is the likelier slip, because it is the plural that is actually current in the
language; reaching for `yðar` would take a knowledge of Icelandic philology.

**Buttons are infinitives** — `Vista`, `Eyða`, `Breyta`, `Afrita`, `Staðfesta`, `Virkja`,
`Endurstilla`, `Endurheimta`, `Skoða`, `Loka`, `Búa til`, `Bæta við`, `Hætta við` — 29 of 35
distinct core values, zero verbal nouns, and exactly one enclitic imperative (`Choose` =
`Veldu`, against `Select` = `Velja`, so an outlier not a pattern). The bundle's own 21 action
keys agree unanimously. `is` therefore joins `cs`/`lt`/`lv`/`sk`/`rm` on the register-neutral
infinitive, and that is what makes the imperative countable as a marker here (§6.5 test 1 is
the only NO in the set so far).

### `is` traps

- **`þér` is both the 2sg dative and the archaic polite nominative**, and the dative is what
  actually occurs — all 54 core hits (`þér er ekki heimilt`, `gefur þér`, `Beini þér til`).
  Calling it formal would misclassify ordinary informal prose. The polite reading is
  recovered by a **bigram**: `þér` plus a finite 2pl verb, matched in both orders since a
  question inverts it. Neither token is decidable alone; the pair is. This is the reusable
  idea from the pass.
- **`-ið` is the neuter definite article** as well as the 2pl verb ending, so there is no
  suffix rule at all — `lykilorðið`, `tölvupóstfangið`, `skjalið`, `safnið`, `nafnið` are
  nouns. Worse, five individual 2pl forms are themselves homographs of common words: `hafið`
  is "the ocean" *and* the past participle of `hefja` ("hefur hafið ferli" — "has begun a
  process"), `getið` is "mentioned", `verðið` is "the price", `vitið` is "the wit", `eigið`
  is the neuter adjective "own" ("þitt eigið Nextcloud"). Two of the five occur in core in
  the non-verb reading; none occurs as a 2pl verb.
- **The enclitic imperative splits by conjugation class.** Class 1 is safe (`notaðu` vs 3pl
  past `notuðu`), class 2 is not (`settu` is both "enter!" and "they put"). The collision is
  attested, not hypothetical: `komu` occurs 4× as a 3pl past and `völdu` twice as the weak
  adjective *selected*, never as imperatives.
- **`vinsamlegast` ("please") carries no address marker** — it is an adverb and inflects for
  nothing, so unlike Maltese `jekk jogħġbok` there is no free signal here.
- **`skrá` means both *file* and *register*.** The single biggest issue in the bundle, and
  the reason for its large correction set. Four pairs of distinct keys rendered identically
  and one value was unreadable (`No register objects reference this file` → `Engin
  skrárhlutar vísa í þessa skrá`). Core locks `skrá` = file, so *register* moved — to
  `gagnaskrá`, which the bundle already used in one place. The owner decided this, since it
  is the app's primary noun and touched ~76 keys.
- **`sía` ("filter") is feminine, and the bundle had it masculine** in 21 values: `Virkir
  síar`, `Ítarlegir síar`, `Engir virkir síar`, `Hreinsa alla síar`. Correct is `Virkar
  síur`, and core `is` contains that exact phrase, plus `Filters` → `Síur`; core's only
  `sí-` forms anywhere are `síur` and `síu`, never `síar`. Both the noun and every adjective
  were corrected. The 5 pre-existing dative-plural `síum` values were already right.
- **`Slug` collided with `ID`** (both `Auðkenni`, both field labels on the same
  `EditSchemaProperty` screen) → `Stuttheiti`. **`Refresh` collided with `Update`** (both
  `Uppfæra`) → `Endurnýja`, core's own word. **`Configurations` and `Settings` both stay
  `Stillingar`** — correct for each, and the unavoidable-collision case.
- **No domain-term capitalisation whatsoever**, and unlike `ga` this is *not* mirroring: it
  holds regardless of the English key's casing (`skema` 0:9 under title-cased keys, 0:14
  under prose). A flat lowercase rule.
- **`skema` is treated as indeclinable** — 25 bare forms at HEAD against one `skemanu`. This
  pass initially introduced `skemað`/`skemans` and they were normalised back; see the
  declension note in the runbook's §8.10.
- Typography: `...` over `…` (41:4 at HEAD) and **matching the English source's glyph per
  key** is the rule — 44 of 45 already did, and the one exception was `Loading
  organisations...`, which was *also* the only `Loading` value not using the `Hleð
  <accusative>...` pattern. That same key was the outlier in `ga` and `mt` too, on the same
  two counts. Em dash `—`, never en dash. No percentage values and no quote glyphs exist in
  the bundle, so source quotes are mirrored rather than converted to `„ “`.
- **The pre-existing half was badly defective, and the audit is the story of this pass.**
  291 of the 1052 pre-existing values were corrected in total — 63 in the first round and
  228 more in the audit. The largest
  classes: 41 acronym/product-name compounds missing the hyphen Icelandic requires
  (`API lykill` → `API-lykill`), 33 malformed compounds (`Hámarks framkvæmdatími` →
  `Hámarksframkvæmdartími`, `Gagnagrunnupplýsingar` → `Gagnagrunnsupplýsingar`,
  `Yfirlitvilla` → `Yfirlitsvilla`), 23 wrong senses, 20 keys using `stofnun` (institution)
  where the bundle's word for *organisation* is `skipulagsheild`, 19 wrong cases, 13
  singular-for-plural, 11 keys using `skráning` where the bundle's word for *log* is
  `annáll`, and 11 agreement errors. Notable individual finds: **`Stav`** — Slavic for
  "status", a straight wrong-language contamination of the kind §6.6 warns about but *inside*
  the committed bundle; **`skrivaðgang`** with a Danish/Norwegian stem for `skrifaðgang`;
  **`fersla`** ×5, which is not an Icelandic word at all (it is `færsla`) and which I
  propagated into my own values before catching it; **`Búningaaðgerðir`** for *Create
  Operations*, where `búningur` means a costume; and `levranir`, `búsum`, `tökmörkun`,
  `kerfisssstillingum`, `Misheppnaðst`, `Endurtaki`, `Grunvefslóð`, `Mynsturnvandamál`, all
  simply not words.
- Terms: object `hlutur`, schema `skema` (pl `skemu`), property `eiginleiki`, view
  `yfirlit`, entity `eining`, source `uppspretta`, chunk `bútur`, audit trail
  `endurskoðunarferlatal`, webhook `vefkrókur`, dashboard `mælaborð`, organisation
  `skipulagsheild`, token `teikn`, flow `flæði` but workflow `vinnuflæði`. Note `Overview`
  had to become `Yfirsýn` rather than core's `Yfirlit`, because `yfirlit` is this app's word
  for **View**.

### `cs` is the first of the sixteen pre-rule locales to be re-audited, and it came out healthy

The sixteen locales finished before any of this tooling existed (`cs da de el es fi fr hu it
nb nl pl pt ru sv uk`) are *doubly* un-audited: their identical values were never cognate-
reviewed **and** their translations never had a register measurement, a detector, an
orthography sweep or a grammar pass. The expectation going in — set by `is`, where 22% of the
pre-existing half was defective — was that these would be as bad or worse. **They are not, at
least not the mature ones.** `cs` came in at 113 defects across all 2052 values (5.5%), with
**zero** garbled words, **zero** foreign stems, **zero** agreement failures and **zero** wrong
plural arrays. Czech is an actively maintained locale with a real translator community.

What that means for the remaining fifteen: budget the pass for **terminology counting**, not
grammar repair. Counting competing renderings per English term produced about 70 of the 113
`cs` corrections and needs no knowledge of the language at all.

### `cs` traps

- **Every Czech 2sg imperative is a proper prefix of its 2pl counterpart**, because the 2pl is
  the 2sg plus `-te`: `vyber`/`vyberte`, `zadej`/`zadejte`, `nastav`/`nastavte`,
  `zvol`/`zvolte`. This bundle holds 64 `vyberte`. Without the trailing `(?!\p{L})` guard the
  detector scores the commonest **formal** shape in the corpus as informal and inverts the
  verdict outright. Several stems are also prefixes of the app's own nouns —
  `nastav`⊂`nastavení`, `zobraz`⊂`zobrazení`, `ulož`⊂`uložené`. The informal possessive `tvá`
  is likewise a substring of `vytvářet`/`vytváření` ("to create"), which a raw scan finds 48
  times, every one inside that verb.
- **Bare `ty` and `ti` are unmatched** (§8.2) — both are the plural demonstrative as well as
  the pronoun, and Czech has no diacritic to split them the way Slovak's `ti`/`tí` does.
  Measured cost of the exclusion: `ty` occurs 6 times in the corpus, all demonstrative, and
  `ti` zero times.
- **Bare `si` carries no register information at all**, which is a *different* situation from
  `hr`/`sk`/`sl`. There, `si` is the 2sg of "to be" as well as the reflexive clitic, so it is
  ambiguous. Czech's 2sg of `být` is `jsi`, so `si` is only ever reflexive — empty rather than
  ambiguous. `jsi` itself is unambiguous and is matched.
- **`prosím` ("please") gives no free signal.** It is a 1sg present verb, so it inflects for
  the speaker, not the addressee — unlike Maltese `jekk jogħġbok`. In `Počkejte prosím` the
  register is carried by `počkejte` alone.
- **Core overturned four candidate corrections**, and this is the trap worth internalising:
  a majority inside the bundle is not authority on its own. `Loading…` → `Načítání…` looks
  like the outlier against 37 sibling `Načítá se` values and is **core's own form**, so the
  one value I was about to "fix" was the only one matching core. `Current password` →
  `Dosavadní heslo` looked like a wrong sense and is core verbatim. `Bucket` is untranslated
  in core and there means an S3 bucket, so core is no authority for this app's histogram-bin
  sense and the value was left alone.
- **Check the call site before calling a form wrong.** `folder` → `složky` looks like a
  genitive where a nominative belongs, and is correct: the key is a button in the middle of a
  split sentence whose preceding fragment ends `přejděte do`, which governs the genitive.
- **The Configuration/Settings split was the owner's call** and is the largest correction set.
  The bundle rendered the app's `Configuration` entity `nastavení` in 33 values and
  `konfigurace` in 31, and the split ran *through* individual features: the Configurations
  screen was titled `Konfigurace` with buttons reading `Nové nastavení`/`Upravit nastavení`,
  and `Failed to save configuration` was byte-identical to `Failed to save settings`. Core
  `cs` renders the generic heading `Configuration` → `Nastavení`, pointing the other way, so
  the decision went to the owner: **`konfigurace` for the noun, `nastavení` for Settings**,
  knowingly diverging from core because core has no Configuration *entity*. Verbs were not
  touched — `Configure X` → `Nastavte X` cannot collide with either noun.
- Genuine grammatical errors were few but real: `No register objects reference this file` had
  lost the `na` that `odkazovat` governs; `Queue / sync health` → `Stav frontu` used the
  genitive of the masculine `front` (a front line) where a queue is the feminine `fronta`,
  genitive `fronty`; `událost, kterou naslouchat` put an accusative on `naslouchat`, which
  governs the dative, with no modal verb; and `Search GitHub` → `Hledat na GitHub` dropped the
  locative the bundle gets right elsewhere in `na GitHubu`.
- Wrong senses: `Commit message` → `Zpráva potvrzení` read *commit* as *confirm* in a bundle
  that is unambiguously about Git (`Branch` → `Větev`, `Repository` → `Repozitář`); `Complex`
  → `Komplexní` is a false friend (Czech `komplexní` means comprehensive, not complicated —
  the bundle's own `Complex queries` → `Složité dotazy` had it right); `Uses` → `Používá` used
  a 3sg verb for an `AppTab` title.
- Terms: object `objekt`, schema `schéma` (pl `schémata`), register `registr`, property
  `vlastnost`, view `pohled`, log `protokol`, audit **trail** `auditní záznam` but audit
  **entry** `záznam auditu` (a distinction the bundle already drew and worth keeping),
  chunk `úsek`, embedding `vnoření`, facet `faseta`, flow `tok`, workflow `pracovní postup`,
  dashboard `nástěnka`, payload `datová část`, handler `řešitel`, hash `otisk`. Refresh was
  decided by core: `Refresh` and `Restore` both rendered `Obnovit`, and core distinguishes
  them exactly — `Znovu načíst` vs `Obnovit`.
- **`nplurals=3` with ABSOLUTE boundaries** (`1` / `2–4` / else incl. 0), agreeing with the
  library, so no boundary or ordering note is needed. All 7 plural arrays were already
  correct, including `_Successfully restored {count} object_`, which switches the participle's
  agreement per form (`Obnoven`/`Obnoveny`/`Obnoveno`) exactly as Czech requires.

Thirty-one locales are complete: `nl`, `de`, `fr`, `es`, `it`, `pt`, `sv`, `da`,
`nb`, `pl`, `cs`, `ru`, `uk`, `el`, `fi`, `hu`, `tr`, `ca`, `et`, `hr`, `lt`, `lv`,
`ro`, `sk`, `sl`, `bg`, `sr`, `rm`, `ga`, `mt`, `is` — the whole high-confidence group plus the
first four of the low-resource ones. Five remain (`lb`, `sq`, `mk`, `be`, `bs`),
in that order, which the owner has confirmed — no need to re-ask per locale as long as
the order is kept.

For non-Latin locales (`ru`, `uk`, `bg`, `be`, `mk`, `sr`, `el`) a script-coverage
check replaces the English-leftover check, and it is now a committed script:
`npm run l10n:script -- <loc>`. Note it cannot distinguish an
untranslated string from a correct one built around a literal identifier — `ru`
legitimately retains 11 such values (`conversationId`, `fileCollection`,
`Zookeeper`, file paths), where every word of prose *is* translated. `bg` retains
16, and all 16 are recorded: the 14 cognates plus the two case normalisations
(`Id` → `ID`, `Url` → `URL`). The script prints Latin runs found *inside* otherwise
translated values too, which is the half the older ad-hoc checks missed.

**On `sr` it earned the whole exercise.** Of 2052 values, exactly two Latin-only ones
were not recorded cognates, and both were genuine defects that had passed every gate for
the life of the file: `NO ACTION` → `NEMA RADNJE`, which is correct Serbian in the
**wrong alphabet**, and `Audit trail #{id}` → `Revizijski trag #{id}`, which is the wrong
alphabet *and* Croatian *and* inconsistent with the bundle's 24 other audit-trail values.
Neither is empty, identical to English, or a bad plural, so nothing else could see them.
Run the sweep before assuming a non-Latin bundle's pre-existing half is sound.

### `ca` traps

Register is **formal** (491 vs 32 against core) with **bare 2sg imperative buttons**, so a single
value legitimately mixes `Desa` on the button with `Deseu`-style 2pl in the prose beside it. An
imperative on an action label is the convention, not a slip.

- **The interpunct `·` (U+00B7) is word-internal.** `col·lecció`, `paral·lel`, `Cancel·la`,
  `instal·lada`, `sol·licitud`, `excel·lent`. Any tokeniser, spell check or regex sweep that
  treats it as a separator will split these in half and report the halves — this actually
  happened to `spell.js` and is written up in runbook §8.3. It also makes the verb `instal·la`
  look like the article `la` to an elision check.
- **`la` does NOT contract before an unstressed initial `i-` or `u-`.** `la informació`,
  `la identitat`, `la integració`, `la interfície`, `la UE` are all correct; `l'` is required
  only before a stressed vowel (`l'alternativa`, `l'API`, `l'objecte`, `l'esquema`). This makes
  a naive elision sweep about 1-in-12 accurate here (runbook §6.9).
- **`registre` collapses three English words** — `Register` (the app's primary entity), `log`,
  and `record`. That is not avoidable at the stem, but it *is* avoidable per key, and two of the
  collisions were on-screen: `Logs`/`Registers` were the two tabs of one tab bar in
  `ViewSource.vue`. `Logs` took the qualifier the bundle's other 16 log keys already use
  (`Registres d'activitat`), and *record* in the MDM screens moved to the app's own `objecte`.
  Spanish and Portuguese have the identical three-way collapse and have not been audited, so
  expect this to recur in both — and do **not** read their agreement with `ca` as evidence.
- **`Configuració` covers both `Settings` and the app's `Configuration` entity.** Core `ca` splits
  them (`Paràmetres`/`Configuració`) and so did the bundle's own one key containing both words.
  35 keys; the owner chose to normalise them all. Compare `cs`, where the same shape came out the
  other way round because core pointed at the losing option.
- **False friends that pass every gate**: `inconsistent` means *flimsy* in Catalan, not
  *contradictory* (use `incoherent`); `citació` is a judicial summons, not a citation;
  `desplaçament` is scrolling, not moving an item; `serial` is a broadcast serial, not
  *sequential* (`en sèrie`); `amigable` describes a person's manner, not a UI. And `autoritzat`
  is *authorised*, which is not *authoritative*.
- **`Fins a la data` is an idiom meaning "up to now"**, so it is wrong as the label of a `To Date`
  field — a sense error that reads perfectly well in isolation.
- **`(s)` parentheticals only work where the plural is a bare suffix.** `dia(es)` and
  `problema(es)` expand to the non-words *diaes* and *problemaes* because both stems change, so
  those took the slash (`dia/dies`). `cas(os)`, `tauler(s)`, `giny(s)` are fine. Same rule `is`
  arrived at, for the same reason.
- **Two useful negatives, both checked rather than assumed.** `Refresh` and `Update` both render
  `Actualitza` and that is **core `ca` verbatim for both**, so the collision is not a defect.
  `Remove` and `Delete` both land in the `suprimir` family, and core collapses them too — the
  same answer `sk` reached. Neither was "fixed".
