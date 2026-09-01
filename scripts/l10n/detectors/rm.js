/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Romansh (Rumantsch Grischun) register detector for openregister l10n.
//
// THERE IS NO CORE EVIDENCE FOR THIS LOCALE AT ALL. Nextcloud ships ZERO rm
// catalogues — not in core/l10n, not in lib/l10n, not in any bundled app — so
// `scanCoreRegister('rm', …)` throws by design rather than computing a verdict
// from nothing. This is the first locale in the set where §5 step 2 cannot be
// run as written, and the runbook's §6.4 fallback applies: the verdict comes
// from this bundle's OWN pre-existing values. It is one-sided enough to settle
// it — 50 formal markers against 0 informal across 995 translated values. The
// counts and the method are recorded in locales/rm.json.
//
// Romansh has a real T-V distinction, so unlike Russian the polite pronoun IS a
// register marker: `Vus` / `voss` (2pl of respect, the German `Sie` model)
// against `ti` / `tiu` (2sg familiar). This bundle capitalises the polite forms
// mid-sentence without exception — `Vus` 13:0, `Voss*` 21:0 — but the match
// below is case-insensitive, because casing is an orthographic convention (see
// locales/rm.json) and not what decides register.
//
// TWO WHOLE PARADIGMS ARE UNUSABLE HERE, and both are ordinary Romansh
// morphology rather than quirks of this bundle:
//
//   • THE 2SG IMPERATIVE. For every `-ar` verb it is spelled exactly like the
//     3sg present indicative, and also like the feminine singular past
//     participle: `stizza` is "delete!", "it deletes" AND "deleted-f.sg". The
//     3sg reading is live in this bundle's own prose — "Quai stizza las
//     endataziuns", "Ferma mintga flux", "Elavurescha ils chunks",
//     "Recalculescha mintga hash" are all real values — so counting the
//     imperative would flag ordinary third-person description.
//
//     Note this is the MIRROR IMAGE of Slovak, not a family difference. Both
//     languages label buttons with the INFINITIVE, so §6.5 test 1 comes out the
//     same for both; they diverge on test 2, because `ulož` ≠ `uloží` in Slovak
//     while `stizza` = `stizza` here. Same button convention, opposite detector
//     decision — which is why §6.5 insists both tests be run per locale.
//
//   • THE 2SG PRESENT OF REGULAR VERBS. It ends in `-as`, which is also the
//     feminine plural of every noun and adjective in the language — the
//     commonest inflection there is. `controllas` is "you check" and also
//     "checks" the noun ("Controllas da surveglianza" is a real value);
//     `empruvas` is "you try" and also "attempts" ("Max empruvas");
//     `tschernas` is "you choose" and also the plural of the noun `tscherna`
//     ("la tscherna d'objects"). So informal detection rests on the TEN
//     irregular verbs, whose 2sg ends in a bare `-s`: has, es, pos, stos, vuls,
//     sas, vas, fas, das, vegns. That is narrow but sound, and it is what
//     actually caught the four real informal slips in the sibling bundles.
//
// Why closed word lists and NOT suffix patterns, specifically for Romansh:
//   • `-ai` looks like the 2pl (polite) imperative ending, and mostly is. But
//     `quai` ("this/that") ends in it and occurs 23 times in these bundles —
//     the single most common word an `-ai` rule would hit — along with
//     `perquai` ("therefore"), `mai` ("never"), bare `ai` (the preposition
//     a + ils), and `hai`/`sai` (1sg "I have"/"I know"). An `-ai` suffix rule
//     scores the most ordinary demonstrative in the language as deference.
//   • `-ais` looks like the 2pl present indicative ending, and mostly is. But
//     `mais` is "months" ("Mintga mais") and the nationality adjectives
//     `ollandais` / `englais` / `franzais` all end in it.
//   • `-as`: see above. Fatal in the other direction.
//
// DIACRITICS ARE NOT FOLDED, and that is load-bearing. `tscherni` is the 2pl
// imperative ("choose!", a formal marker) while `tschernì` is the past
// participle "selected" — this bundle's value for the key `Selected`, and for
// `register(s) selected`. They differ by the grave accent and nothing else, so
// stripping it would score every "Tschernì" label as polite address. The same
// goes for `e` ("and") against `è` ("is"), the two most frequent short words in
// the language. fold() therefore only lowercases.
//
// JS \b is ASCII-only and would treat `à`/`è`/`ì` as boundaries, so every guard
// is (?<!\p{L}) … (?!\p{L}) with the u flag.

function fold(s) {
	return String(s).toLowerCase()
}

// Vus / formal 2pl — the correct register for this bundle.
const FORMAL_RES = [
	// Pronoun and possessive. Zero collisions in Romansh: `vus` has no other
	// reading, and `voss`/`vossa`/`vossas` are only the 2pl possessive. These are
	// the strongest signal in the language and supply most of the count.
	/(?<!\p{L})(?:vus|voss|vossa|vossas)(?!\p{L})/gu,
	// Closed list of 2pl present-indicative forms. NOT an "-ais" rule — `mais`
	// ("months") and `englais` are excluded by construction, not by a guard.
	/(?<!\p{L})(?:essas|avais|pudais|stuais|vulais|savais|vesais|duvrais|basegnais|giais|faschais|dais|vegnis|tschernis|legis|agiuntais|modifitgais|scrivais|mettais|controllais|empruvais|endatais|memorisais|stizzais|chargiais|tschertgais|utilisais|configurais|activais|deactivais|publitgais|exequis|spetgais|cliccais|tippais|installais|contactais|verifitgais|validais|restituis|allontanais|copiais|exportais|importais|filtrais|analisais|administrais|creais|serrais|avris|mussais|cumenzais|cuntinuais|midais|purgais|vectorisais)(?!\p{L})/gu,
	// Closed list of 2pl (polite) imperatives. `-ai` for the -ar/-er classes and
	// `-i` for tscherner; see the header for why this cannot be a suffix rule.
	// `tschernai` is included because it IS polite address, but it is the WRONG
	// form for `tscherner` (correct: `tscherni`) and appears only in
	// opencatalogi's rm bundle — do not copy it in from a harvest.
	/(?<!\p{L})(?:empruvai|endatai|spetgai|agiuntai|tippai|creai|cliccai|dumondai|dumandai|controllai|installai|utilisai|duvrai|dovrai|configurai|mettai|activai|deactivai|administrai|procurai|descrivai|laschai|vectorisai|verifitgai|contactai|declanschai|legiai|promovai|tractai|clamai|allontanai|applitgai|persunalisai|tirai|empleneschai|midai|memorisai|supplitgai|stizzai|serrai|generai|copiai|exportai|importai|filtrai|analisai|chargiai|tschertgai|publitgai|validai|mussai|cumenzai|cuntinuai|purgai|sigillai|modifitgai|notai|guardai|calculai|marcai|tscherni|tschernai)(?!\p{L})/gu,
]

// ti / informal 2sg — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	// Bare `ti` IS usable in Romansh, and that is worth stating because it is the
	// opposite of cs `ty`, hr/sl `ti` and sr `ти`, where the same two letters are
	// also a demonstrative plural. Romansh demonstratives are `quel`/`quella`/
	// `quest`/`questa`, so there is no collision to guard against. Same useful
	// negative as bg — do not port the "leave the bare pronoun unmatched" rule
	// across languages without checking it against real data.
	/(?<!\p{L})(?:ti|tiu|tia|tes|tias)(?!\p{L})/gu,
	// 2sg present of the ten irregular verbs, whose forms end in a bare `-s` and
	// so escape the `-as` feminine-plural collision. `possedas`, `duvras` and
	// `basegnas` are the only regulars included: their stems are not nouns.
	// `controllas`, `empruvas`, `tschernas`, `datas` are deliberately ABSENT.
	/(?<!\p{L})(?:has|es|pos|stos|vuls|sas|vas|fas|das|vegns|possedas|duvras|basegnas)(?!\p{L})/gu,
]

const CONTROLS = [
	// ---- must read FORMAL. Every one is a real value from this bundle unless
	// marked constructed.
	['Essas Vus segir che Vus vulais rumir las colliaziuns da tschertga veglias?', 'formal'],
	['Vulais Vus stizzar permanentamain', 'formal'],
	['Endpoint API persunalisà sche Vus duvrais ina autra regiun', 'formal'],
	['Qua vesais Vus sche quella chadaina è entira.', 'formal'],
	['Tscherner in repositori sin il qual Vus avais access da scriver', 'formal'],
	['Vus stuais esser annunzià per marcar vistas sco favorits', 'formal'],
	['Vus basegnais in agent IA per cumenzar ina conversaziun.', 'formal'],
	['Vossa clav API da Fireworks AI. Procurai Vus ina sin', 'formal'],
	['Administrar Voss Registers da datas e lur configuraziuns', 'formal'],
	['Naginas caracteristicas correspundan a Voss filters.', 'formal'],
	// 2pl imperatives in prose instructions — the formal marker that is NOT a
	// label form here, because labels take the infinitive instead.
	['Controllai la secziun da statistica per il progress.', 'formal'],
	['Empruvai per plaschair pli tard danovamain.', 'formal'],
	['Spetgai per plaschair entant che nus retschavain Vossas configuraziuns.', 'formal'],
	['Legiai la endataziun avant che decider.', 'formal'],
	["Declanschai in memorisar d'object per activar", 'formal'],
	['Duvrai ils filters sutvart per rafinar Vossa tschertga.', 'formal'],
	['Tippai per tschertgar gruppas', 'formal'],
	["Installai l'applicaziun notify_push da l'App Store da Nextcloud", 'formal'],
	['Creai vistas avant da configurar la vectorisaziun.', 'formal'],
	['Mettai sin 0 per elavurar tut las Datotecas.', 'formal'],
	['Verifitgai la chadaina per constatar quai.', 'formal'],
	['menu u contactai insatgi cun il dretg da crear agents.', 'formal'],
	// the -i imperative of tscherner, one accent away from the participle below
	['Tscherni in Register ed in Schema per vesair ils filters disponibels.', 'formal'],
	['u tscherni ina perioda', 'formal'],
	// recall must survive the presence of an excluded -ai word in the same string
	['Utilisai quai cura che fluxs sa cumportan fallà', 'formal'],
	['Activai quai là nua ch\'ina documentaziun da conformitad è necessaria.', 'formal'],

	// ---- must read INFORMAL. The first three are the real slips shipped in the
	// sibling rm bundles; the rest are constructed but valid Rumantsch Grischun,
	// because openregister's own bundle contains no informal value to draw on.
	['Ti has cuntanschì la limita da {limit} dashboards', 'informal'],
	['Ti pos mo agiuntar dashboards che ti possedas', 'informal'],
	["Ti n'has anc nagins credenzials mediads.", 'informal'],
	['Tes Registers', 'informal'],
	['Tia configuraziun da tschertga', 'informal'],
	['Ti es annunzià', 'informal'],
	['Ti stos memorisar las midadas', 'informal'],
	['Sche ti vuls, empruva danovamain', 'informal'],
	['Ti sas gia co quai funcziuna', 'informal'],
	['Quai influenzescha tias datas', 'informal'],
	['Ti basegnas in agent IA', 'informal'],

	// ---- must read NEITHER: the INFINITIVE is the correct Romansh label
	// convention (§7.3), so no button may score as either register.
	['Memorisar', 'neither'],
	['Stizzar', 'neither'],
	['Annullar', 'neither'],
	['Modifitgar', 'neither'],
	['Tscherner il Register ed il Schema', 'neither'],
	['Agiuntar applicaziun', 'neither'],
	['Tscherner tut', 'neither'],
	['Vesair ils detagls', 'neither'],
	['Reemprovar las extracziuns fallidas', 'neither'],
	['Calcular las grondezzas', 'neither'],
	["Exportar, vesair u stizzar las colliaziuns d'audit", 'neither'],
	['Restituir u stizzar permanentamain ils elements', 'neither'],

	// ---- must read NEITHER: the `-as` feminine-plural trap. This is why the
	// regular 2sg present is not detected. Every one is a real value.
	['Controllas da surveglianza — furnidas dad applicaziuns', 'neither'],
	['Max empruvas', 'neither'],
	['Las retardadas dobleschan cun mintga emprova (2, 4, 8 minutas...)', 'neither'],
	['Naginas datas disponiblas', 'neither'],
	['Gruppas tschernidas', 'neither'],
	['Las vistas publicas pon vegnir accedidas da scadin en il sistem', 'neither'],
	['Naginas endataziuns da colliaziuns da tschertga chattadas', 'neither'],

	// ---- must read NEITHER: the `-ai` trap. Without the closed list these five
	// would all score formal, and `quai` alone occurs 23 times.
	['Quai stizza las endataziuns pli veglias che 30 dis.', 'neither'],
	['Quai ha duas chaschuns pussaivlas', 'neither'],
	['ed è perquai mo disponibla sco cumond:', 'neither'],
	['Il secret na vegn mai en OpenConnector.', 'neither'],
	['Configuraziuns da chat OpenAI', 'neither'],

	// ---- must read NEITHER: the `-ais` trap.
	['Mintga mais', 'neither'],

	// ---- must read NEITHER: the 2sg imperative / 3sg present homograph. These
	// are all 3sg present indicative in real values, spelled exactly like the
	// imperatives of the same verbs, which is why the paradigm is excluded.
	['Quai stizza permanentamain:', 'neither'],
	['Ferma mintga flux en mez la execuziun, tar ses proxim pass.', 'neither'],
	['Elavurescha ils chunks en batches cun parallelissem simulà.', 'neither'],
	['Recalculescha mintga hash e cumpara el cun quel memorisà.', 'neither'],
	['Spetga il sigil', 'neither'],

	// ---- must read NEITHER: `tschernì` is the past participle "selected", one
	// grave accent from the 2pl imperative `tscherni` tested above. This pair is
	// the reason fold() keeps diacritics.
	['Tschernì', 'neither'],
	['Register(s) tschernì(s)', 'neither'],
	["Nagin Schema tschernì per l'exploraziun", 'neither'],

	// ---- mixed sanity: one informal marker inside otherwise formal prose wins.
	['Tscherni in Register, ma ti stos memorisar las midadas', 'informal'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Stizza las midadas', 'the bare 2sg imperative of an -ar verb. Identical to '
		+ 'the 3sg present ("Quai stizza…") and to the feminine singular past '
		+ 'participle, all three of which occur in this bundle. Excluded wholesale '
		+ '— unlike bg, the split is not by conjugation class, because EVERY '
		+ 'Romansh -ar verb collides this way'],
	['Ti controllas la configuraziun', 'the 2sg present of a regular verb. '
		+ '`controllas` is at once "you check" and the noun "checks" — and the '
		+ 'noun reading is the one that occurs here ("Controllas da surveglianza"). '
		+ 'Detection rests on the ten irregular verbs instead'],
	['Endatescha il num', 'the 2sg imperative of an -escha (inchoative) verb, same '
		+ 'collision as the -ar class: also the 3sg present ("Elavurescha ils '
		+ 'chunks", "Recalculescha mintga hash")'],
	['Las tias datas', 'a possessive with the definite article reads informal but '
		+ 'is caught only by `tias` itself; a nominal sentence carrying no pronoun '
		+ 'and no finite verb has no marker at all'],
	['Na sa emblidar da memorisar', 'negated imperatives are formed with `na … '
		+ 'betg` around an open set of verbs; only affirmative forms are enumerated'],
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

	// No core scan is possible: Nextcloud ships no rm catalogues at all, so
	// scanCoreRegister would throw. Confirm that is still true rather than
	// asserting it from a comment, then measure this bundle instead — the §6.4
	// fallback, and the evidence recorded in locales/rm.json.
	const path = require('path')
	const { coreCatalogues, loadJsTranslations, APP_ROOT } = require('../lib.js')
	let core = null
	try {
		core = coreCatalogues('rm')
	} catch (e) {
		console.log(`\nno core rm catalogues: ${e.message.split('.')[0]}`)
	}
	if (core) {
		console.log(`\nNOTE: ${core.length} core rm catalogue(s) exist now — core was `
			+ 'empty when this detector was written. Re-measure the register against '
			+ 'core and update locales/rm.json; core outranks the bundle (§3.4).')
	}

	const file = path.join(APP_ROOT, 'l10n', 'rm.js')
	const tr = loadJsTranslations(file).translations
	let formal = 0
	let informal = 0
	let values = 0
	const hits = []
	for (const [k, v] of Object.entries(tr)) {
		for (const x of Array.isArray(v) ? v : [v]) {
			// Values byte-equal to their key are untranslated English and cannot
			// carry Romansh register; counting them would dilute both totals.
			if (typeof x !== 'string' || !x || (!Array.isArray(v) && x === k)) continue
			values++
			const s = score(x)
			formal += s.f
			informal += s.i
			if (s.i > 0) hits.push(x.slice(0, 100))
		}
	}
	console.log(`\nscanned l10n/rm.js: ${values} translated value(s)`)
	console.log(`formal (Vus) markers:   ${formal}`)
	console.log(`informal (ti) markers:  ${informal}`)
	console.log(`verdict: ${formal > informal * 3 ? 'FORMAL' : informal > formal * 3 ? 'INFORMAL' : 'MIXED — inspect'}`)
	for (const v of hits.slice(0, 20)) console.log(`  informal? ${v}`)
}
