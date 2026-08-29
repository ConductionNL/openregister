/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Latvian register detector for openregister l10n.
//
// Measures the PROSE register: jūs (formal 2pl) against tu (informal 2sg).
// The BUTTON convention is a separate question and is not measured here —
// core lv answers it on its own: every short label is an infinitive
// ("Saglabāt", "Atjaunināt", "Apstiprināt", "Turpināt", "Atcelt"), which is the
// register-neutral pattern cs and lt also use.
//
// Note on capitalisation: Latvian capitalises "Tu / Tev / Tavs" as a politeness
// convention when addressing one person, and core lv does exactly that. That is
// still the 2sg series, i.e. INFORMAL in the tu/jūs opposition — the formal
// address is "Jūs". So the tu-series is matched case-insensitively and capital
// "Tavas datnes" counts as informal, not as some third register.
//
// Why closed word lists and NOT suffix patterns, specifically for Latvian:
//
//   • "-at" / "-āt" is the INFINITIVE ending, not a 2pl-present marker. Every
//     single one of the 12 distinct -at/-āt words in core lv is an infinitive
//     or an adverb: atjaunināt, saglabāt, apstiprināt, mēģināt, uzzināt,
//     turpināt, vaicāt, atsvaidzināt, ritināt, atklāt, drukāt, turklāt. A "-at"
//     rule would therefore score every button label in the language as formal
//     2pl and invert the measurement outright.
//
//   • "-iet" is not safe either. Of the 4 distinct -iet words in core lv, only
//     "skatiet" and "turiet" are 2pl imperatives; "vienuviet" is an adverb ("in
//     one place") and "nešķiet" is third person ("does not seem"). Half of a
//     suffix rule's hits would be false.
//
//   • "-i" is the worst of the three as a 2sg-present marker, exactly as "-ai"
//     is for Lithuanian: it ends the nominative plural of masculine nouns
//     ("faili", "objekti", "lietotāji", "ieraksti", "dati") and the locative
//     singular besides.
//
// Two genuine homograph classes make most 2sg verb forms unusable, and both are
// systematic rather than a handful of exceptions:
//
//   1. For -ēt and -āt verbs the 2sg present is spelled identically to the THIRD
//      person, because Latvian third person makes no number distinction:
//      "meklē" is both "you search" and "he/it searches"; likewise "saglabā",
//      "aizver", "atver". These are ordinary third-person UI prose and are
//      EXCLUDED — counting them would manufacture informal hits out of
//      statements like "Sistēma saglabā izmaiņas".
//
//   2. Feminine nouns in -e have an accusative singular in -i, which collides
//      with the 2sg imperative: "pārbaudi" is both "check!" and the accusative
//      of "pārbaude" (a check); "redzi" collides with "redze" (vision);
//      "atlasi" with "atlase" (a selection). "ievadi" is the same trap from the
//      masculine side — nominative plural of "ievads" (an input). All excluded.
//
// What is left, and is genuinely unambiguous, is the pronoun series plus 2sg
// forms whose third-person counterpart is spelled differently ("vari" vs "var",
// "zini" vs "zina", "esi" vs "ir", "spied" vs "spiež").

function fold(s) {
	return String(s).toLowerCase()
}

// jūs / formal 2pl — the register core lv does NOT use (0 hits in 890 values).
const FORMAL_RES = [
	/(?<!\p{L})(?:jūs|jūsu|jums|jumis|jūsos)(?!\p{L})/gu,
	// Closed list of 2pl imperatives and presents that recur in Nextcloud UI
	// prose. Every entry is checked against its own infinitive, because for
	// -āt-stem verbs the 2pl present IS the infinitive — "jūs zināt" and "zināt"
	// (to know) are the same string, so "zināt" and "uzzināt" are deliberately
	// absent: core lv uses "Uzzināt vairāk" as a neutral "Learn more" link.
	/(?<!\p{L})(?:izvēlieties|ievadiet|atlasiet|konfigurējiet|atstājiet|uzgaidiet|pagaidiet|skatiet|turiet|spiediet|nospiediet|noklikšķiniet|sazinieties|pārbaudiet|mēģiniet|izmēģiniet|izmantojiet|saglabājiet|pievienojiet|aizveriet|atveriet|apstipriniet|turpiniet|dzēsiet|izdzēsiet|kopējiet|meklējiet|dodieties|lejupielādējiet|augšupielādējiet|reģistrējieties|pieslēdzieties|ievērojiet|rīkojieties|varat|vēlaties|esat|redzat|gribat|drīkstat|saņemat|izmantojat|meklējat)(?!\p{L})/gu,
]

// tu / informal 2sg — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	/(?<!\p{L})(?:tu|tev|tevi|tevis|tevī|tavs|tava|tavu|tavas|tavi|tavam|tavai|taviem|tavām|tavā|tavās|tavos|tavus)(?!\p{L})/gu,
	// 2sg forms whose THIRD-PERSON counterpart is spelled differently, so there
	// is no third-person reading, and which do not collide with a noun case.
	// See the header for what was excluded and why.
	/(?<!\p{L})(?:vari|nevari|esi|neesi|zini|nezini|gribi|vēlies|izvēlies|dodies|skaties|pieslēdzies|reģistrējies|mēģini|turpini|apstiprini|spied|nospied|ej)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (jūs prose) — these are the app's own existing lv values
	['Izvēlieties reģistru', 'formal'],
	['Ievadiet objekta ID', 'formal'],
	['Atlasiet repozitoriju, kuram jums ir rakstīšanas piekļuve', 'formal'],
	['Lūdzu, uzgaidiet, kamēr ielādējam jūsu konfigurācijas.', 'formal'],
	['Neviena īpašība neatbilst jūsu filtriem.', 'formal'],
	['Konfigurējiet parametrus objektu vektorizācijai.', 'formal'],
	['Skatiet dokumentāciju', 'formal'],
	['Turiet savus kolēģus un draugus vienuviet.', 'formal'],
	['Jūs varat to mainīt vēlāk', 'formal'],
	['Ja vēlaties, mēģiniet vēlreiz', 'formal'],
	// must read informal (tu prose) — the deviation. All six are real core lv
	// values, which is the measurement this detector exists to make.
	['Tavas datnes ir šifrētas.', 'informal'],
	['Tev nav ļauts kopīgot %s', 'informal'],
	['Tu grasies piešķirt %1$s piekļuvi savam %2$s kontam.', 'informal'],
	['Serveris nevarēja izpildīt tavu pieprasījumu.', 'informal'],
	['droša vieta visiem Taviem datiem', 'informal'],
	['{actor} nomainīja Tavu paroli', 'informal'],
	['Ja vēlies, mēģini vēlreiz', 'informal'],
	['Tu vari to mainīt vēlāk', 'informal'],
	// must read NEITHER: the "-at"/"-āt" infinitive trap. These are core lv's
	// own button and link labels. A suffix rule would score every one of them
	// as formal 2pl, which is how this measurement would get inverted.
	['Saglabāt', 'neither'],
	['Atjaunināt', 'neither'],
	['Apstiprināt', 'neither'],
	['Turpināt', 'neither'],
	['Uzzināt vairāk', 'neither'],
	['Mēģināt vēlreiz', 'neither'],
	['Drukāt', 'neither'],
	['Atcelt', 'neither'],
	['Dzēst', 'neither'],
	['Aizvērt', 'neither'],
	// must read NEITHER: the "-iet" trap — an adverb and a third-person form
	// that a suffix rule would read as 2pl imperatives.
	['Turiet visu vienuviet', 'formal'], // "turiet" is a real 2pl; "vienuviet" must add nothing
	['Nešķiet, ka fails ir derīgs', 'neither'],
	// must read NEITHER: the "-i" plural trap. Nominative plurals of masculine
	// nouns end in -i and must not score as 2sg present.
	['Visi objekti', 'neither'],
	['Faili ir augšupielādēti', 'neither'],
	['Lietotāji un grupas', 'neither'],
	['Ieraksti un dati', 'neither'],
	['Aģenti un fragmenti', 'neither'],
	// must read NEITHER: homograph class 1 — the 2sg form of an -ēt/-āt verb is
	// also the third person. Every one of these is third-person UI prose.
	['Sistēma saglabā izmaiņas', 'neither'],
	['Lietotājs meklē objektus', 'neither'],
	['Pārlūks aizver savienojumu', 'neither'],
	['Reģistrs atver shēmu', 'neither'],
	// must read NEITHER: homograph class 2 — a 2sg imperative that is also a
	// noun case. "pārbaudi" is the accusative of "pārbaude", "atlasi" of
	// "atlase", "redzi" of "redze", "ievadi" the plural of "ievads".
	['Sākt pārbaudi', 'neither'],
	['Notīrīt atlasi', 'neither'],
	['Datu ievadi', 'neither'],
	// must read NEITHER: ordinary third-person and impersonal prose. Core lv
	// prefers the impersonal "Lūgums ..." over addressing the reader at all.
	['Objekts veiksmīgi izdzēsts', 'neither'],
	['Datu kvalitāte', 'neither'],
	['Lūgums mēģināt vēlreiz vai sazināties ar savu pārvaldītāju.', 'neither'],
	['Nav atlasīts neviens objekts', 'neither'],
	['Neizdevās ielādēt iestatījumus', 'neither'],
]

// Informal styling this detector cannot see, and why.
const UNDETECTABLE = [
	['Meklē objektus', 'a 2sg imperative of an -ēt verb is spelled exactly like the third person, so "search!" and "it searches" are indistinguishable without a parse'],
	['Saglabā izmaiņas', 'same collision for -āt verbs — excluded, so a genuinely informal button in this shape reads as neither'],
	['Pārbaudi savus iestatījumus', '"pārbaudi" is also the accusative singular of the noun "pārbaude", so it is excluded and this informal sentence scores neither'],
	['Ievadi savu paroli', '"ievadi" is also the nominative plural of "ievads" (an input), so it is excluded too'],
	['Ieraksti savu vārdu', '"savu" is person-neutral, and "ieraksti" is also the plural of "ieraksts" (a record) — the noun this app uses constantly'],
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
	reportCoreRegister('lv', scanCoreRegister('lv', score), { formal: 'jūs', informal: 'tu' })
}
