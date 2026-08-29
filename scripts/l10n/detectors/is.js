/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Icelandic (íslenska) register detector for openregister l10n.
//
// ICELANDIC HAD A T-V DISTINCTION AND IT IS NOW OBSOLETE. That is the fact to
// understand first, and it is a THIRD distinct situation from the two locales
// done immediately before this one — the three should not be collapsed:
//
//   • ga — Irish never had a T-V distinction at all. `sibh` is strictly plural.
//     There is no choice to record.
//   • mt — Maltese HAS one and it is current. `intom` works as a polite singular
//     and `Is-Sinjur` as the deferential register; both are simply unused, so the
//     verdict is an ordinary measured choice.
//   • is — Icelandic HAD one (þérun: `þér` plus a 2pl verb, possessive `yðar`)
//     and abandoned it during the 20th century. The forms still exist in legal,
//     liturgical and deliberately archaic register, so using them is possible in
//     a way Irish 2pl-as-polite is not — but in a 2026 admin UI `Hafið þér
//     aðgang?` would read as parody, not deference.
//
// So `informal` here records a live language state, not a preference, and not an
// absence. The measurement:
//
//   core is: 28 catalogues, 3610 values.
//     2sg pronoun + possessive markers   482 occurrences
//     clean enclitic imperatives         178 values
//     archaic polite V-form (yður/yðar/yðvar/þéra/þérun)   ZERO
//     plain 2pl address (þið/ykkur/ykkar)                  ZERO
//
//   Both zeroes were re-checked by raw grep over the 28 catalogues, not just by
//   this scan — §8.3 says a measurement that lands on exactly zero deserves a
//   second look before it goes into registerEvidence, and a nine-token grep is
//   what that looked like here.
//
// THE GATE CATCHES TWO DIFFERENT DEFECTS, deliberately under one polarity, and
// they are worth keeping distinct in your head because they are not the same
// mistake:
//
//   1. ARCHAIC DEFERENCE — `yður`, `yðar`, or nominative `þér` with a 2pl verb.
//      Wrong because it is obsolete, not because it is polite.
//   2. WRONG NUMBER — `þið`, `ykkur`, `ykkar`. These are the ordinary modern
//      2nd person PLURAL, entirely correct Icelandic when addressing several
//      people, and simply wrong in a single-user UI. This is the more likely
//      slip of the two: a translator importing a Continental politeness plural
//      from de/fr/nl reaches for the plural that is actually current, because
//      they would have to know Icelandic philology to reach for `yðar`.
//
// WHY THE ENCLITIC IMPERATIVE IS COUNTED, AND ONLY FOR ONE CONJUGATION CLASS —
// §6.5, and this is the bg outcome (PARTIALLY detectable, split by class) rather
// than a whole-paradigm exclusion:
//
//   Test 1: is the imperative this locale's LABEL convention? NO. Core labels
//   buttons with the INFINITIVE — Vista, Eyða, Breyta, Afrita, Færa, Staðfesta,
//   Virkja, Endurstilla, Endurheimta, Skoða, Leita, Loka, Búa til, Bæta við,
//   Hætta við — 29 of 35 distinct values across ~35 bare action keys, with zero
//   verbal nouns and exactly ONE enclitic imperative (`Choose` = `Veldu`,
//   against `Select` = `Velja`). This bundle's own 21 action keys agree
//   unanimously. So is joins cs/lt/lv/sk/rm on the register-neutral infinitive,
//   and counting the imperative does NOT flag every button.
//
//   Test 2: is the imperative a homograph? PARTIALLY, and the split is exactly
//   by conjugation class, which makes it a rule rather than a word list:
//
//     · Class 1 (-a verbs) form the 3pl past in -uðu while the imperative plus
//       enclitic þú is -aðu. notaðu/notuðu, skoðaðu/skoðuðu, afritaðu/afrituðu,
//       prófaðu/prófuðu, virkjaðu/virkjuðu. DISTINCT, so usable.
//     · Class 2 (-ja/-ta/-la verbs, past in -ti/-di/-ði) form the 3pl past
//       IDENTICALLY to the imperative plus enclitic: settu, sendu, smelltu,
//       reyndu, breyttu, ýttu, staðfestu, skráðu, endurstilltu, eyddu, gerðu.
//       `þeir settu` is "they put" and `Settu inn` is "enter!". UNUSABLE.
//
//   And the collision is demonstrated in the corpus rather than merely possible,
//   which is what settles it: `komu` occurs 4 times as the 3pl past ("Það komu
//   of margar beiðnir" — "too many requests came"), and `völdu` twice as the weak
//   adjective *selected* ("Ekki hægt að lesa völdu skrána", "úr völdu sniðmáti"),
//   never as an imperative. `staðfestu` is trebly ambiguous: imperative, 3pl
//   past, AND the oblique of the noun `staðfesta` ("confirmation").
//
//   The excluded class is not small — `settu` alone has 41 values — but most of
//   it is recovered anyway, because those values almost always carry a pronoun or
//   possessive too: "Skráðu þig inn" scores on `þig`, "Reyndu aftur eða hafðu
//   samband" scores on `hafðu`.
//
// WHY THERE IS NO -ið SUFFIX RULE. `-ið` is the 2pl verb ending, and it is ALSO
// the neuter definite article — one of the commonest morphemes in the language.
// lykilorðið, tölvupóstfangið, skjalið, safnið, yfirlitið, nafnið are all nouns.
// This is the Icelandic counterpart of the bg `-те` trap, and it is worse,
// because three individual 2pl verb forms are themselves homographs of ordinary
// words: `hafið` is the past participle of `hefja` ("hefur hafið ferli" — "has
// begun a process") and also "the ocean"; `getið` is the participle "mentioned";
// `verðið` is "the price"; `vitið` is "the wit"; `eigið` is the neuter adjective
// "own" ("þitt eigið Nextcloud"). Two of those five occur in core in the
// non-verbal reading and NEITHER occurs as a 2pl verb. So the 2pl forms are a
// short closed list of the unambiguous ones only.
//
// THE þér BIGRAM, which is the interesting part of this detector. `þér` is at
// once the 2sg DATIVE ("þér er ekki heimilt" — "you are not permitted") and the
// archaic polite NOMINATIVE. All 54 core occurrences are the dative, verified at
// their call sites, so bare `þér` counts as INFORMAL. The polite reading is
// recovered without giving up the dative: as a nominative subject it must combine
// with a finite 2pl verb, so the BIGRAM disambiguates both individually-ambiguous
// tokens at once — `þér hafið` can only be the V-form, since dative `þér` takes
// no 2pl verb and `hafið` as "the ocean" does not follow a pronoun. Matched in
// both orders, because a question inverts it (`Hafið þér...`).
//
// ICELANDIC "PLEASE" CARRIES NO ADDRESS MARKER, unlike Maltese. §8.1 says to look
// for the politeness formula rather than only pronouns, and to check rather than
// assume: `vinsamlegast` is an adverb (superlative of `vinsamlegur`, "kindly")
// and inflects for nothing. So it is the ga outcome, not the mt one — no free
// signal here.
//
// DIACRITICS ARE NOT FOLDED. They are contrastive in Icelandic and load-bearing
// in this very detector: `veldu` (imperative, usable) against `völdu` (weak
// adjective, excluded) differ only by the vowel. fold() only lowercases — the
// sk/sl/rm/ga precedent.
//
// JS \b is ASCII-only and would treat þ/ð/æ/ö/á/í as boundaries, so every guard
// is (?<!\p{L}) … (?!\p{L}) with the u flag.

function fold(s) {
	return String(s).toLowerCase()
}

// 2pl verb forms, in TWO lists, because ambiguity is context-dependent here.
//
// V2PL_ALL is every 2pl form including the five homographs. It is used ONLY
// adjacent to `þér` or `þið`, where the pairing disambiguates both words at
// once: "hefur hafið ferli" has no pronoun beside it, so `þér hafið` can only be
// the verb and only be the V-form.
//
// V2PL_SAFE is the subset matched on its own — a bare 2pl imperative label like
// `Veljið`. hafið / getið / verðið / vitið / eigið are excluded here, since each
// is a common ordinary word ("the ocean" / "mentioned" / "the price" / "the wit"
// / "own"), and two of the five occur in core in exactly that reading while none
// occurs as a 2pl verb. smellið / sláið / notið are left out as marginal for the
// same reason (`smell` "a smack", `slá` "a bar", `not` "uses" all take -ið).
const V2PL_ALL = 'eruð|hafið|getið|viljið|verðið|skuluð|munuð|þurfið|vitið|eigið|veljið|smellið|sláið|notið|séuð|voruð|hafðuð'
const V2PL_SAFE = 'eruð|viljið|skuluð|munuð|þurfið|veljið|séuð|voruð'

// Deference and wrong-number address — THE DEVIATION this gate exists to catch.
// Zero occurrences in 3610 core values plus this bundle's 1054.
const FORMAL_RES = [
	// Archaic polite possessive / oblique. `yður` (acc/dat), `yðar` (gen), and the
	// yðr- stem of the possessive adjective. No competing reading in the modern
	// language at all — these words do not occur outside deliberate archaism.
	/(?<!\p{L})(?:yður|yðar|yðvar|yðart|yðra|yðrar|yðrir|yðru|yðrum|yðurt)(?!\p{L})/gu,
	// The verbs for "to address as þér", which name the practice itself.
	/(?<!\p{L})(?:þéra|þérar|þéraði|þérun|þérunar)(?!\p{L})/gu,
	// Plain modern 2nd person PLURAL. Correct Icelandic for several addressees and
	// wrong for a single-user UI, which is defect (2) in the header.
	/(?<!\p{L})(?:þið|ykkur|ykkar|ykkart|ykkarn|ykkir)(?!\p{L})/gu,
	// The þér BIGRAM, in both orders. This is what recovers the polite NOMINATIVE
	// without losing the 2sg dative: neither token is decidable alone, the pair is.
	// Uses the FULL 2pl list, which is exactly what the pairing makes safe.
	new RegExp(`(?<!\\p{L})þér\\s+(?:${V2PL_ALL})(?!\\p{L})`, 'gu'),
	new RegExp(`(?<!\\p{L})(?:${V2PL_ALL})\\s+þér(?!\\p{L})`, 'gu'),
	// Same pairing with the plain plural pronoun — the wrong-number defect.
	new RegExp(`(?<!\\p{L})þið\\s+(?:${V2PL_ALL})(?!\\p{L})`, 'gu'),
	new RegExp(`(?<!\\p{L})(?:${V2PL_ALL})\\s+þið(?!\\p{L})`, 'gu'),
	// A bare 2pl imperative addressed to one user, e.g. `Veljið skráasafn`. Only
	// the unambiguous subset, and only because the label convention here is the
	// INFINITIVE (§6.5 test 1), so this cannot flag ordinary buttons.
	new RegExp(`(?<!\\p{L})(?:${V2PL_SAFE})(?!\\p{L})`, 'gu'),
]

// 2sg — the CORRECT and only live register for this locale. Counted so the
// verdict rests on evidence; not gated on.
const INFORMAL_RES = [
	// Pronoun in every case, plus the emphatic. Bare `þú` has no competing reading
	// in Icelandic — the same useful negative as bg, rm and ga, so do NOT port the
	// "leave the bare pronoun unmatched" rule from cs/hr/sl here. `þér` is the
	// dative and is included on the strength of 54 verified call sites; the polite
	// nominative reading is taken by the bigram above, which wins the tie.
	/(?<!\p{L})(?:þú|þig|þér|þín|þúst)(?!\p{L})/gu,
	// Possessive `þinn` in its full paradigm. All unambiguous.
	/(?<!\p{L})(?:þinn|þitt|þína|þínir|þínar|þínum|þinni|þínu|þinna|þíns|þínan)(?!\p{L})/gu,
	// Clitic question/statement forms, where þú has fused onto the verb. These are
	// unambiguous by construction — no noun ends this way.
	/(?<!\p{L})(?:ertu|viltu|hefurðu|geturðu|ættirðu|muntu|verðurðu|máttu|áttu|fékkstu|varstu)(?!\p{L})/gu,
	// CLASS 1 enclitic imperatives only (-aðu), whose 3pl past is -uðu and so does
	// not collide, plus the strong/irregular verbs whose 3pl past differs by ablaut
	// (veldu/völdu, farðu/fóru, taktu/tóku, gakktu/gengu, hafðu/höfðu, bíddu/biðu,
	// sláðu/slógu, gefðu/gáfu, láttu/létu, búðu/bjuggu). The class 2 forms —
	// settu, sendu, smelltu, reyndu, breyttu, ýttu, staðfestu, skráðu,
	// endurstilltu, eyddu, gerðu, kíktu, merktu, stilltu — are ABSENT on purpose;
	// each is spelled identically to the 3rd person plural past.
	/(?<!\p{L})(?:notaðu|skoðaðu|athugaðu|vistaðu|afritaðu|prófaðu|virkjaðu|leitaðu|opnaðu|byrjaðu|hakaðu|flokkaðu|raðaðu|veldu|farðu|taktu|gakktu|hafðu|bíddu|sláðu|gefðu|láttu|búðu)(?!\p{L})/gu,
]

const CONTROLS = [
	// ---- must read INFORMAL (2sg). Every one is a real value from core is or from
	// this bundle. This is the CORRECT register, so these are the "good data"
	// controls that must keep firing.
	['Þú ættir að nota upprunalegt biðlaraforrit', 'informal'],
	['Smelltu á eftirfarandi hnapp til að endurstilla lykilorðið þitt.', 'informal'],
	['Tengingin þín er ekki örugg', 'informal'],
	['Aðgangur þinn er ekki uppsettur með lykilorðalausri innskráningu.', 'informal'],
	['Endurstilltu lykilorðið þitt', 'informal'],
	['Sumir tenglar þínir á sameignir hafa verið fjarlægðir', 'informal'],
	['Skrárnar þínar eru dulritaðar.', 'informal'],
	['Þjóninum tókst ekki að afgreiða beiðnina þína.', 'informal'],
	['Lykilorðalaus auðkenning er ekki studd í vafranum þínum.', 'informal'],
	['Athugaðu gildi "datadirectory" í uppsetningunni þinni.', 'informal'],
	['Það komu of margar beiðnir frá netkerfinu þínu.', 'informal'],
	['Lykilorð einkalykilsins þíns samsvarar ekki lengur innskráningarlykilorðinu þínu.', 'informal'],
	['Ertu viss um að þú viljir eyða öllum skrám og möppum úr ruslinu?', 'informal'],
	['Viltu bæta við fjartengdri sameign {name} frá {owner}@{remote}?', 'informal'],
	['Hér geturðu stillt hvaða forrit ætti að nota', 'informal'],
	['Fyrir bestu útkomu ættirðu að íhuga að nota GNU/Linux þjón í staðinn.', 'informal'],
	['Síðan fannst ekki á netþjóninum eða að þér er ekki heimilt að skoða hana.', 'informal'],
	['Beini þér til {productName} eftir {count} sekúndur.', 'informal'],
	['Skráði þig inn áður en þú leyfir "{client}" aðgang', 'informal'],
	// clean class-1 enclitic imperatives, and the safe irregulars
	['Notaðu einn af öryggisafritunarkóðunum', 'informal'],
	['Skoðaðu tengilinn til að sjá frekari upplýsingar', 'informal'],
	['Veldu skrá.', 'informal'],
	['Sláðu inn hlutauðkenni', 'informal'],
	['Hafðu samband við kerfisstjóra.', 'informal'],
	['Vinsamlegast bíddu meðan við sækjum stillingar þínar.', 'informal'],
	['Farðu í %s', 'informal'],
	['Afritaðu tengilinn handvirkt:', 'informal'],
	['Virkjaðu dulritun á vefþjóninum í kerfisstjórnunarstillingunum', 'informal'],
	['Gakktu úr skugga um að gagnagrunnurinn hafi verið öryggisafritaður', 'informal'],
	// the pronoun must still be found when it is not the first word
	['Fireworks AI API lykillinn þinn. Fáðu einn á', 'informal'],

	// ---- must read FORMAL — the deviation. Core and this bundle contain NO
	// deference and NO 2pl address at all, so every one of these is constructed.
	// They are valid Icelandic and each is a shape a translator could produce.
	// (a) archaic V-form possessive/oblique
	['Lykilorð yðar er útrunnið', 'formal'],
	['Vér sendum yður tilkynningu', 'formal'],
	['Gögnin yðar hafa verið vistuð', 'formal'],
	['Aðgangur yðvar er ekki tiltækur', 'formal'],
	// (b) the þér bigram — polite NOMINATIVE, both orders. Bare þér elsewhere must
	// still read informal, which the "þér er ekki heimilt" control above asserts.
	['Þér hafið ekki heimild til að skoða þessa síðu', 'formal'],
	['Hafið þér vistað breytingarnar?', 'formal'],
	['Þér eruð ekki innskráðir', 'formal'],
	['Viljið þér eyða þessum hlut?', 'formal'],
	['Þér þurfið að velja skráasafn', 'formal'],
	// (c) plain 2pl address — the wrong-number defect, the likelier slip
	['Eruð þið viss um að þið viljið eyða þessu?', 'formal'],
	['Stillingarnar ykkar hafa verið vistaðar', 'formal'],
	['Við sendum ykkur tilkynningu þegar ferlinu er lokið', 'formal'],
	['Þið verðið að skrá ykkur inn fyrst', 'formal'],
	['Veljið skráasafn og skema', 'formal'],

	// ---- must read NEITHER: the INFINITIVE label convention (§7.3). No button may
	// score as either register. Every one is a real core or bundle value.
	['Vista', 'neither'],
	['Eyða', 'neither'],
	['Hætta við', 'neither'],
	['Breyta', 'neither'],
	['Búa til', 'neither'],
	['Bæta við', 'neither'],
	['Staðfesta', 'neither'],
	['Endurstilla', 'neither'],
	['Endurheimta', 'neither'],
	['Gera óvirkt', 'neither'],
	['Flytja inn', 'neither'],
	['Velja', 'neither'],
	['Reyna aftur', 'neither'],
	['Stillingar', 'neither'],

	// ---- must read NEITHER: the -ið DEFINITE ARTICLE trap. This is why there is
	// no -ið suffix rule. All real values.
	['Endurstilltu lykilorðið þitt', 'informal'],
	['Staðfestu tölvupóstfangið þitt', 'informal'],
	['Sláðu inn nafn yfirlits...', 'informal'],
	['Skjalið var ekki fundið', 'neither'],
	['Safnið er tómt', 'neither'],
	['Nafnið er þegar í notkun', 'neither'],
	['Yfirlitið hefur verið uppfært', 'neither'],

	// ---- must read NEITHER: the 2pl-verb homographs. Each is a real core value in
	// which the word is NOT a verb, and neither occurs as a 2pl verb anywhere.
	['Tækið eða forritið »%s« hefur hafið ferli til að þurrka út fjartengt.', 'neither'],
	['Mistókst að bæta opinberum tengli í þitt eigið Nextcloud', 'informal'],
	['Bæta í þitt eigið Nextcloud', 'informal'],
	['Þess er getið í annálnum', 'neither'],
	['Verðið er ekki tiltækt', 'neither'],

	// ---- must read NEITHER: the CLASS 2 imperative / 3pl-past homograph. The
	// reason that whole class is excluded. `komu` and `völdu` are real core values
	// in the PAST reading; the rest are real values in the imperative reading and
	// must still not score, because nothing can tell the two apart.
	['Það komu upp villur varðandi uppsetninguna', 'neither'],
	['Ekki hægt að lesa völdu skrána.', 'neither'],
	['Búa til nýja skrá úr völdu sniðmáti', 'neither'],
	['Settu inn {minSearchLength} staf eða fleiri til að leita', 'neither'],
	['Sendu inn eitthvað efni', 'neither'],
	['Mundu að senda skrárnar inn til %s', 'neither'],
	['Ýttu á Enter til að hefja leit', 'neither'],

	// ---- must read NEITHER: impersonal / passive prose, which is most of this
	// bundle. It addresses nobody and legitimately scores zero both ways.
	['Hlutnum var eytt', 'neither'],
	['Engar skrár fundust', 'neither'],
	['Vektorfellingar eru búnar til við skráaútdrátt', 'neither'],
	['Ekki er hægt að afturkalla þessa aðgerð.', 'neither'],
	['Skema var uppfært', 'neither'],

	// ---- mixed sanity: one deference marker inside otherwise correct prose wins,
	// because that is the slip the gate has to catch.
	['Veldu skráasafn, en þér verðið að vista breytingarnar', 'formal'],
	['Notaðu síurnar hér að neðan til að þrengja leitina ykkar', 'formal'],
]

// Register information this detector CANNOT see, and why. Recorded rather than
// left as failing controls.
const UNDETECTABLE = [
	['Settu inn tölvupóstfangið þitt', 'the CLASS 2 enclitic imperative (-tu/-du/-ðu) '
		+ 'is spelled identically to the 3rd person plural past, so `settu` is both '
		+ '"enter!" and "they put". The whole class is excluded: settu (41 values in '
		+ 'core), sendu, smelltu, reyndu, breyttu, ýttu, staðfestu, skráðu, '
		+ 'endurstilltu, eyddu, gerðu. `staðfestu` is trebly ambiguous, being also '
		+ 'the oblique of the noun "confirmation". The loss is largely recovered in '
		+ 'practice because such values usually carry a pronoun or possessive too — '
		+ 'this example scores on `þitt`'],
	['Reyndu aftur síðar', 'same class, and here nothing else in the value carries '
		+ 'address, so it genuinely scores zero. Note the polarity: every one of '
		+ 'these losses is on the CORRECT-register side, so it depresses the informal '
		+ 'count without ever producing a false formal hit. The gate stays sound; only '
		+ 'the verdict evidence is thinner than the language provides'],
	['Þér hafið aðgang', 'a polite nominative þér is caught ONLY by the bigram, so a '
		+ 'V-form built on a 2pl verb this list omits as a homograph — hafið, getið, '
		+ 'verðið, vitið, eigið — escapes. That is a deliberate trade: including those '
		+ 'five would score "hefur hafið ferli" and "þitt eigið Nextcloud" as '
		+ 'deference, and both are real core values while the V-form is attested '
		+ 'nowhere. This particular example DOES fire, because `hafið` is in the '
		+ 'bigram list; `Þér vitið það` would not'],
	['Vinsamlegast veljið skrá', 'Icelandic "please" is an adverb and inflects for '
		+ 'nothing, so unlike Maltese `jekk jogħġbok` it carries no address marker at '
		+ 'all. §8.1 says to look for the politeness formula rather than only '
		+ 'pronouns — checked here, and there is no free signal. This value is caught '
		+ 'by `veljið`, not by `vinsamlegast`'],
	['Yfirlitið var vistað', 'the -ið neuter definite article makes every second noun '
		+ 'in the language look like a 2pl verb ending, so there is no suffix rule at '
		+ 'all and the 2pl list is confined to unambiguous forms. This is the bg -те '
		+ 'situation and slightly worse, since five individual 2pl forms are '
		+ 'themselves homographs of common words'],
	['Þú getur vistað', 'the 2sg PRESENT is useless on its own: Icelandic syncretises '
		+ '2sg and 3sg for most verbs (`þú getur` / `hann getur`, `þú hefur` / `hann '
		+ 'hefur`), and the -ur ending is also the masculine nominative singular of '
		+ 'thousands of nouns. Address is carried by the pronoun, which is matched'],
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
		// Formal wins ties, as in ga.js and for the same reason: the deviation being
		// gated on is deference / wrong number, so a value carrying both must be
		// reported as the defect rather than excused by the 2sg marker beside it.
		// It is also what makes the þér bigram work — `Þér hafið` scores informal on
		// bare `þér` as well, and must still come out formal.
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

	const { scanCoreRegister, reportCoreRegister } = require('../lib.js')
	const r = scanCoreRegister('is', score)
	reportCoreRegister('is', r, {
		formal: 'yðar / þér+2pl deference, and þið/ykkar wrong number',
		informal: 'þú, 2sg',
	})
	console.log('\nread this as: Icelandic HAD a T-V distinction and abandoned it in the 20th')
	console.log('century, so `informal` records a live language state rather than a preference.')
	console.log(`The load-bearing figure is the FORMAL count — it must be 0, and is ${r.formal}.`)
	console.log('It bundles two distinct defects: archaic deference (yðar, þér+2pl) and plain')
	console.log('wrong number (þið, ykkar). The second is the likelier slip, because it is the')
	console.log('plural that is actually current in the language.')
}
