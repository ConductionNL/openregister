 
// Luxembourgish (Lëtzebuergesch) register detector for openregister l10n.
//
// CORE IS EFFECTIVELY EMPTY FOR THIS LOCALE. Nextcloud ships exactly ONE lb
// catalogue in the scanned roots (server/lib/l10n/lb.json, 72 values), which is
// no better than nothing for a register measurement — it contains not a single
// address form. `coreCatalogues('lb')` therefore does NOT throw the way it does
// for rm and mt, which makes this the more dangerous shape: §5 step 2 appears to
// run and would report a verdict computed from zero markers. So this detector
// follows rm.js in scanning the BUNDLE instead, and additionally widens the
// corpus to the sibling apps' FRONTEND bundles the way the mt pass did — 1054
// own values plus 2386 sibling values, 3440 in total. Counts in locales/lb.json.
//
// VERDICT: FORMAL, decisively. 161 polite markers against 9 informal ones, and
// the 9 are four values in ONE sibling app (launchpad); openregister's own 1054
// values carry zero. Luxembourgish uses the 2pl `Dir` / `Iech` / `Ären` as the
// polite address, on the German `Sie` model but built from the plural — so the
// V-form here is live and ordinary, not archaic (contrast is) and not merely
// available-but-unused (contrast mt).
//
// ============================================================================
// fold() DOES NOT LOWERCASE, AND THAT IS THE WHOLE DESIGN. This is the first
// detector in the set where case is the ONLY thing separating the two
// polarities, so the usual `String(s).toLowerCase()` would invert the verdict on
// the commonest address word in the language:
//
//     dir  (lowercase) = the INFORMAL 2sg DATIVE — "to you", familiar
//     Dir  (capital)   = the POLITE 2pl NOMINATIVE — the V-form
//
// Both are attested here. "Du kanns nëmmen Dashboards bäifügen, déi dir
// gehéieren" is the informal dative; "Sidd Dir sécher…" is the polite nominative,
// 82 times. A case-folding detector counts all 83 as one bucket. This is the
// da/nb `De`/`Dem`/`Deres` situation (§8.2) with a sharper edge, because there
// the collision is with a third-person pronoun rather than with the same
// paradigm's own familiar form.
//
// The residual blind spot is honest and recorded in UNDETECTABLE: a value that
// OPENS with the informal dative gets a sentence-initial capital and is then
// indistinguishable from the polite nominative. All 11 sentence-initial `Dir` in
// the corpus are polite (each followed by a 2pl verb — `hutt`, `musst`,
// `braucht`, `kënnt`), so the cost is theoretical here, but it is real.
//
// ============================================================================
// WHY CLOSED WORD LISTS, SPECIFICALLY FOR LUXEMBOURGISH (§8.1):
//
//   • `-s` is the 2sg present ending AND the genitive/plural marker AND the
//     ending of ordinary vocabulary. A `-s` rule is unusable.
//   • THE MODALS SYNCRETISE 1sg/2sg/3sg, so the specific forms `muss` and
//     `weess` carry NO address information: "du muss" and "hie muss" are
//     spelled identically. This is measured, not assumed — all 15 occurrences of
//     bare `muss` in the corpus are 3sg ("De Slug muss…", "D'Tabell muss…",
//     "LLM muss…"), not one is 2sg. Both are excluded, while the regularly
//     inflected 2sg of the SAME verbs (`kanns`, `wëlls`, `sollst`) is kept —
//     so this is a PARTIALLY-detectable paradigm in the bg/is sense, split by
//     lexical class (the modals) rather than by conjugation class.
//   • `-t` is the 2pl ending (a formal marker) AND the 3sg present ending. Two
//     of the most useful-looking 2pl forms are therefore excluded: `kënnt` is
//     "you (pl) can" AND "he comes" (3sg of kommen), and `braucht` is "you (pl)
//     need" AND "he needs" (3sg of brauchen). The 2pl forms kept below are the
//     ones whose 3sg is spelled differently: hutt/huet, sidd/ass, musst/muss,
//     gitt/gëtt, maacht/mécht.
//   • THE 2SG IMPERATIVE IS EXCLUDED WHOLESALE. §6.5 test 1 comes out NO here —
//     labels are infinitives (§7.3), so counting it would not flag every button,
//     which is what forces the exclusion in ca/et/hr/sl/sr/ga/mt. It is
//     excluded on test 2 instead: the Luxembourgish 2sg imperative is the bare
//     stem, and for the productive stems that is also an ordinary noun
//     (`Späicher` = "storage/loft", `Filter`, `Test`). Cheap to give up: every
//     informal slip actually shipped in this app family is a 2sg INDICATIVE
//     (`Du hues`, `Du kanns`), never an imperative.
//
// JS \b is ASCII-only and would treat `ë`/`é`/`ä` as boundaries, so every guard
// is (?<!\p{L}) … (?!\p{L}) with the u flag. Two controls below exist purely to
// pin that: `direkt` contains `dir`, and `Duerchschnëttlech` opens with `Du`.

// Whitespace normalisation only. See the header: lowercasing would destroy the
// dir/Dir distinction, which is the one signal this locale actually turns on.
/**
 *
 * @param s
 */
function fold(s) {
	return String(s).replace(/\s+/g, ' ')
}

// Dir / Iech / Ären — the polite 2pl, and the correct register for this bundle.
const FORMAL_RES = [
	// CASE-SENSITIVE by necessity. Capitalised `Dir` is the polite nominative;
	// lowercase `dir` is the informal dative and is matched as informal below.
	/(?<!\p{L})Dir(?!\p{L})/gu,
	// `Iech` (polite acc/dat) and the `Är-` possessive family. No collisions in
	// Luxembourgish: neither has a second reading. Ordered longest-first is
	// unnecessary because of the trailing guard, but the family is written out in
	// full rather than as `Är\p{L}*` so a new form cannot be absorbed silently.
	/(?<!\p{L})(?:Iech|Ären|Ärem|Ärer|Äert|Äre|Är)(?!\p{L})/gu,
	// The SAME polite possessive written lowercase. Polite in force, defective in
	// orthography — Luxembourgish capitalises the V-form possessive, and the
	// sibling bundles do so 50:0. All 11 occurrences are in openregister's own
	// pre-existing half and are corrected by this pass (locales/lb.json
	// `corrections`, class CAPITAL-POLITE). Counted FORMAL because the register
	// reading is not in doubt; kept in the list so the detector stays correct
	// against any bundle that still carries them.
	/(?<!\p{L})(?:ären|ärem|ärer|äert|äre)(?!\p{L})/gu,
	// Closed list of 2pl present-indicative forms, restricted to those whose 3sg
	// is spelled differently. NOT a `-t` rule: see the header for why `kënnt` and
	// `braucht` are absent.
	/(?<!\p{L})(?:hutt|sidd|musst|wëllt|gitt|maacht|waart|kritt|hätt)(?!\p{L})/gu,
]

// du / dech / deng — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	// Bare `du` IS usable in Luxembourgish — the same useful negative as bg, rm,
	// ga, mt and is. There is no demonstrative or copular reading to collide
	// with, so do not port the "leave the bare pronoun unmatched" rule from
	// cs/hr/sl. Case-insensitive: `Du` and `du` are both informal, unlike dir/Dir.
	/(?<!\p{L})(?:du|dech|däin|däi|deng|denger|dengem|dengen|dengt)(?!\p{L})/giu,
	// CASE-SENSITIVE, lowercase only: the informal 2sg dative. A capitalised
	// `Dir` is the polite nominative and is matched as FORMAL above.
	/(?<!\p{L})dir(?!\p{L})/gu,
	// Closed list of 2sg present-indicative forms. `muss` and `weess` are
	// deliberately ABSENT — the modals syncretise 1sg/2sg/3sg and all 15 corpus
	// occurrences of `muss` are 3sg. `bass` is included but see UNDETECTABLE:
	// it is also the noun "bass", which does not occur in this domain.
	/(?<!\p{L})(?:hues|bass|kanns|wëlls|gees|kriss|sollst|däerfs|hätts|wäers|géngs)(?!\p{L})/giu,
]

const CONTROLS = [
	// ---- must read FORMAL. Every one is a real value from this app family.
	['Sidd Dir sécher, datt Dir al Sich-Trails opraume wëllt?', 'formal'],
	['Sidd Dir sécher, datt Dir déi ausgewielte Sich-Trails läsche wëllt? Dës Aktioun kann net réckgängeg gemaach ginn.', 'formal'],
	['Sidd Dir sécher, datt Dir definitiv läsche wëllt', 'formal'],
	['Wëllt Dir all gefiltert Audit-Trail-Andréi definitiv läschen? Dës Aktioun kann net réckgängeg gemaach ginn.', 'formal'],
	["Keng Usiichte fonnt. Erstellt fir d'éischt Usiichte ier Dir d'Vektoriséierung konfiguréiert.", 'formal'],
	['E Repository auswielen op deen Dir Schreifzougang hutt', 'formal'],
	['Dir musst ageloggt si fir Usiichten als Favoritt ze markéieren', 'formal'],
	['Dir braucht e KI-Agent fir e Gespréich unzefänken.', 'formal'],
	["Hei kënnt Dir d'Detailer fir Är Organisatioun festleeën.", 'formal'],
	['Dir kënnt nëmmen Är eege Dashboards als Schablounen späicheren', 'formal'],
	['Dat bedeit entweder, datt Open Register op dësem Server net verfügbar ass, oder datt Dir Äert Passwuert bestätege musst', 'formal'],
	['Ze vill verfeelte Versich. Wann ech glift waart, ier Dir et nach eemol probéiert.', 'formal'],
	['Méiglecherweis beaarbecht Dir an engem aneren Tab', 'formal'],
	['Dir hutt kee Zougang zu dësem Dossier.', 'formal'],
	['Wiesselt séier dohinner, wou Dir musst sinn', 'formal'],
	["Duerchsicht all Widget, deen Dir op en Dashboard derbäifüge kënnt, no Kategorie gruppéiert.", 'formal'],
	// The lowercase polite possessive: still FORMAL for register purposes, and
	// separately a capitalisation defect this pass corrects.
	['Et gi keng Audit-Trail-Andréi déi ären aktuelle Filteren entspriechen.', 'formal'],
	['Mëll geläscht Artikelen aus äre Registere verwalten a restauréieren', 'formal'],
	['Erweidert Filtere mat Live-Donnéeën aus ärem Sichindex lueden', 'formal'],
	['Keng Usiichte entspriechen ärer Sich', 'formal'],
	['Vektore ginn an ären existéierenden Objet- an Datei-Sammlunge gespäichert', 'formal'],

	// ---- must read INFORMAL. The first four are the real slips shipped in
	// launchpad's lb bundle; the rest are constructed but valid Luxembourgish,
	// because openregister's own bundle contains no informal value to draw on.
	["Du hues d'Limitt vun {limit} Dashboards erreecht", 'informal'],
	["Du hues d'Limitt vun {limit} Widgets op dësem Dashboard erreecht", 'informal'],
	// carries BOTH `Du kanns` and the lowercase informal dative `dir`
	['Du kanns nëmmen Dashboards bäifügen, déi dir gehéieren', 'informal'],
	["Du hues nach keng vermëttelt Zougangsdaten. Erstell fir d'éischt eng an OpenRegister.", 'informal'],
	['Deng Registere', 'informal'],
	['Däin Schema ass gespäichert', 'informal'],
	['Du bass ageloggt', 'informal'],
	['Wann du wëlls, probéier nach eemol', 'informal'],
	['Dëst beaflosst deng Donnéeën', 'informal'],
	["Du gees op d'Astellungen", 'informal'],
	['Du kriss eng Notifikatioun', 'informal'],
	["Du sollst d'Ännerunge späicheren", 'informal'],
	// the lowercase dative on its own, mid-sentence — no `du` to help
	['Mir schécken dir eng Mail', 'informal'],
	['Dat gehéiert dech net', 'informal'],

	// ---- must read NEITHER: the INFINITIVE is the correct Luxembourgish label
	// convention (§7.3, measured 64:0), so no button may score as either register.
	['Späicheren', 'neither'],
	['Läschen', 'neither'],
	['Ofbriechen', 'neither'],
	['Beaarbechten', 'neither'],
	['Aktualiséieren', 'neither'],
	['Ewechhuelen', 'neither'],
	['Verëffentlechen', 'neither'],
	['Objete läschen', 'neither'],
	['Audit-Trails exportéieren, ukucken oder läschen', 'neither'],
	['Gréissten berechnen', 'neither'],
	['Zréck', 'neither'],
	['Weider', 'neither'],

	// ---- must read NEITHER: the MODAL SYNCRETISM trap. Every one is a real
	// value, and every one is 3sg. This is the single most important trap here:
	// counting `muss` as 2sg would score 15 ordinary third-person statements as
	// informal and put the verdict in doubt.
	['De Slug muss ënner de Geschwësteren eenzegaarteg sinn', 'neither'],
	['LLM muss aktivéiert sinn mat engem konfiguréierten Embedding-Provider', 'neither'],
	["D'Tabell muss op d'mannst eng Zeil hunn", 'neither'],
	['publishAt muss en zukünftegen Zäitstempel sinn', 'neither'],
	['Entweder userId oder groupId muss ugi ginn', 'neither'],
	["D'Feed-URL muss mat http:// oder https:// ufänken", 'neither'],
	['OpenRegister muss installéiert sinn, fir d\'Schema ze validéieren.', 'neither'],

	// ---- must read NEITHER: the 3sg `-t` forms deliberately kept out of the
	// formal list, so that ordinary third-person prose scores nothing.
	['Et braucht e Schema fir dës Operatioun', 'neither'],
	['Den Job kënnt all fënnef Minutten', 'neither'],

	// ---- must read NEITHER: the \p{L} guards. `direkt` contains `dir` and would
	// score INFORMAL under an ASCII \b; `Duerchschnëttlech` opens with `Du`.
	// Both are real values.
	['Generéiert direkt Vektoren wann nei Objeten erstallt ginn', 'neither'],
	['Duerchschnëttlech Objet-Usiichten/Sessioun', 'neither'],
	['Direkt Verbindung', 'neither'],
	['API-Dokumentatioun ukucken', 'neither'],
	['Dokumentatioun', 'neither'],
	['Duplikat', 'neither'],
	// `Är` must not match inside a longer word either
	['Ärekëscht', 'neither'],

	// ---- mixed sanity: one informal marker inside otherwise formal prose wins.
	['Sidd Dir sécher, mee du hues keng Rechter', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Dir gëtt eng Mail geschéckt', 'a value OPENING with the informal 2sg dative '
		+ 'takes a sentence-initial capital and is then spelled exactly like the '
		+ 'polite nominative. This is the one hole left by making case load-bearing, '
		+ 'and it is unavoidable: no orthographic feature distinguishes them in that '
		+ 'position. All 11 sentence-initial `Dir` in the 3440-value corpus are '
		+ 'polite (each followed by a 2pl verb), so the cost is currently theoretical'],
	['Späicher d\'Ännerungen', 'the bare 2sg imperative. Excluded wholesale because '
		+ 'for the productive stems it is also an ordinary noun — `Späicher` is '
		+ '"storage/loft", `Filter` and `Test` are nouns this bundle uses as labels. '
		+ 'Unlike ca/et/hr/sl/sr/ga/mt the exclusion is NOT forced by the label '
		+ 'convention (§6.5 test 1 comes out NO here — labels are infinitives); it is '
		+ 'forced by test 2 alone'],
	['Du muss dat späicheren', 'the 2sg of a MODAL. `muss` and `weess` are spelled '
		+ 'identically in the 1st, 2nd and 3rd person singular, so they carry no '
		+ 'address information at all. The regularly inflected 2sg of the same verbs '
		+ '(`kanns`, `wëlls`, `sollst`) IS detected, which is why this is a partial '
		+ 'exclusion rather than a whole paradigm'],
	['Dat ass eng Saach fir d\'Benotzer', 'a nominal sentence with no pronoun and no '
		+ 'finite verb carries no address marker in either direction. Luxembourgish '
		+ 'UI prose is heavily nominal, so this covers a large share of the bundle'],
	['Wann ech glift', 'the politeness formula ("please"). Checked for a free signal '
		+ 'the way mt\'s `jekk jogħġbok` provided one, and it does NOT have it: '
		+ '`wann ech glift` is literally "if I please" and inflects for the SPEAKER, '
		+ 'not the addressee, so it is register-neutral. Same negative as is '
		+ '`vinsamlegast` and ga `le do thoil`; 21 occurrences here, all useless. '
		+ 'Third of four locales checked to come out empty — keep checking, the '
		+ 'payoff when it lands is large'],
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

	const fs = require('fs')
	const path = require('path')
	const { coreCatalogues, loadJsTranslations, APP_ROOT } = require('../lib.js')

	// Core is not empty here (unlike rm/mt), it is merely useless — so re-check
	// what it actually contains rather than asserting it from this comment. If
	// core ever grows real lb coverage it OUTRANKS the bundle (§3.4) and this
	// verdict must be re-measured.
	let coreValues = 0
	let coreFiles = []
	try {
		coreFiles = coreCatalogues('lb')
		for (const f of coreFiles) {
			const j = JSON.parse(fs.readFileSync(f, 'utf8'))
			for (const v of Object.values(j.translations || {})) {
				for (const x of Array.isArray(v) ? v : [v]) if (typeof x === 'string' && x.trim()) coreValues++
			}
		}
	} catch (e) {
		console.log(`\nno core lb catalogues: ${e.message.split('.')[0]}`)
	}
	let coreF = 0
	let coreI = 0
	for (const f of coreFiles) {
		const j = JSON.parse(fs.readFileSync(f, 'utf8'))
		for (const v of Object.values(j.translations || {})) {
			for (const x of Array.isArray(v) ? v : [v]) {
				if (typeof x !== 'string' || !x.trim()) continue
				const s = score(x)
				coreF += s.f
				coreI += s.i
			}
		}
	}
	console.log(`\ncore: ${coreFiles.length} catalogue(s), ${coreValues} values, `
		+ `${coreF} formal / ${coreI} informal marker(s)`)
	if (coreValues > 500) {
		console.log('NOTE: core lb has grown past 500 values — re-measure the register '
			+ 'against core and update locales/lb.json; core outranks the bundle (§3.4).')
	} else {
		console.log('  -> too thin to decide anything; the §6.4 fallback below is the evidence.')
	}

	// The §6.4 fallback, widened to the sibling apps' FRONTEND bundles as the mt
	// pass did. Only .js — the backend .json is a separate catalogue with a
	// separate consumer (§1) and would be miscredited to the frontend.
	const APPS = path.resolve(APP_ROOT, '..')
	const scan = (file, label) => {
		const tr = loadJsTranslations(file).translations
		let formal = 0
		let informal = 0
		let values = 0
		const hits = []
		for (const [k, v] of Object.entries(tr)) {
			for (const x of Array.isArray(v) ? v : [v]) {
				// Values byte-equal to their key are untranslated English and cannot
				// carry Luxembourgish register; counting them would dilute both totals.
				if (typeof x !== 'string' || !x.trim() || (!Array.isArray(v) && x === k)) continue
				values++
				const s = score(x)
				formal += s.f
				informal += s.i
				if (s.i > 0) hits.push(x.slice(0, 100))
			}
		}
		console.log(`  ${label.padEnd(16)} ${String(values).padStart(5)} values  `
			+ `formal=${String(formal).padStart(4)}  informal=${String(informal).padStart(3)}`)
		return { formal, informal, values, hits }
	}

	console.log('\n=== §6.4 fallback: this app family\'s own frontend bundles ===')
	let F = 0
	let I = 0
	let V = 0
	const allHits = []
	for (const app of fs.readdirSync(APPS).sort()) {
		const file = path.join(APPS, app, 'l10n', 'lb.js')
		if (!fs.existsSync(file)) continue
		let r
		try { r = scan(file, app) } catch { continue }
		F += r.formal
		I += r.informal
		V += r.values
		for (const h of r.hits) allHits.push([app, h])
	}
	console.log(`  ${'TOTAL'.padEnd(16)} ${String(V).padStart(5)} values  `
		+ `formal=${String(F).padStart(4)}  informal=${String(I).padStart(3)}`)
	console.log(`verdict: ${F > I * 3 ? 'FORMAL' : I > F * 3 ? 'INFORMAL' : 'MIXED — inspect'}`)
	for (const [app, h] of allHits) console.log(`  informal? [${app}] ${h}`)
}
