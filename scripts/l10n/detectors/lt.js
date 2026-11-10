 
// Lithuanian register detector for openregister l10n.
//
// Measures the PROSE register. Whether Lithuanian also splits by string role
// (bare imperative buttons regardless of prose register, as ca/et/hr do) is
// decided from core's own button labels, not from this detector.
//
// Why closed word lists and NOT suffix patterns, specifically for Lithuanian:
//   • "-ai" is NOT a usable 2sg-present marker. It ends the nominative plural of
//     every masculine noun ("objektai", "failai", "vartotojai") and a huge class
//     of adverbs ("gerai", "puikiai", "automatiškai"). A "-ai" rule would score
//     ordinary noun and adverb phrases as informal.
//   • "-i" is worse: it is the 2sg present ending AND, for conjugation-II verbs,
//     the 3sg/3pl ending too. "gali", "turi", "nori" all mean both "you can/have/
//     want" and "he/they can/have/want", because Lithuanian third person makes no
//     number distinction. These three are the highest-frequency modals in UI prose
//     and are therefore EXCLUDED — counting them would manufacture informal hits
//     out of ordinary third-person statements.
//   • "-kite" looks like a safe 2pl-imperative marker, but it is still spelled out
//     as a closed list here so a stray noun cannot match.
// The unambiguous signals are the pronouns (tu-series vs jūs-series) plus 2sg
// forms whose 3sg counterpart differs ("žinai" vs 3sg "žino").

/**
 *
 * @param s
 */
function fold(s) {
	return String(s).toLowerCase()
}

// jūs / formal 2pl. Case-insensitive: Lithuanian politeness capitalises "Jūs",
// but plain 2pl "jūs" is the same register in a UI string.
const FORMAL_RES = [
	/(?<!\p{L})(?:jūs|jūsų|jums|jumis|jūsiškai)(?!\p{L})/gu,
	// closed list of 2pl imperatives that actually recur in Nextcloud UI prose
	/(?<!\p{L})(?:pasirinkite|įveskite|patikrinkite|palaukite|spustelėkite|išsaugokite|ištrinkite|pridėkite|atidarykite|uždarykite|naudokite|pabandykite|bandykite|išsiųskite|patvirtinkite|tęskite|pakeiskite|pašalinkite|nukopijuokite|ieškokite|peržiūrėkite|taikykite|nustatykite|sukurkite|įkelkite|susisiekite|sekite|užpildykite|įdiekite|įjunkite|išjunkite|eikite|perskaitykite|parašykite|atnaujinkite|atkreipkite|turėkite|būkite|įsitikinkite|padarykite|priskirkite|pasirūpinkite)(?!\p{L})/gu,
]

// tu / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	/(?<!\p{L})(?:tu|tavo|tave|tau|tavimi|tavęs)(?!\p{L})/gu,
	// 2sg present forms whose 3sg counterpart is spelled DIFFERENTLY, so there is
	// no third-person reading. "gali", "turi" and "nori" are deliberately absent —
	// see the header.
	/(?<!\p{L})(?:žinai|matai|gauni|esi|nesi|randi|pasirenki|spusteli|įvedi|keiti|galėtum|turėtum|norėtum)(?!\p{L})/gu,
	// 2sg imperatives of the highest-frequency UI verbs. Unlike Croatian/Catalan
	// these are NOT homographs of a 3sg present (Lithuanian 3sg of "pasirinkti" is
	// "pasirenka", not "pasirink"), so they are safe to count — but see the
	// button-convention note in GLOSSARY.md before treating them as a defect.
	/(?<!\p{L})(?:pasirink|įvesk|patikrink|palauk|spustelėk|išsaugok|ištrink|pridėk|atidaryk|uždaryk|naudok|pabandyk|bandyk|patvirtink|tęsk|pakeisk|pašalink|nukopijuok|ieškok|peržiūrėk|sukurk|įkelk)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (jūs prose)
	['Prašome palaukite', 'formal'],
	['Įveskite savo slaptažodį', 'formal'],
	['Pasirinkite registrą ir schemą', 'formal'],
	['Patikrinkite savo nustatymus', 'formal'],
	['Tai turi įtakos jūsų paskyrai', 'formal'],
	['Jūs galite tai pakeisti vėliau', 'formal'],
	['Jūsų pakeitimai išsaugoti', 'formal'],
	['Susisiekite su administratoriumi', 'formal'],
	// must read informal (tu prose) — the deviation
	['Tavo paskyra', 'informal'],
	['Tu gali tai pakeisti vėliau', 'informal'],
	['Įvesk savo slaptažodį', 'informal'],
	['Jei nori, pabandyk dar kartą', 'informal'],
	['Tai turi įtakos tavo duomenims', 'informal'],
	['Ar žinai, kad tai neatšaukiama?', 'informal'],
	// must read NEITHER: the "-ai" trap. Nominative plurals of masculine nouns and
	// a large class of adverbs end in "-ai" and must not score as 2sg present.
	['Visi objektai', 'neither'],
	['Failai įkelti', 'neither'],
	['Vartotojai ir grupės', 'neither'],
	['Veikia gerai', 'neither'],
	['Sukurta automatiškai', 'neither'],
	['Rodyti puikiai suformatuotą JSON', 'neither'],
	// must read NEITHER: the conjugation-II homograph. These are THIRD person
	// ("it can", "the schema has"), not second person, and are the single easiest
	// way to invert this measurement.
	['Registras gali turėti kelias schemas', 'neither'],
	['Schema turi 12 savybių', 'neither'],
	['Objektas nori būti indeksuotas', 'neither'],
	// must read NEITHER: ordinary third-person and impersonal prose
	['Objektas sėkmingai ištrintas', 'neither'],
	['Duomenų kokybė', 'neither'],
	['Nepavyko įkelti nustatymų', 'neither'],
	['Nėra pasirinktų objektų', 'neither'],
]

// Informal styling this detector cannot see, and why.
const UNDETECTABLE = [
	['Bandyk dar', 'covered by the 2sg-imperative list, but a verb outside that closed list would be missed'],
	['Įrašyk savo vardą', '"savo" is person-neutral, so only the bare 2sg imperative signals 2sg'],
]

/**
 *
 * @param s
 */
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

/**
 *
 */
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
	reportCoreRegister('lt', scanCoreRegister('lt', score), { formal: 'jūs', informal: 'tu' })
}
