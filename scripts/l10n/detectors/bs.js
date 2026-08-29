/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Bosnian register detector for openregister l10n.
//
// CORE IS NOT EVIDENCE FOR THIS LOCALE, and this is the shape §2.3 warns about:
// Nextcloud ships exactly ONE bs catalogue (44 usable values), so
// scanCoreRegister('bs') SUCCEEDS rather than throwing, and would report a
// verdict computed from almost nothing. Zero catalogues is the safe failure; one
// thin catalogue is the dangerous one. The marker count is what matters, not the
// catalogue count, so the main block prints core's own count and then measures the
// §6.4 fallback corpus: this bundle plus the sibling apps' frontend bs.js.
//
// Measured 217 second-person PLURAL (Vi) markers against ZERO singular over
// ~2263 frontend values. Counts and the per-app split are in locales/bs.json.
// This is the `hr` case and NOT the `mt`/`ga` case: Bosnian has a full T-V
// distinction and this register uses the V-form, so the deviation this gate looks
// for is a real `ti` slip rather than an impossible form.
//
// THE PROSE REGISTER AND THE BUTTON CONVENTION DIFFER, so only prose is scored.
// The bare 2sg imperative is the correct Bosnian label style — `Sačuvaj`,
// `Obriši`, `Otkaži`, `Kreiraj`, `Ažuriraj`, `Osvježi` — and counting it would
// flag every button in the app. That is §7.3 pattern 2, as in Croatian.
//
// THE PROMPT OVERRIDE IS LEXICALLY BOUNDED, and where the boundary falls had to
// be measured rather than carried over from `mk`. The English verb decides:
//   `Select …` -> `Odaberi …`   2sg, 20 of 20, at 14 to 45 characters
//   `Choose …` -> `Odaberite …` 2pl,  5 of 5,  at 15 to 128 characters
// So it is not `sq`'s length grading, and it is not `mk`'s "Select/Choose/Enter
// all take 2pl" either — the line runs BETWEEN Select and Choose here. `Enter …`
// goes with Choose (`Unesite …`, 5 of 5). Measure this per verb, per locale.
//
// Why closed word lists and NOT suffix patterns, specifically for Bosnian:
//   • "-te" is the 2pl imperative/present ending AND the accusative plural of
//     every masculine noun. This bundle has `Objekte` 11 times and `entitete` 4.
//     A "-te" rule scores ordinary noun phrases as formal.
//   • "-š" is the 2sg present ending, but `vaš` — the FORMAL possessive — ends in
//     it, as does `još` (10 occurrences here). A "-š" rule inverts the polarity on
//     the single most common formal marker.
//   • bare "ti" is unusable: it is also the masculine nominative plural of `taj`,
//     so "ti Objekti" means "those Objects".
//   • bare "si" is unusable: besides 2sg `biti` it is the reflexive dative clitic,
//     which appears in formal prose ("možete si odabrati").
//   • "svoj-" is excluded on TWO independent counts. It is person-neutral, so it
//     signals no register; and it is the stem of `svojstvo` ("property"), which
//     occurs 30+ times in this bundle as one of the app's first-class nouns. A
//     `svoj-` rule would match the app's own vocabulary.
//
// THE HYPHEN QUESTION, asked because §8.3 requires it and answered differently
// from Albanian. Bosnian attaches case endings to Latin-script loanwords across a
// hyphen — `webhook-a`, `webhook-u`, `VAPID-om` — so a guard of `(?<!\p{L})` will
// match after that hyphen. For `sq` that was fatal, because Albanian attaches the
// DEFINITE ARTICLE there and `-i`/`-je` collide with the copula. Bosnian attaches
// only case endings (-a -u -om -ima -ovi), and not one of them is a register
// marker, so the plain letter guard is correct here and the hyphen must stay
// OUTSIDE it. Same question as `sq` and `ca`, third distinct answer.

function fold(s) {
	return String(s).toLowerCase()
}

// Vi / formal 2pl. Case-insensitive on purpose: Bosnian politeness capitalises
// "Vi/Vas/Vaš", but plain 2pl "vi/vas/vaš" is the same register in a UI string.
const FORMAL_RES = [
	/(?<!\p{L})(?:vi|vas|vam|vama)(?!\p{L})/gu,
	// vaš- possessive, all cases. The boundary guard is what stops "naš" matching.
	/(?<!\p{L})vaš(?:a|e|eg|ega|em|emu|oj|om|u|ih|im|ima|i)?(?!\p{L})/gu,
	// Closed list of 2pl finite verbs that recur in Nextcloud UI prose. NOT a
	// "-te" rule — see the header.
	/(?<!\p{L})(?:možete|morate|želite|vidite|znate|imate|trebate|jeste|niste|budete|htjeli|mogli|nemate)(?!\p{L})/gu,
	// Closed list of 2pl imperatives. Every one of these is attested either in
	// this bundle or in the sibling frontends.
	/(?<!\p{L})(?:unesite|odaberite|provjerite|pričekajte|kliknite|sačuvajte|obrišite|dodajte|otvorite|zatvorite|koristite|pokušajte|pošaljite|potvrdite|nastavite|promijenite|uklonite|kopirajte|potražite|pregledajte|primijenite|postavite|kreirajte|učitajte|obratite|slijedite|ispunite|instalirajte|uključite|isključite|prijavite|odjavite|osigurajte|napravite|idite|pročitajte|napišite|ažurirajte|kontaktirajte|imajte|uzmite|budite|filtrirajte|analizirajte|izvezite|uvezite|upravljajte|konfigurišite|upišite|suzite|nabavite|ostavite|vektorizujte|omogućite|onemogućite|generišite|nemojte)(?!\p{L})/gu,
]

// ti / informal 2sg — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// bare "ti" deliberately absent (homograph of "those"); oblique forms are safe
	/(?<!\p{L})(?:tebe|tebi|tobom)(?!\p{L})/gu,
	/(?<!\p{L})tvoj(?:a|e|eg|ega|em|emu|oj|om|u|ih|im|ima|i)?(?!\p{L})/gu,
	// 2sg present of the highest-frequency modal/perception verbs. These have no
	// nominal reading, unlike the bare imperatives.
	/(?<!\p{L})(?:možeš|moraš|želiš|vidiš|znaš|imaš|hoćeš|trebaš|jesi|nisi|budeš|dobiješ|odabereš|klikneš|nemaš)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (Vi prose) — all real values from this bundle
	['Unesite ID Objekta', 'formal'],
	['Odaberite Šemu', 'formal'],
	['Odaberite Registar', 'formal'],
	['Provjerite odjeljak statistike za napredak.', 'formal'],
	['Koristite filtere ispod da poboljšate svoju pretragu.', 'formal'],
	['Molimo odaberite koji Registar i Šemu koristiti za novi Objekat', 'formal'],
	['Postavite na 0 za obradu svih Objekata.', 'formal'],
	['Pregledajte detalje entiteta i upravljajte relacijama', 'formal'],
	['Ostavite prazno za obradu svih prikaza na osnovu vaše konfiguracije.', 'formal'],
	['Filtrirajte i analizirajte unose traga pretrage', 'formal'],
	['Konfigurišite postavke veze ispod.', 'formal'],
	['Upišite prilagođeni naziv modela', 'formal'],
	['Ako želite, suzite pretragu', 'formal'],
	['Ovo utiče na vaš račun', 'formal'],
	// must read formal via the possessive alone, with no verb marker present
	['Podaci o vašim Objektima', 'formal'],
	// must read informal (ti prose) — the deviation. Constructed, because the
	// bundle contains no informal value: that IS the measurement.
	['Tvoj Registar', 'informal'],
	['Možeš to promijeniti kasnije', 'informal'],
	['Ako želiš, pokušaj ponovo', 'informal'],
	['Ovo utiče na tvoje podatke', 'informal'],
	['Poslano tebi', 'informal'],
	['Nemaš još ni jedan Registar', 'informal'],
	// must read NEITHER: the bare 2sg imperative is the CORRECT Bosnian button
	// convention and is also a homograph of the 3sg present ("uredi" = "edit!" /
	// "he edits"). If these fired, every button in the app would flag.
	['Sačuvaj', 'neither'],
	['Obriši', 'neither'],
	['Otkaži', 'neither'],
	['Uredi', 'neither'],
	['Zatvori', 'neither'],
	['Kreiraj', 'neither'],
	['Kopiraj', 'neither'],
	['Ukloni', 'neither'],
	['Ažuriraj', 'neither'],
	['Osvježi', 'neither'],
	['Omogući', 'neither'],
	['Onemogući', 'neither'],
	['Pokušaj ponovo', 'neither'],
	['Odaberi sve', 'neither'],
	['Odaberi model razgovora', 'neither'],
	['Odaberi Registar i Šemu', 'neither'],
	['Nazad', 'neither'],
	// must read NEITHER: the "-te" accusative-plural trap. Ordinary noun phrases,
	// both attested in this bundle, and they must not be scored as 2pl.
	['Prikaži Objekte', 'neither'],
	['Analiziraj Objekte', 'neither'],
	['Odaberi tipove Datoteka za vektorizaciju:', 'neither'],
	['Poveži entitete', 'neither'],
	['Svi Objekte i Šeme', 'neither'],
	// must read NEITHER: the "-š" trap. "naš" is not a 2sg verb and "još" is an
	// adverb. Critically "vaš" must score FORMAL, never informal — tested above.
	['Naš Registar', 'neither'],
	['Još nema rezultata', 'neither'],
	['Još nema dostupnih podataka', 'neither'],
	// must read NEITHER: "svoj-" is person-neutral AND is the stem of the app's
	// own noun "svojstvo". Both readings appear here.
	['Svojstvo', 'neither'],
	['Svojstva Objekta', 'neither'],
	['Analiziraj postojeća Svojstva', 'neither'],
	['Uredi svoju Šemu', 'neither'],
	// must read NEITHER: bare "ti" is the masculine nominative plural of "taj".
	['Ti Objekti su obrisani', 'neither'],
	['Ti zapisi nisu pronađeni', 'neither'],
	// must read NEITHER: bare "si" is the reflexive dative clitic, not 2sg "biti".
	['Pregled si možete prilagoditi', 'formal'],
	// must read NEITHER: hyphenated loanword declension. These case endings are
	// what the header's hyphen note is about; none may score as a marker.
	['Naziv webhook-a', 'neither'],
	['Zapisi isporuke webhook-a', 'neither'],
	['Generiši VAPID-om potpisan ključ', 'neither'],
	['URL-ovi za povratni poziv', 'neither'],
	// mixed sanity: one informal marker inside otherwise neutral prose still wins
	['Nema Šema. Kreiraj prvu Šemu za tvoj Registar.', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls: the bare 2sg imperative is both the correct Bosnian button
// convention and a homograph of the 3sg present indicative, so counting it would
// flag every button in the app.
const UNDETECTABLE = [
	['Pokušaj ponovo', 'bare 2sg imperative — correct button style, homograph of 3sg present'],
	['Unesi svoju lozinku', '"svoju" is person-neutral, so only the bare imperative signals 2sg'],
	['Odaberi Registar', 'same: "odaberi" is also the 3sg present of "odabrati"'],
	['Sačuvaj izmjene', 'the app\'s own Save label; indistinguishable from a 2sg address'],
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

	const path = require('path')
	const fs = require('fs')
	const {
		scanCoreRegister, loadJsTranslations, APP_ROOT, isIdentical,
	} = require('../lib.js')

	// Core exists but is one thin catalogue, so it is REPORTED and then set aside
	// rather than trusted. Printing its marker count is the point: a verdict from
	// 0 markers over 44 values is what §2.3 tells you not to record.
	let core = null
	try {
		core = scanCoreRegister('bs', score)
		console.log(`\ncore bs: ${core.files.length} catalogue(s), ${core.values} value(s), `
			+ `${core.formal} formal / ${core.informal} informal marker(s)`)
		console.log('core is NOT the evidence for this locale — too thin to decide anything. '
			+ 'Falling back to §6.4.')
	} catch (e) {
		console.log(`\nno core bs catalogues: ${e.message.split('.')[0]}`)
	}

	// §6.4 fallback: this bundle plus the sibling apps' FRONTEND bs.js. The
	// backend bs.json is excluded — separate catalogue, separate consumer (§1) —
	// and that exclusion is load-bearing here, because openbuild ships a Croatian
	// catalogue under bs.json.
	const corpus = []
	const own = loadJsTranslations(path.join(APP_ROOT, 'l10n', 'bs.js')).translations
	for (const [k, v] of Object.entries(own)) {
		// A value byte-equal to its key is untranslated English and cannot carry
		// Bosnian register; counting it would dilute both totals.
		if (isIdentical(k, v)) continue
		for (const x of [].concat(v)) if (typeof x === 'string' && x) corpus.push(['own', x])
	}
	const appsDir = path.resolve(APP_ROOT, '..')
	for (const a of fs.readdirSync(appsDir).sort()) {
		if (a === path.basename(APP_ROOT)) continue
		const f = path.join(appsDir, a, 'l10n', 'bs.js')
		if (!fs.existsSync(f)) continue
		try {
			for (const v of Object.values(loadJsTranslations(f).translations || {})) {
				for (const x of [].concat(v)) if (typeof x === 'string' && x) corpus.push([a, x])
			}
		} catch { /* a sibling with an unparseable bundle is not this pass's problem */ }
	}

	const per = new Map()
	let formal = 0
	let informal = 0
	const hits = []
	for (const [app, x] of corpus) {
		const s = score(x)
		formal += s.f
		informal += s.i
		if (!per.has(app)) per.set(app, { f: 0, i: 0, n: 0 })
		const p = per.get(app)
		p.f += s.f
		p.i += s.i
		p.n++
		if (s.i > 0) hits.push(x.slice(0, 100))
	}
	console.log(`\nscanned ${corpus.length} frontend value(s) (this bundle + sibling apps)`)
	for (const [app, p] of per) {
		console.log(`  ${app.padEnd(16)} ${String(p.n).padStart(5)} value(s)  `
			+ `${p.f} formal / ${p.i} informal`)
	}
	console.log(`formal (Vi / vaš) markers:   ${formal}`)
	console.log(`informal (ti / tvoj) markers: ${informal}`)
	console.log(`verdict: ${formal > informal * 3 ? 'FORMAL' : informal > formal * 3 ? 'INFORMAL' : 'MIXED — inspect'}`)
	for (const v of hits.slice(0, 20)) console.log(`  informal? ${v}`)
	console.log('\nread this as: Bosnian HAS a T-V distinction and this register uses the')
	console.log('V-form — the hr case, not the mt/ga case. The load-bearing figure is the')
	console.log(`INFORMAL count: it must be 0, and is ${informal}.`)
}
