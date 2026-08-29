/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Maltese (Malti) register detector for openregister l10n.
//
// THERE IS NO CORE EVIDENCE FOR THIS LOCALE. Nextcloud ships ZERO mt catalogues
// — not core/l10n, not lib/l10n, not any bundled app — so scanCoreRegister('mt')
// throws by design rather than computing a verdict from nothing. This is the
// second such locale after rm, and the runbook's §6.4 fallback applies: the
// verdict comes from the app's own values plus the sibling apps' frontend mt.js.
// Measured 93 second-person SINGULAR markers against ZERO plural and ZERO
// deferential-third-person, over 3422 values. Counts in locales/mt.json.
//
// MALTESE IS NOT THE ga CASE, and conflating the two would be a real error.
// Irish has no T-V distinction at all, so its "informal" names the only address
// form available. Maltese genuinely HAS a politeness system — `intom` serves as
// a polite singular the way French `vous` does, and `Is-Sinjur` / `Is-Sinjura`
// with third-person agreement is the deferential register. Both exist, and both
// are simply unused in this software register. So `informal` here records a real
// measured choice, as it does for nl/de/et/lv, and the deviation this gate looks
// for is genuine deference rather than an impossible form.
//
// TWO WHOLE PARADIGMS ARE UNUSABLE, both ordinary Maltese morphology:
//
//   • THE t- PREFIX. The 2sg imperfect and the 3sg FEMININE imperfect are
//     spelled identically across the entire verb system, so `tista'` is at once
//     "you can" and "she/it can" — and both readings are live in this bundle:
//     "Hawn tista' tara jekk dik il-katina hijiex sħiħa" (you) against
//     "Il-Proprjetà tista' tittejjeb", "Din l-analiżi tista' tieħu ftit ħin" and
//     "Qabel ma tista' taħdem il-vettorizzazzjoni" (3sg f). 23 occurrences, split
//     both ways. `trid` ("you want" / "she wants") shares the paradigm and adds
//     24 more. This is the Latvian shape — systematic, not exceptional — so the
//     whole class is excluded, and that costs real recall.
//
//   • THE -u ENDING. The 2pl imperative and 2pl imperfect both end in -u, and so
//     does the 3pl of everything. `nstabu` ("they were found") occurs 24 times in
//     this bundle alone, in "Ma nstabu l-ebda X"; `għandhom` 19 times;
//     `jappartjenu` and `jistgħu` likewise. Counting -u forms would score the
//     commonest sentence shape in the file as deference. So 2pl IMPERATIVES are
//     undetectable here, and the formal side rests entirely on the pronoun, the
//     possessive, the -kom prepositional pronouns and Sinjur — all unambiguous.
//
// Why closed word lists and NOT suffix patterns, specifically for Maltese:
//   • `-kom` looks like the 2pl object/possessive ending, and mostly is. But it
//     is also the ending of ordinary words, and more to the point a suffix rule
//     would sweep in any noun happening to end that way. The thirteen real
//     prepositional pronouns are enumerable, so they are enumerated.
//   • `-ek` / `-k` looks like the 2sg possessive ending. It is also the tail of
//     a large number of unrelated words, and Maltese `-k` attaches to
//     prepositions rather than freely, so again the paradigm is closed and small.
//   • `t-` and `-u`: see above. Both fatal.
//
// DIACRITICS ARE NOT FOLDED. Maltese uses ċ ġ ħ ż plus the digraph `għ`, and
// `għ` sits inside the single most productive marker here — `tiegħek`. fold()
// only lowercases; that matters because the first probe run of this pass omitted
// case folding and scored 0 for the pronoun, missing capitalised `Inti ċert li
// trid…`, which is a real value.
//
// JS \b is ASCII-only and would treat ħ/ġ/ż/ċ as boundaries, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag. Maltese also hyphenates its assimilated
// definite article (il- it- is- ir- id- in- iċ- iż- ix- l-), giving `it-token`
// and `tal-Awditjar`; the hyphen is a non-letter, so the guards match the stem
// correctly with no special handling.

function fold(s) {
	return String(s).toLowerCase()
}

// intom / Sinjur — THE DEVIATION this gate exists to catch. Zero occurrences in
// 3422 real mt values, so any hit is a defect rather than a minority style.
const FORMAL_RES = [
	// 2pl pronoun, used as the polite singular. No other reading in Maltese.
	/(?<!\p{L})(?:intom|intkom)(?!\p{L})/gu,
	// 2pl possessive. Distinct from 2sg tiegħek and from 3pl tagħhom.
	/(?<!\p{L})(?:tagħkom|tiegħkom)(?!\p{L})/gu,
	// Closed list of 2pl prepositional pronouns. NOT a "-kom" suffix rule.
	/(?<!\p{L})(?:lilkom|magħkom|għalikom|fikom|minnkom|bikom|bejnkom|taħtkom|warajkom|quddiemkom|dwarkom|lejkom|fuqkom|bħalkom)(?!\p{L})/gu,
	// The politeness formula with a 2pl object suffix — `jekk jogħġobkom`. Its
	// 2sg counterpart is the commonest address marker in this bundle after the
	// possessive (35 uses), so the plural is exactly the slip to catch here.
	/(?<!\p{L})(?:jogħġobkom|jogħġbkom)(?!\p{L})/gu,
	// Deferential third-person address, the very formal Maltese register.
	/(?<!\p{L})(?:sinjur|sinjura|sinjurin|sinjuri)(?!\p{L})/gu,
]

// int / inti — the CORRECT register for this bundle. Counted so the verdict rests
// on evidence; not gated on.
const INFORMAL_RES = [
	// 2sg pronoun, both gender variants. `int` has no competing reading in
	// Maltese, so unlike cs `ty` or hr `ti` the bare pronoun IS usable.
	/(?<!\p{L})(?:int|inti|intik)(?!\p{L})/gu,
	// 2sg possessive — the single most productive marker here, 75 of the 93 hits.
	// Note the `għ`: folding diacritics would destroy it.
	/(?<!\p{L})(?:tiegħek|tagħek)(?!\p{L})/gu,
	// Closed list of 2sg prepositional pronouns. Three occur in the corpus
	// (lilek, miegħek and the possessive above); the rest are the same closed
	// paradigm and cost nothing.
	/(?<!\p{L})(?:lilek|miegħek|għalik|fik|minnek|bik|bejnek|taħtek|warajk|quddiemek|dwarek|lejk|magħk|bħalek|fuqek)(?!\p{L})/gu,
	// `jekk jogħġbok` — "please". The politeness formula carries a 2sg object
	// suffix, which makes it a genuine address marker and not just a courtesy
	// word, and it is the second commonest marker in this bundle: 35 uses against
	// zero for the 2pl `jogħġobkom`. Enumerated rather than reached by a `-ok`
	// suffix rule, because 2sg object suffixes attach to an open set of verbs.
	/(?<!\p{L})(?:jogħġbok|jogħġobok)(?!\p{L})/gu,
]

const CONTROLS = [
	// ---- must read INFORMAL (2sg). All real values from this bundle or a
	// sibling app's frontend mt.js. This is the CORRECT register.
	['Inti ċert li trid tħassar permanentement', 'informal'],
	['Inti ċert li trid tnaddaf it-traċċi tat-tfittxija qodma?', 'informal'],
	['Inti ċert li trid tħassar it-traċċi tat-tfittxija magħżula?', 'informal'],
	['Uża l-filtri hawn taħt biex tirfina t-tfittxija tiegħek.', 'informal'],
	['Tella\' l-filtri avvanzati b\'data live mill-indiċi tat-tfittxija tiegħek', 'informal'],
	['Amministra u rrestawra l-Oġġetti soft-imħassra mir-Reġistri tiegħek', 'informal'],
	['Amministra l-applikazzjonijiet u l-moduli tiegħek', 'informal'],
	['Ħalli vojt biex tipproċessa l-vedute kollha bbażat fuq il-konfigurazzjoni tiegħek.', 'informal'],
	['Int ħloqt id-dashboard {dashboard}', 'informal'],
	['Int aġġornajt id-dashboard {dashboard}', 'informal'],
	['Tista\' żżid biss dashboards li jappartjenu lilek', 'informal'],
	['%1$s ikkondividiet **%2$s** miegħek', 'informal'],
	['Il-grupp primarju tiegħek għad-dashboards kondiviżi', 'informal'],
	// the marker must still be found when it is not the first word
	['Ikkuntattja lill-amministratur tiegħek', 'informal'],
	// `jekk jogħġbok` — 35 real uses, the second commonest marker here
	['Jekk jogħġbok erġa\' pprova aktar tard.', 'informal'],
	['Jekk jogħġbok oħloq aġent fil-', 'informal'],
	['Jekk jogħġbok stenna waqt li nġibu l-konfigurazzjonijiet tiegħek.', 'informal'],
	['Jekk jogħġbok agħżel liema Reġistru u skema għandhom jintużaw', 'informal'],

	// ---- must read FORMAL — the deviation. This bundle and its siblings contain
	// NO 2pl or deferential address at all, so every one of these is constructed.
	// Each is valid Maltese and is the shape a translator would produce by
	// importing a politeness plural from fr/de/nl.
	['Intom ċerti li tridu tħassru permanentement?', 'formal'],
	['Il-Reġistri tagħkom', 'formal'],
	['Uża l-filtri hawn taħt biex tirfina t-tfittxija tagħkom.', 'formal'],
	['Ma nistgħux nikkuntattjawkom, agħtuna l-email tagħkom', 'formal'],
	['Dan id-dashboard jappartjeni lilkom', 'formal'],
	['%1$s ikkondividiet **%2$s** magħkom', 'formal'],
	['Il-konfigurazzjoni tinsab fis-settings tagħkom', 'formal'],
	['Jekk jogħġobkom agħżlu Reġistru u Skema', 'formal'],
	['Ma nstabet l-ebda data għalikom', 'formal'],
	['L-Oġġetti ġew imħassra minnkom', 'formal'],
	['Is-Sinjur jista\' jara t-traċċi tal-awditjar', 'formal'],
	['Is-Sinjura tista\' tħassar dan l-Oġġett', 'formal'],
	['Nirringrazzjaw lis-Sinjuri għall-paċenzja tagħkom', 'formal'],

	// ---- must read NEITHER: the BARE 2SG IMPERATIVE is the label convention,
	// so no button may score as either register. All real values.
	['Issejvja', 'neither'],
	['Ħassar', 'neither'],
	['Ikkanċella', 'neither'],
	['Editja', 'neither'],
	['Oħloq', 'neither'],
	['Neħħi', 'neither'],
	['Agħlaq', 'neither'],
	['Irrestawra', 'neither'],
	['Ivverifika l-katina', 'neither'],
	['Ittestja l-Konnessjoni', 'neither'],
	['Agħżel Kollha', 'neither'],
	['Uri l-Filtri', 'neither'],
	['Aħbi l-Filtri', 'neither'],
	['Neħħi l-filtri kollha', 'neither'],
	['Ħassar Permanentement', 'neither'],
	['Ibda l-Vettorizzazzjoni', 'neither'],

	// ---- must read NEITHER: the t- PREFIX trap. Each of these is 3sg FEMININE
	// in a real value, spelled exactly like the 2sg. This is why the paradigm is
	// excluded, and it is the reason the informal count is lower than the
	// language actually supports.
	['Qabel ma tista\' taħdem il-vettorizzazzjoni tal-Oġġetti:', 'neither'],
	['Il-Proprjetà tista\' tittejjeb', 'neither'],
	['Din l-analiżi tista\' tieħu ftit ħin', 'neither'],
	['Din l-azzjoni ma tistax tiġi annullata.', 'neither'],
	['Qed tistenna siġill', 'neither'],
	['Ma setgħetx tinqara l-kopertura tas-siġilli', 'neither'],

	// ---- must read NEITHER: the -u ENDING trap. Every one is 3pl in a real
	// value, spelled exactly like a 2pl imperative would be. Without this
	// exclusion the commonest sentence shape in the file would score formal.
	['Ma nstabu l-ebda Oġġetti', 'neither'],
	['Ma nstabu l-ebda Reġistri', 'neither'],
	['Ma nstabu l-ebda logs', 'neither'],
	['Agħżel liema vedute għandhom jiġu inklużi fil-proċess ta\' vettorizzazzjoni.', 'neither'],
	['Kif għandhom jiġu mmaniġġjati l-provi għal kunsinni li fallew', 'neither'],
	['L-oġġetti jistgħu jingħaqdu biss jekk jappartjenu lill-istess reġistru u skema.', 'neither'],
	['L-għadd massimu ta\' Fajls li għandhom jiġu proċessati.', 'neither'],

	// ---- must read NEITHER: the impersonal / passive shapes this bundle uses
	// for most descriptive prose. They address nobody and carry no marker in
	// either direction — the commonest shape in the file.
	['It-traċċa tal-awditjar tħassret b\'suċċess', 'neither'],
	['Il-konfigurazzjoni ġiet issejvjata', 'neither'],
	['Qed jitilgħa...', 'neither'],
	['Kull entrata hija ssiġillata', 'neither'],
	['Naqas milli jissejvja s-settings', 'neither'],

	// ---- must read NEITHER: 3sg and 3pl possessives, one paradigm slot away
	// from the markers above. tagħhom is 3pl "their", NOT 2pl "your".
	['il-hashes maħżuna għadhom jaqblu mar-ringieli tagħhom', 'neither'],
	['Ikkonfigura l-Reġistri tad-data u l-konfigurazzjonijiet tagħhom', 'neither'],

	// ---- mixed sanity: one deferential marker inside otherwise correct prose
	// wins, because that is the slip the gate has to catch.
	['Uża l-filtri hawn taħt, imma l-Oġġetti tagħkom jibqgħu moħbija', 'formal'],
]

// Register information this detector CANNOT see, and why. Recorded rather than
// left as failing controls.
const UNDETECTABLE = [
	['Tista\' tara t-traċċi kollha', 'the 2sg imperfect. Spelled identically to the '
		+ '3sg FEMININE imperfect across the whole verb system, and both readings are '
		+ 'live in this bundle — "Hawn tista\' tara" is 2sg while "Il-Proprjetà tista\' '
		+ 'tittejjeb" is 3sg f. 23 occurrences of tista\' alone, split both ways. This '
		+ 'is the largest recall loss here'],
	['Jekk trid tħassar dan l-Oġġett', 'same paradigm, and it costs more than it looks: '
		+ 'trid ("you want" / "she wants") occurs 24 times and most of those ARE genuine '
		+ '2sg address, but nothing distinguishes them from the 3sg reading, so none can '
		+ 'be counted. Note the polarity — the loss is on the CORRECT-register side, so '
		+ 'it thins the evidence without ever producing a false formal hit'],
	['Tħassru l-Oġġetti kollha', 'the 2pl IMPERATIVE, which would be a formal marker '
		+ 'and cannot be detected: -u is also the 3pl of everything. `nstabu` ("they '
		+ 'were found") occurs 24 times in this bundle in "Ma nstabu l-ebda X", and '
		+ '`għandhom` 19 times. So the formal side rests on the pronoun, the possessive, '
		+ 'the -kom prepositional pronouns and Sinjur — narrow, but unambiguous'],
	['Ħassart l-Oġġett', 'the 2sg perfect ends in -t, which is also the 1sg perfect. '
		+ 'The two differ only by an internal vowel for most verbs (ħlaqt "I created" '
		+ 'against ħloqt "you created"), far too fine for a word list. The attested 2sg '
		+ 'perfects all carry the pronoun Int and are caught that way'],
	['Ġie mħassar l-Oġġett', 'the impersonal and passive shapes address nobody at all, '
		+ 'and most of this bundle is written that way. A low informal count is '
		+ 'therefore NOT evidence of a register problem — the assertion that matters is '
		+ 'that the formal count is zero'],
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
		// Formal wins ties, as in ga: the deviation being gated on is the
		// deferential form, so a value carrying both must be reported as the
		// defect rather than excused by the singular.
		const got = f > 0 ? 'formal' : i > 0 ? 'informal' : 'neither'
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

	// No core scan is possible: Nextcloud ships no mt catalogues at all, so
	// scanCoreRegister would throw. Confirm that is STILL true rather than
	// asserting it from a comment, then measure this bundle plus the sibling
	// apps' frontend mt.js — the §6.4 fallback recorded in locales/mt.json.
	const path = require('path')
	const fs = require('fs')
	const { coreCatalogues, loadJsTranslations, APP_ROOT, isIdentical } = require('../lib.js')
	let core = null
	try {
		core = coreCatalogues('mt')
	} catch (e) {
		console.log(`\nno core mt catalogues: ${e.message.split('.')[0]}`)
	}
	if (core) {
		console.log(`\nNOTE: ${core.length} core mt catalogue(s) exist now — core was `
			+ 'empty when this detector was written. Re-measure the register against '
			+ 'core and update locales/mt.json; core outranks the bundle (§3.4).')
	}

	const corpus = []
	const own = loadJsTranslations(path.join(APP_ROOT, 'l10n', 'mt.js')).translations
	for (const [k, v] of Object.entries(own)) {
		// Values byte-equal to their key are untranslated English and cannot carry
		// Maltese register; counting them would dilute both totals.
		if (isIdentical(k, v)) continue
		for (const x of [].concat(v)) if (typeof x === 'string' && x) corpus.push(['own', x])
	}
	// Sibling apps' FRONTEND mt.js only. The backend mt.json is a separate
	// catalogue with a separate consumer (§1) and is deliberately excluded — it
	// does contain an `int`, which would otherwise leak into this measurement.
	const appsDir = path.resolve(APP_ROOT, '..')
	for (const a of fs.readdirSync(appsDir).sort()) {
		if (a === path.basename(APP_ROOT)) continue
		const f = path.join(appsDir, a, 'l10n', 'mt.js')
		if (!fs.existsSync(f)) continue
		try {
			for (const v of Object.values(loadJsTranslations(f).translations || {})) {
				for (const x of [].concat(v)) if (typeof x === 'string' && x) corpus.push([a, x])
			}
		} catch { /* a sibling with an unparseable bundle is not this pass's problem */ }
	}

	let formal = 0
	let informal = 0
	const hits = []
	for (const [, x] of corpus) {
		const s = score(x)
		formal += s.f
		informal += s.i
		if (s.f > 0) hits.push(x.slice(0, 100))
	}
	console.log(`\nscanned ${corpus.length} frontend value(s) (this bundle + sibling apps)`)
	console.log(`formal (intom / Sinjur) markers: ${formal}`)
	console.log(`informal (int / tiegħek) markers: ${informal}`)
	console.log(`verdict: ${formal > informal * 3 ? 'FORMAL' : informal > formal * 3 ? 'INFORMAL' : 'MIXED — inspect'}`)
	for (const v of hits.slice(0, 20)) console.log(`  formal? ${v}`)
	console.log('\nread this as: Maltese HAS a politeness system (intom, Is-Sinjur) and this')
	console.log('software register simply does not use it — unlike ga, where no T-V distinction')
	console.log(`exists at all. The load-bearing figure is the FORMAL count: it must be 0, and is ${formal}.`)
}
