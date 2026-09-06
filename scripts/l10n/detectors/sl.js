 
// Slovenian register detector for openregister l10n.
//
// Measures the PROSE register only. Slovenian buttons take the bare 2sg
// imperative regardless of how prose addresses the reader — the same convention
// as ca/et/hr, and NOT the infinitive its neighbour sk uses. Counting those
// imperatives would be wrong twice over here:
//
//   1. it is the correct label convention, so every button in the app would flag;
//   2. for the whole `-iti` verb class the 2sg imperative is spelled exactly like
//      the 3sg present indicative — `uredi` is both "edit!" and "he edits", and so
//      are `shrani`, `osveži`, `obnovi`, `posodobi`, `preveri`. Those six are all
//      button labels in this very bundle. (`-ati` and `-irati` verbs do differ:
//      `dodaj` vs `doda`, `kopiraj` vs `kopira` — but the collision class is far
//      too large to carve out.)
//
// So sl looks like sk on the map and behaves like hr in the data. This is exactly
// why the register and the button convention are measured per locale.
//
// Why closed word lists and NOT suffix patterns, specifically for Slovenian:
//   • "-te" is the 2pl ending, imperative and present alike, but it is ALSO the
//     accusative plural of the demonstrative "ta": "te datoteke" = "these files".
//     And bare "te" is the accusative of informal "ti", so the same two letters
//     point in both directions at once.
//   • "-š" looks like the 2sg present ending, but "vaš" (your-FORMAL) and "naš"
//     (our) both end in it — a "-š" rule scores the formal possessive as informal
//     and inverts the polarity outright, the same trap as hr and sk.
//   • bare "ti" is unusable: besides informal "you" it is the masculine
//     nominative plural of "ta", so "ti objekti" means "those objects" — the same
//     collision cs has with "ty" and hr with "ti".
//   • bare "si" is unusable: besides the 2sg of "biti" it is the reflexive dative
//     clitic, which is at its most common in FORMAL prose ("lahko si izberete").
// JS \b is ASCII-only and would treat "č" as a boundary, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag.

/**
 *
 * @param s
 */
function fold(s) {
	return String(s).toLowerCase()
}

// vi / formal 2pl. Slovenian capitalises the polite pronoun in careful writing
// ("Vi", "Vaš"), but either casing is the same register in a UI string.
const FORMAL_RES = [
	// "vas" is also the noun "village" — see UNDETECTABLE. Kept because a village
	// is implausible in this app's domain and the pronoun is frequent.
	/(?<!\p{L})(?:vi|vas|vam|vami)(?!\p{L})/gu,
	// vaš- possessive, all cases. The guard keeps "naš" out.
	/(?<!\p{L})vaš(?:a|e|ega|emu|em|im|ih|o|i|ima|imi)?(?!\p{L})/gu,
	// closed list of 2pl present-indicative forms. NOT a "-te" rule — see header.
	/(?<!\p{L})(?:morate|nemorate|želite|veste|vidite|imate|nimate|potrebujete|ste|niste|boste|znate|hočete|morete|dobite|izberete|greste)(?!\p{L})/gu,
	// closed list of 2pl imperatives / 2pl presents that recur in Nextcloud UI
	// prose. Slovenian spells the 2pl imperative and the 2pl present alike for
	// most verbs, and both are formal, so no split is needed.
	/(?<!\p{L})(?:izberite|vnesite|preverite|počakajte|kliknite|shranite|izbrišite|dodajte|odprite|zaprite|uporabite|poskusite|pošljite|potrdite|nadaljujte|spremenite|odstranite|kopirajte|poglejte|nastavite|ustvarite|naložite|obrnite|sledite|izpolnite|namestite|vklopite|izklopite|prijavite|odjavite|zagotovite|naredite|pojdite|preberite|napišite|posodobite|kontaktirajte|imejte|bodite|upravljajte|filtrirajte|prikažite|izvozite|uvozite|analizirajte|konfigurirajte|nadzorujte|vektorizirajte|pustite|pridobite|začnite|ponovite|obnovite|prekličite|končajte|vstavite|premaknite|preimenujte|pritisnite|obvestite|upoštevajte|glejte|spremljajte|iščite|najdite|dopolnite|uredite|določite|dodelite|prenesite|zaženite|ustavite|objavite|preizkusite|sinhronizirajte|aktivirajte|deaktivirajte|onemogočite|omogočite|razširite|tipkajte|zožite|validirajte|arhivirajte|osvežite|počistite|izvedite|vključite|izključite|zapišite|primerjajte|zaznajte|identificirajte|razdelite|označite|povežite|ločite|omejite|zamenjajte|dovolite|zavrnite|odobrite|zberite|revidirajte)(?!\p{L})/gu,
]

// ti / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// bare "ti" and bare "te" deliberately absent — both are demonstratives too.
	// See the header. The oblique forms below have no other reading.
	/(?<!\p{L})(?:tebe|tebi|tabo|teboj)(?!\p{L})/gu,
	/(?<!\p{L})tvoj(?:a|e|ega|emu|em|im|ih|o|i|ima|imi)?(?!\p{L})/gu,
	// 2sg present of the highest-frequency modal/perception verbs. Bare "si" is
	// deliberately absent — see header.
	/(?<!\p{L})(?:moraš|nemoraš|želiš|veš|vidiš|imaš|nimaš|potrebuješ|boš|znaš|hočeš|moreš|dobiš|izbereš|klikneš|nisi|greš)(?!\p{L})/gu,
	// 2sg presents of the -iti verbs whose 2pl counterparts are in the formal list
	// above. Unlike the imperative, the 2sg present is NOT a 3sg homograph — the
	// 3sg drops the -š (`spremeni`, `shrani`) — so these are safe to enumerate.
	// Still a closed list rather than a "-š" rule, which would match "vaš"/"naš".
	/(?<!\p{L})(?:spremeniš|preveriš|shraniš|odstraniš|uporabiš|poskusiš|nastaviš|ustvariš|naložiš|posodobiš|odpreš|zapreš|dodaš|kopiraš|izbrišeš|prikažeš|začneš|nadaljuješ|vneseš|pošlješ|potrdiš)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (vi prose) — all real values from this bundle or core sl
	['Izberite register', 'formal'],
	['Vnesite opis (neobvezno) ...', 'formal'],
	['Počakajte, medtem ko pridobivamo vaše konfiguracije.', 'formal'],
	['Upravljajte svoje podatkovne registre in njihove konfiguracije', 'formal'],
	['Uporabite filtre za zožitev vnosov revizijske sledi', 'formal'],
	['Za dodajanje pogledov med priljubljene morate biti prijavljeni', 'formal'],
	['Za začetek pogovora potrebujete agenta UI.', 'formal'],
	['Vaš ključ API OpenAI. Pridobite ga na', 'formal'],
	['Izberite repozitorij, do katerega imate dostop za pisanje', 'formal'],
	['Poskusite znova pozneje.', 'formal'],
	['Pred odločitvijo preberite vnos.', 'formal'],
	['Tipkajte za iskanje skupin', 'formal'],
	['Ali ste prepričani, da želite izbrisati', 'formal'],
	// the reflexive-dative "si" must not flip a formal sentence to informal
	['Lahko si izberete drug register', 'formal'],
	// must read informal (ti prose) — the deviation
	['Tvoj register', 'informal'],
	['To lahko spremeniš pozneje', 'informal'],
	['Če želiš, poskusi znova', 'informal'],
	['To vpliva na tvoje podatke', 'informal'],
	['Poslano tebi', 'informal'],
	['Ali veš, kje je datoteka?', 'informal'],
	['Nisi prijavljen', 'informal'],
	// must read NEITHER: the bare 2sg imperative IS the correct Slovenian label
	// convention, and for every -iti verb below it is also the 3sg present
	// indicative. If these fired, every button in the app would flag.
	['Shrani', 'neither'],
	['Izbriši', 'neither'],
	['Dodaj', 'neither'],
	['Ustvari', 'neither'],
	['Uredi', 'neither'],
	['Prekliči', 'neither'],
	['Zapri', 'neither'],
	['Kopiraj', 'neither'],
	['Osveži', 'neither'],
	['Obnovi', 'neither'],
	['Posodobi', 'neither'],
	['Preveri verigo', 'neither'],
	['Izberi vse', 'neither'],
	['Prikaži podrobnosti', 'neither'],
	['Dodaj aplikacijo', 'neither'],
	['Analiziraj objekte', 'neither'],
	// must read NEITHER: the "-te"/"te" demonstrative trap. These are ordinary
	// noun phrases, not 2pl verbs and not the accusative of "ti".
	['Te datoteke so izbrisane', 'neither'],
	['Prikaži te objekte', 'neither'],
	['Vse te sheme', 'neither'],
	// must read NEITHER: bare "ti" is the masculine nominative plural of "ta".
	['Ti objekti so izbrisani', 'neither'],
	['Ti registri niso najdeni', 'neither'],
	// must read NEITHER: the "-š" trap. "naš" is not a 2sg verb, and "vaš" must
	// score FORMAL rather than informal — that pair is tested above and here.
	['Naš register', 'neither'],
	['Naše nastavitve', 'neither'],
	// must read NEITHER: "še" is an adverb, not a 2sg form
	['Zaznana še ni bila nobena entiteta', 'neither'],
	// mixed sanity: one informal marker inside otherwise formal prose wins
	['Izberite register in nato shrani tvoje spremembe', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Shrani svoje spremembe', 'bare 2sg imperative — the correct Slovenian button '
		+ 'convention AND the 3sg present of every -iti verb; "svoje" is '
		+ 'person-neutral, so nothing here marks the number'],
	['Poskusi znova', 'same: "poskusi" is also the 3sg present of "poskusiti"'],
	['Vas je lepa', 'FALSE POSITIVE the other way — "vas" is also the noun '
		+ '"village", so this scores formal. Implausible in this app\'s domain, '
		+ 'which is why the pronoun is kept'],
	['Ali vidva želita nadaljevati?', 'the DUAL (2du "-ta") is a third address '
		+ 'form Slovenian has and the other 24 locales do not. It addresses '
		+ 'exactly two people and never appears in UI prose, so it is not matched'],
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
	reportCoreRegister('sl', scanCoreRegister('sl', score), { formal: 'vi', informal: 'ti' })
}
