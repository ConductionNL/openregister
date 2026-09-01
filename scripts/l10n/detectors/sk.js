/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Slovak register detector for openregister l10n.
//
// Measures the prose register AND the 2sg imperative, which is a genuine
// deviation in Slovak — unlike Croatian, Catalan and Estonian, where the bare
// 2sg imperative is the correct button convention and counting it would flag
// every button. Slovak labels the buttons with the INFINITIVE ("Uložiť",
// "Odstrániť", "Pridať"), so "Ulož" / "Odstráň" / "Pridaj" is not a competing
// convention here, it is familiar address. Two facts make this safe to detect,
// and both are specific to Slovak:
//   • the 2sg imperative is NOT a homograph of the 3sg present indicative
//     ("ulož" vs "uloží", "pridaj" vs "pridá", "zmeň" vs "zmení"), which is
//     exactly the collision that makes the Croatian imperative undetectable;
//   • the infinitive ends in -ť, so no infinitive can be mistaken for one.
// So no label-position bound is needed, unlike ro.js.
//
// DIACRITICS ARE NOT FOLDED, and that is load-bearing rather than laziness.
// Three of the distinctions this detector rests on are carried by the acute
// alone, so stripping it would break the detector in both directions:
//   • "ti" (dative of "ty", informal) vs "tí" (masculine animate nominative
//     plural of "ten" — "tí používatelia" = "those users");
//   • "vyber" (2sg imperative, informal) vs "výber" ("selection", a noun this
//     bundle uses in five keys — "Výber typu súboru");
//   • "uprav" (2sg imperative) vs "úprava" / "úprav" (genitive plural of
//     "edit"). fold() therefore only lowercases.
//
// Why closed word lists and NOT suffix patterns, specifically for Slovak:
//   • "vy-" is the most productive verbal prefix in the language: vybrať,
//     vymazať, vytvoriť, vyhľadať, vypnúť, vyčistiť, vypočítať, vyplniť. An
//     unguarded "vy" marker matches most of the buttons in this app. Every
//     pronoun regex below is boundary-guarded for this reason, and "Vybrať
//     všetko" is a must-not-fire control.
//   • "-te" looks like the 2pl ending (imperative and present), but it is also
//     the locative singular of every hard masculine noun: "v dokumente",
//     "v objekte", "v elemente", "v momente". And "ešte" ("still", "yet") is
//     one of the most frequent adverbs in the language and ends in -te.
//   • "-š" looks like the 2sg present ending, but "váš" (your-FORMAL) and "náš"
//     (our) both end in it, as does "kôš". A "-š" rule scores the formal
//     possessive as informal — polarity-inverting noise, the same trap as hr.
//   • bare "si" is unusable as an informal marker: besides the 2sg of "byť" it
//     is the reflexive dative clitic, which is at its most common in formal
//     prose ("môžete si vybrať", "prečítajte si záznam" — both in this bundle).
// JS \b is ASCII-only and would treat "á" as a boundary, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag.

function fold(s) {
	return String(s).toLowerCase()
}

// vy / formal 2pl. Slovak does not capitalise the polite pronoun as
// consistently as Croatian does, and either casing is the same register in a
// UI string, so the match is case-insensitive.
const FORMAL_RES = [
	// Boundary-guarded so the vy- verbal prefix cannot match. "vám"/"vás"/"vami"
	// have no other reading; bare "vy" is only the pronoun.
	/(?<!\p{L})(?:vy|vás|vám|vami)(?!\p{L})/gu,
	// váš- possessive, all cases. The guard keeps "náš" out, and the "vaš"/"váš"
	// stem split is real Slovak alternation (váš / vaša / vášho / vašej).
	/(?<!\p{L})(?:váš|vášho|vášmu|vaš(?:a|e|ej|ich|im|imi|ou|u|om|i|ím)?)(?!\p{L})/gu,
	// closed list of 2pl present-indicative forms. NOT a "-te" rule — see header.
	/(?<!\p{L})(?:môžete|nemôžete|musíte|nemusíte|chcete|nechcete|viete|neviete|vidíte|máte|nemáte|potrebujete|ste|budete|dostanete|používate|zvolíte|uvidíte|nájdete)(?!\p{L})/gu,
	// closed list of 2pl imperatives that actually recur in Nextcloud UI prose.
	/(?<!\p{L})(?:zadajte|vyberte|zvoľte|skontrolujte|počkajte|kliknite|uložte|vymažte|pridajte|otvorte|zatvorte|použite|skúste|pošlite|potvrďte|pokračujte|zmeňte|odstráňte|skopírujte|pozrite|prezrite|nastavte|vytvorte|načítajte|obráťte|postupujte|vyplňte|nainštalujte|zapnite|vypnite|prihláste|odhláste|uistite|urobte|prejdite|prečítajte|napíšte|píšte|aktualizujte|kontaktujte|majte|buďte|spravujte|filtrujte|zobrazte|exportujte|importujte|analyzujte|konfigurujte|ovládajte|vektorizujte|ponechajte|získajte|začnite|opakujte|obnovte|overte|zrušte|dokončite|vložte|presuňte|premenujte|stlačte|všimnite|zvážte|sledujte|hľadajte|nájdite|doplňte|upravte|definujte|priraďte|nahrajte|stiahnite|spustite|zastavte|publikujte|testujte|synchronizujte|aktivujte|deaktivujte|zakážte|povoľte|rozšírte|vyčistite|vypočítajte|extrahujte|generujte|identifikujte|porovnajte|zistite|zahrňte|uchovávajte|zapisujte|čakajte|zdieľajte|vyhľadajte|zapečaťte)(?!\p{L})/gu,
]

// ty / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// Bare "ty" is safe in Slovak, unlike Croatian "ti": the demonstrative plural
	// is "tí"/"tie", never "ty". "ti" carries no acute and is the dative of "ty";
	// "tí" is excluded by the acute, which is why fold() keeps diacritics.
	/(?<!\p{L})(?:ty|ťa|ti|teba|tebe|tebou)(?!\p{L})/gu,
	/(?<!\p{L})tvoj(?:a|e|ej|ho|mu|ich|im|imi|ou|u|om|i|ím)?(?!\p{L})/gu,
	// 2sg present of the highest-frequency modal/perception verbs. Bare "si" is
	// deliberately absent — see header.
	/(?<!\p{L})(?:môžeš|nemôžeš|musíš|nemusíš|chceš|nechceš|vieš|nevieš|vidíš|máš|nemáš|potrebuješ|budeš|dostaneš|klikneš|vyberieš|uvidíš|nájdeš|používaš)(?!\p{L})/gu,
	// closed list of 2sg imperatives. Safe to detect in Slovak because they are
	// not homographs of the 3sg present and the infinitive ends in -ť; see the
	// header for why that is not true of hr/ca/et. "otvor" is deliberately
	// absent — it is also the noun "aperture" (see UNDETECTABLE).
	/(?<!\p{L})(?:ulož|pridaj|vyber|odstráň|uprav|zruš|zatvor|skús|počkaj|zadaj|klikni|použi|vytvor|nastav|zobraz|prečítaj|napíš|pošli|potvrď|pokračuj|zmeň|skopíruj|obnov|over|spusti|zastav|vymaž|načítaj|vyplň|nainštaluj|zapni|vypni|urob|prejdi|vyhľadaj|filtruj|spravuj|analyzuj|konfiguruj|exportuj|importuj|testuj|publikuj|zdieľaj|vyčisti|vypočítaj|začni|opakuj|dokonči)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (vy prose) — every one of these is a real value from this
	// bundle or from core sk
	['Vyberte register', 'formal'],
	['Zadajte popis (voliteľné)...', 'formal'],
	['Počkajte, kým načítame vaše konfigurácie.', 'formal'],
	['Spravujte svoje dátové registre a ich konfigurácie', 'formal'],
	['Použite filtre na zúženie záznamov', 'formal'],
	['Na pridanie zobrazení medzi obľúbené musíte byť prihlásení', 'formal'],
	['Na začatie konverzácie potrebujete AI agenta.', 'formal'],
	['Váš API kľúč OpenAI. Získajte ho na', 'formal'],
	['Vyberte repozitár, ku ktorému máte prístup na zápis', 'formal'],
	['Skúste to znova neskôr.', 'formal'],
	['Pred rozhodnutím si záznam prečítajte.', 'formal'],
	['Zapnite tam, kde sa vyžaduje doklad o súlade.', 'formal'],
	// the reflexive-dative "si" must not flip a formal sentence to informal
	['Môžete si vybrať iný register', 'formal'],
	// must read informal (ty prose / 2sg imperative) — the deviation
	['Tvoj register', 'informal'],
	['Môžeš to zmeniť neskôr', 'informal'],
	['Ak chceš, skús to znova', 'informal'],
	['Ovplyvní to tvoje údaje', 'informal'],
	['Poslané tebe', 'informal'],
	['Dáme ti vedieť', 'informal'],
	['Zadaj popis', 'informal'],
	['Ulož zmeny', 'informal'],
	['Pridaj aplikáciu', 'informal'],
	['Odstráň objekt', 'informal'],
	['Vyber register', 'informal'],
	// must read NEITHER: the INFINITIVE is the correct Slovak label convention,
	// and several begin with the vy- prefix. If the pronoun guard were missing,
	// most of these would score formal.
	['Uložiť', 'neither'],
	['Odstrániť', 'neither'],
	['Vytvoriť', 'neither'],
	['Upraviť', 'neither'],
	['Zrušiť', 'neither'],
	['Zatvoriť', 'neither'],
	['Kopírovať', 'neither'],
	['Pridať aplikáciu', 'neither'],
	['Vybrať všetko', 'neither'],
	['Vybrať register a schému', 'neither'],
	['Vyhľadať webhooky', 'neither'],
	['Vyčistiť staré záznamy', 'neither'],
	['Vypočítať veľkosti', 'neither'],
	['Vymazať výber', 'neither'],
	['Vektorizovať všetky objekty', 'neither'],
	['Exportovať', 'neither'],
	// must read NEITHER: the "-te" locative-singular and adverb traps. These are
	// ordinary noun phrases and one very common adverb.
	['V dokumente', 'neither'],
	['V objekte', 'neither'],
	['V elemente', 'neither'],
	['notify_push je nainštalovaný, ale ešte nie je aktívny', 'neither'],
	['Zatiaľ neboli zistené žiadne entity', 'neither'],
	// must read NEITHER: the "-š" trap. "náš" is not a 2sg verb and "kôš" is a
	// basket. The formal counterpart "váš" is tested above.
	['Náš register', 'neither'],
	['Naše nastavenia', 'neither'],
	['Prázdny kôš', 'neither'],
	// must read NEITHER: "tí" is the demonstrative, not the dative of "ty". This
	// pair is the reason fold() keeps diacritics.
	['Tí používatelia majú prístup', 'neither'],
	// must read NEITHER: "výber" is the noun, not the 2sg imperative "vyber";
	// "úprav" is a genitive plural, not "uprav". Same reason.
	['Výber typu súboru', 'neither'],
	['Späť k úpravám', 'neither'],
	['Výber zobrazení', 'neither'],
	// mixed sanity: one informal marker inside otherwise formal prose wins
	['Vyberte register a potom ulož zmeny', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Otvor súbor', '2sg imperative of "otvoriť", but "otvor" is also the noun '
		+ '"aperture/opening", so counting it would flag a legitimate noun phrase'],
	['Nezabudni na to', 'negated 2sg imperatives are formed with ne- on an open '
		+ 'set of verbs; only the affirmative forms are enumerated'],
	['Svoje nastavenia nájdeš tam', '"svoje" is person-neutral, so only the verb '
		+ 'carries the 2sg — covered here, but a nominal sentence would not be'],
]

function score(s) {
	const t = fold(s)
	let f = 0
	let i = 0
	// Fresh regex per call: a reused /g/ carries lastIndex and silently turns
	// later matches into misses.
	for (const re of FORMAL_RES) f += (t.match(new RegExp(re.source, re.flags)) || []).length
	for (const re of INFORMAL_RES) i += (t.match(new RegExp(re.source, re.flags)) || []).length
	return { f, i }
}

function runControls() {
	let fail = 0
	for (const [s, want] of CONTROLS) {
		const { f, i } = score(s)
		const got = i > 0 ? 'informal' : f > 0 ? 'formal' : 'neither'
		if (got !== want) {
			fail++
			console.log(`FAIL want=${want} got=${got} f=${f} i=${i}  ${s}`)
		}
	}
	return { fail, total: CONTROLS.length }
}

module.exports = { score, fold, runControls, CONTROLS, UNDETECTABLE }

if (require.main === module) {
	const { fail, total } = runControls()
	console.log(`controls: ${total - fail}/${total} pass`)
	if (fail) process.exitCode = 1

	const { scanCoreRegister, reportCoreRegister } = require('../lib.js')
	reportCoreRegister('sk', scanCoreRegister('sk', score), { formal: 'vy', informal: 'ty' })
}
