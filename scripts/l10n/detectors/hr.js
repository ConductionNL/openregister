/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Croatian register detector for openregister l10n.
//
// Measures the PROSE register only, because — like Catalan and Estonian — the
// Croatian button convention is independent of it: the bare 2sg imperative
// ("Spremi", "Izbriši", "Odaberi") is standard for short labels no matter how
// prose addresses the user. Counting those would flag every button in the app.
//
// Why closed word lists and NOT suffix patterns, specifically for Croatian:
//   • "-te" looks like the 2pl imperative/present ending, but it is also the
//     accusative plural of every masculine noun: "dokumente", "objekte",
//     "atribute", "elemente", "filtere". And "te" is a bare conjunction ("and").
//     A "-te" suffix rule scores ordinary noun phrases as formal.
//   • "-š" looks like the 2sg present ending, but "naš" (our) and "vaš"
//     (your-formal) both end in it, as do "još" (still) and "koš". A "-š" rule
//     scores the FORMAL possessive as informal — polarity-inverting noise.
//   • bare "ti" is NOT usable as an informal marker: it is also the masculine
//     nominative plural of "taj", so "ti objekti" means "those objects".
//   • bare "si" is skipped too — besides 2sg "biti" it is the reflexive dative
//     clitic, which appears in formal sentences ("možete si odabrati").
// The unambiguous signals are the possessive stems (tvoj- / vaš-), the oblique
// pronoun forms, and closed lists of high-frequency finite verbs.

function fold(s) {
	return String(s).toLowerCase()
}

// Vi / formal 2pl. Case-insensitive on purpose: Croatian politeness capitalises
// "Vi/Vas/Vaš", but plain 2pl "vi/vas/vaš" is the same register in a UI string.
const FORMAL_RES = [
	/(?<!\p{L})(?:vi|vas|vam|vama)(?!\p{L})/gu,
	// vaš- possessive, all cases. Guarded by the boundary so "naš" cannot match.
	/(?<!\p{L})vaš(?:a|e|eg|ega|em|emu|oj|om|u|ih|im|ima|i)?(?!\p{L})/gu,
	// closed list of 2pl finite verbs + imperatives that actually recur in
	// Nextcloud UI prose. NOT a "-te" rule — see the header.
	/(?<!\p{L})(?:možete|morate|želite|vidite|znate|imate|trebate|jeste|niste|budete|htjeli|mogli)(?!\p{L})/gu,
	/(?<!\p{L})(?:unesite|odaberite|provjerite|pričekajte|kliknite|spremite|izbrišite|dodajte|otvorite|zatvorite|koristite|pokušajte|pošaljite|potvrdite|nastavite|promijenite|uklonite|kopirajte|potražite|pogledajte|primijenite|postavite|stvorite|učitajte|obratite|slijedite|ispunite|instalirajte|uključite|isključite|prijavite|odjavite|osigurajte|napravite|idite|pročitajte|napišite|ažurirajte|kontaktirajte|imajte|uzmite|budite)(?!\p{L})/gu,
]

// ti / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// bare "ti" deliberately absent (homograph of "those"); oblique forms are safe
	/(?<!\p{L})(?:tebe|tebi|tobom)(?!\p{L})/gu,
	/(?<!\p{L})tvoj(?:a|e|eg|ega|em|emu|oj|om|u|ih|im|ima|i)?(?!\p{L})/gu,
	// 2sg present of the highest-frequency modal/perception verbs. These have no
	// nominal reading, unlike the bare imperatives.
	/(?<!\p{L})(?:možeš|moraš|želiš|vidiš|znaš|imaš|hoćeš|trebaš|jesi|nisi|budeš|dobiješ|odabereš|klikneš)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (Vi prose)
	['Molimo pričekajte', 'formal'],
	['Unesite svoju lozinku', 'formal'],
	['Odaberite registar i shemu', 'formal'],
	['Provjerite svoje postavke', 'formal'],
	['Ovo utječe na vaš račun', 'formal'],
	['Možete to promijeniti kasnije', 'formal'],
	['Vaše promjene su spremljene', 'formal'],
	['Obratite se administratoru', 'formal'],
	// must read informal (ti prose) — the deviation
	['Tvoj račun', 'informal'],
	['Možeš to promijeniti kasnije', 'informal'],
	['Ako želiš, pokušaj ponovno', 'informal'],
	['Ovo utječe na tvoje podatke', 'informal'],
	['Poslano tebi', 'informal'],
	// must read NEITHER: bare 2sg imperative button labels are the CORRECT
	// Croatian UI convention, and each is also a homograph of the 3sg present
	// ("uredi" = "edit!" / "he edits"). If these fired, every button would flag.
	['Spremi', 'neither'],
	['Izbriši', 'neither'],
	['Dodaj', 'neither'],
	['Odaberi', 'neither'],
	['Otvori', 'neither'],
	['Zatvori', 'neither'],
	['Uredi', 'neither'],
	['Odustani', 'neither'],
	['Kopiraj', 'neither'],
	['Stvori registar', 'neither'],
	// must read NEITHER: the "-te" accusative-plural trap. These are ordinary
	// noun phrases and must not be scored as 2pl.
	['Odabrani dokumente', 'neither'],
	['Prikaži objekte', 'neither'],
	['Filtriraj atribute', 'neither'],
	['Sve komponente', 'neither'],
	['Popis elemente', 'neither'],
	// must read NEITHER: the "-š" trap. "naš" is not a 2sg verb, and "još" is an
	// adverb. Critically, "vaš" must score FORMAL, never informal — that pair is
	// tested above and here.
	['Naš registar', 'neither'],
	['Još nema rezultata', 'neither'],
	['Prazan koš', 'neither'],
	// must read NEITHER: bare "ti" is the masculine nominative plural of "taj".
	['Ti objekti su izbrisani', 'neither'],
	['Ti zapisi nisu pronađeni', 'neither'],
	// mixed sanity: an informal marker inside otherwise neutral prose still wins
	['Nema shema. Stvori prvu shemu za tvoj registar.', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls: the bare 2sg imperative is both the correct Croatian
// button convention and a homograph of the 3sg present indicative, so counting
// it would flag every button in the app.
const UNDETECTABLE = [
	['Pokušaj ponovno', 'bare 2sg imperative — correct button style, homograph of 3sg present'],
	['Unesi svoju lozinku', '"svoju" is person-neutral, so only the bare imperative signals 2sg'],
	['Odaberi registar', 'same: "odaberi" is also the 3sg present of "odabrati"'],
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
	reportCoreRegister('hr', scanCoreRegister('hr', score), { formal: 'Vi', informal: 'ti' })
}
