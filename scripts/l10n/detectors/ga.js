/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Irish (Gaeilge) register detector for openregister l10n.
//
// IRISH HAS NO T-V DISTINCTION, and that is the single fact to understand before
// reading the word lists below. `sibh` is strictly the second-person PLURAL in
// modern standard Irish; it is not a polite singular the way German `Sie`,
// Romansh `Vus` or Croatian `Vi` are. So unlike every locale done before it,
// there is no register CHOICE here to measure — there is one way to address a
// user, and it is `tú`.
//
// The measurement bears that out and is one-sided beyond any other locale in the
// set: across core's 33 ga catalogues (5395 values) there are 434 second-person
// singular markers and ZERO second-person plural markers. Not one occurrence of
// sibh, sibhse, bhur, agaibh, daoibh, libh, oraibh, uaibh, chugaibh, díbh, or an
// -aigí/-igí 2pl imperative — verified by raw grep as well as by this scan. This
// bundle's own 1036 pre-existing values agree: 15 singular markers, 0 plural.
//
// locales/ga.json therefore records `"register": "informal"`, which needs saying
// plainly: it does NOT mean Irish core chose the familiar register over a polite
// one. It means the gate's polarity is set so that patchcheck refuses SECOND-
// PERSON PLURAL address. That is the one register defect this locale can have,
// and it is a live risk rather than a theoretical one: a translator working down
// a list of European locales imports the politeness plural by analogy from
// de/fr/nl and produces `An bhfuil sibh cinnte…` for a single-user dialog. No
// other gate in the project can see that.
//
// WHY THE 2SG IMPERATIVE IS NOT COUNTED — §6.5, and it fails BOTH tests:
//
//   • Test 1: it IS this locale's label convention. Core labels buttons with the
//     bare imperative — Sábháil, Scrios, Cealaigh, Cruthaigh, Deimhnigh,
//     Cóipeáil, Roghnaigh, Bain, Dún, Bog, Athnuaigh, Athchóirigh — exactly as
//     English does. Counting it would flag every button in the app.
//
//   • Test 2: for the whole `-áil` class the imperative is spelled IDENTICALLY
//     to the verbal noun, and the verbal noun is live in this bundle's prose as
//     the progressive: `Ag sábháil...` ("Saving..."), `Ag cóipeáil...`,
//     `Ag tástáil...`, `Ag próiseáil...`, `Ag íoslódáil...` are all real values
//     whose second word is the imperative form. Several stems are ordinary nouns
//     besides — `Scrios` is also "destruction", `Dún` also "a fort", `Cuardach`
//     also "a search" — and `Léigh`/`Léigh` is the imperative of a verb whose
//     verbal noun `léamh` this bundle uses in `á léamh`.
//
//   So ga lands where ca/et/hr/sl/sr land, not where sk does. Note that unlike
//   rm, the two tests agree here rather than pulling apart: Irish is an
//   imperative-label language AND its imperative is a homograph, so the
//   exclusion is doubly forced.
//
// WHY CLOSED WORD LISTS AND NOT SUFFIX PATTERNS, specifically for Irish:
//
//   • `-igí` / `-aigí` looks like the 2pl imperative ending and mostly is. But it
//     is also the plural of every noun in `-ig`: `oifig` -> `oifigí` ("offices"),
//     and Irish forms plenty of plurals that way. An `-igí` rule would score an
//     ordinary noun plural as deference. (No such word happens to occur in the
//     6431-value corpus scanned here, which is exactly why a suffix rule would
//     have looked safe and shipped.)
//
//   • `-ibh` looks like a 2pl prepositional-pronoun ending and often is (`libh`,
//     `daoibh`, `fúibh`, `díbh`). But it is ALSO the dative plural of every noun
//     in the older orthography and the ending of `díobh` / `dóibh` / `uathu`-type
//     THIRD-person plurals. `díbh` is "off you (pl)" while `díobh` is "off them"
//     — one letter apart, and it is the THIRD-person one that occurs in this
//     corpus, twice, both times genuinely "of them" (`gach ball díobh seo`,
//     `gach ceann díobh a shárú`). Matching `-ibh` would score both as formal
//     address. Only `díbh` is listed; `díobh` is deliberately absent.
//
//   • `do` is the 2sg possessive "your" AND the preposition "to/for" AND the
//     past-tense verbal particle AND half of `le do thoil` ("please"). It occurs
//     in 527 of the 6431 corpus values, overwhelmingly not as a possessive. It is
//     excluded wholesale; see UNDETECTABLE for what that costs.
//
// DIACRITICS ARE NOT FOLDED. The fada is contrastive in Irish (`ár` "our" vs
// `ar` "on", `sé` vs `se`), and there is no reason to strip it here: none of the
// tokens below need folding to match, and stripping invites collisions that
// cannot be predicted from the lists themselves. fold() only lowercases — the
// sk/sl/rm precedent.
//
// JS \b is ASCII-only and would treat á/é/í/ó/ú as boundaries, so every guard is
// (?<!\p{L}) … (?!\p{L}) with the u flag.

function fold(s) {
	return String(s).toLowerCase()
}

// sibh / 2pl — THE DEVIATION this gate exists to catch. Zero legitimate uses in
// a single-user UI, and zero occurrences in 6431 real ga values.
const FORMAL_RES = [
	// Pronoun, emphatic pronoun and possessive. No collisions in Irish: `sibh`
	// and `sibhse` have no other reading, and `bhur` is only the 2pl possessive.
	/(?<!\p{L})(?:sibh|sibhse|bhur)(?!\p{L})/gu,
	// Closed list of 2pl prepositional pronouns. NOT an "-ibh" rule — `díobh`
	// and `dóibh` (third person) are excluded by construction, not by a guard.
	/(?<!\p{L})(?:agaibh|daoibh|dhaoibh|libh|oraibh|uaibh|ionaibh|romhaibh|chugaibh|chughaibh|d[íi]bh|dh[íi]bh|asaibh|tharaibh|f[úu]ibh|umaibh|tr[íi]bh|eadraibh|chucaibh)(?!\p{L})/gu,
	// Closed list of 2pl imperatives, for the verbs this app actually uses. See
	// the header for why this cannot be an `-igí` suffix rule. The 2pl PRESENT
	// needs no list: Irish forms it analytically (`cuireann sibh`), so `sibh`
	// above already catches it, and the same goes for the 2pl conditional.
	/(?<!\p{L})(?:s[áa]bh[áa]laig[íi]|scriosaig[íi]|cuirig[íi]|bainig[íi]|cealaíg[íi]|d[úu]naig[íi]|c[óo]ipe[áa]laig[íi]|roghnaíg[íi]|cruthaíg[íi]|cumasaíg[íi]|d[íi]chumasaíg[íi]|athnuaíg[íi]|athshocraíg[íi]|deimhníg[íi]|bogaig[íi]|athch[óo]iríg[íi]|l[ée]ig[íi]|scr[íi]obhaig[íi]|f[ée]achaig[íi]|t[ée]ig[íi]|b[íi]g[íi]|d[ée]anaig[íi]|tugaig[íi]|glanaig[íi]|seice[áa]laig[íi]|[úu]s[áa]idig[íi]|fanaig[íi]|iarraig[íi]|bainistíg[íi]|easp[óo]rt[áa]laig[íi]|iomp[óo]rt[áa]laig[íi]|[íi]osl[óo]d[áa]laig[íi]|uasl[óo]d[áa]laig[íi]|t[áa]st[áa]laig[íi]|bail[íi]ochtaíg[íi]|veicteoiríg[íi]|nuashonraíg[íi]|foilsíg[íi]|taispe[áa]naig[íi]|folaíg[íi]|scagaig[íi]|cuardaíg[íi]|aimsíg[íi]|tosaíg[íi]|stopaig[íi]|atriailig[íi]|comhroinnig[íi]|seolaig[íi]|osclaíg[íi]|cl[óo]scr[íi]obhaig[íi]|fillig[íi]|aithníg[íi]|braithíg[íi]|ginig[íi]|r[íi]omhaig[íi]|rithig[íi]|t[úu]saíg[íi]|sioncronaíg[íi]|scor[áa]naíg[íi]|leathnaíg[íi]|aisghabhaig[íi]|c[úu]lghairig[íi]|di[úu]ltaíg[íi]|glacaig[íi]|f[íi]oraíg[íi]|cumraíg[íi]|pr[óo]ise[áa]laig[íi]|suite[áa]laig[íi]|east[óo]scaig[íi]|athainmníg[íi]|cinnig[íi]|faighig[íi]|abraíg[íi]|feicig[íi]|t[óo]gaig[íi])(?!\p{L})/gu,
]

// tú / 2sg — the CORRECT and only register for this locale. Counted so the
// verdict rests on evidence; not gated on.
const INFORMAL_RES = [
	// Pronoun, its lenited object form, and both emphatics. `tú` and `thú` have
	// no competing reading in Irish, so unlike cs `ty` / hr `ti` / sr `ти` the
	// bare pronoun IS usable — the same useful negative result as bg and rm. Do
	// not port the "leave the bare pronoun unmatched" rule here.
	/(?<!\p{L})(?:t[úu]|th[úu]|tusa|thusa)(?!\p{L})/gu,
	// Closed list of 2sg prepositional pronouns. Every one of the seven that
	// occurs in the corpus was checked at its call site and is genuine 2sg
	// address: `Níl cead agat`, `Níl aitheantas tugtha duit`, `Is féidir leat`,
	// `An bhfuil fonn ort`, `a theastaíonn uait`, `Fáilte romhat`,
	// `a sheoladh chugat`. The rest are absent from the corpus but are the same
	// closed paradigm, and cost nothing.
	/(?<!\p{L})(?:agat|duit|dhuit|leat|ort|uait|ionat|romhat|chugat|chughat|d[íi]ot|dh[íi]ot|asat|tharat|f[úu]t|umat|tr[íi]ot)(?!\p{L})/gu,
	// Synthetic 2sg conditional, a small closed list of the verbs this app uses.
	// The 2sg PRESENT needs no list — Irish forms it analytically (`cuireann
	// tú`), so the pronoun above already catches every instance.
	/(?<!\p{L})(?:chuirfe[áa]|dh[ée]anf[áa]|bheife[áa]|bhf[áa]ilteoir|d'fh[ée]adf[áa]|gheobhf[áa]|bhfaighfe[áa]|scriosf[áa]|sh[áa]bh[áa]lf[áa]|roghn[óo]f[áa]|gcinnfe[áa]|f[ée]adf[áa])(?!\p{L})/gu,
]

const CONTROLS = [
	// ---- must read INFORMAL (2sg). Every one is a real value from this bundle
	// or from core ga; this is the CORRECT register, so these are the "good
	// data" controls that must keep firing.
	['An bhfuil tú cinnte gur mhaith leat seanrianta cuardaigh a ghlanadh?', 'informal'],
	['An bhfuil tú cinnte gur mhaith leat scriosadh go buan', 'informal'],
	['An bhfuil tú ag iarraidh an iontráil rian iniúchta seo a scriosadh go buan?', 'informal'],
	['Caithfidh tú a bheith logáilte isteach chun amhairc a chur leis na ceanáin', 'informal'],
	['Teastaíonn gníomhaire AI uait chun comhrá a thosú.', 'informal'],
	['Roghnaigh stórlann a bhfuil rochtain scríofa agat air', 'informal'],
	['Léigh an iontráil sula gcinnfidh tú.', 'informal'],
	['Fan le freagra webhook sula leanfaidh tú ar aghaidh', 'informal'],
	['Níl cead agat rochtain a fháil ar an leathanach seo', 'informal'],
	['Is féidir leat an fhuinneog seo a dhúnadh.', 'informal'],
	['Níl aitheantas tugtha duit faoi láthair.', 'informal'],
	['An bhfuil fonn ort an stóras a scriosadh?', 'informal'],
	['Déan cur síos ar thasc a theastaíonn uait don chúntóir a dhéanamh', 'informal'],
	['Fáilte romhat ar bord', 'informal'],
	['Sheol muid pasfhocal chun {file} a rochtain chugat', 'informal'],
	['Cé na comhaid ar mhaith leat a choinneáil?', 'informal'],
	// the pronoun must still be found when it is not the first word
	['Nuair a thosaíonn tú ag clóscríobh', 'informal'],

	// ---- must read FORMAL (2pl) — the deviation. This bundle and core contain
	// NO 2pl address at all, so every one of these is constructed. They are
	// valid Irish, and each is the shape a translator would produce by importing
	// a Continental politeness plural.
	['An bhfuil sibh cinnte gur mhaith libh an réad seo a scriosadh?', 'formal'],
	['Caithfidh sibh a bheith logáilte isteach', 'formal'],
	['Teastaíonn gníomhaire AI uaibh chun comhrá a thosú.', 'formal'],
	['Níl cead agaibh rochtain a fháil ar an leathanach seo', 'formal'],
	['Is féidir libh an fhuinneog seo a dhúnadh.', 'formal'],
	['Bhur gcumraíocht chuardaigh', 'formal'],
	['Níl aitheantas tugtha daoibh faoi láthair.', 'formal'],
	['Fáilte romhaibh ar bord', 'formal'],
	['Seolfar an pasfhocal chugaibh', 'formal'],
	['An bhfuil fonn oraibh an stóras a scriosadh?', 'formal'],
	['Baineadh na cearta díbh', 'formal'],
	['Sábhálaigí na hathruithe sula bhfágfaidh sibh', 'formal'],
	['Roghnaígí clár agus scéimre', 'formal'],
	['Léigí an iontráil sula gcinnfidh sibh', 'formal'],
	['Cuirigí an comhad leis', 'formal'],
	['Úsáidigí na scagairí thíos', 'formal'],
	['Seiceálaigí an chuid staitisticí', 'formal'],

	// ---- must read NEITHER: the BARE 2SG IMPERATIVE is the label convention
	// (§7.3), so no button may score as either register. Every one is a real
	// value from this bundle.
	['Sábháil', 'neither'],
	['Scrios', 'neither'],
	['Cealaigh', 'neither'],
	['Cuir in Eagar', 'neither'],
	['Cruthaigh Clár', 'neither'],
	['Roghnaigh Clár agus Scéimre', 'neither'],
	['Scrios gach réad sa scéimre seo', 'neither'],
	['Glan gach scagaire', 'neither'],
	['Cumasaigh nó díchumasaigh an webhook seo', 'neither'],
	['Athnuaigh faisnéis an bhunachair sonraí', 'neither'],
	['Bain ó na ceanáin', 'neither'],
	['Fíoraigh an slabhra', 'neither'],
	['Amharc, easpórtáil, nó scrios rianta iniúchta', 'neither'],
	['Athchóirigh nó scrios míreanna go buan', 'neither'],
	['Léigh', 'neither'],
	['Bailíochtaigh Réada', 'neither'],
	['Scríobh iontráil rian iniúchta do gach céim', 'neither'],

	// ---- must read NEITHER: the imperative / VERBAL NOUN homograph. The second
	// word of each is spelled exactly like the imperative tested above, and all
	// are real values. This is why the whole paradigm is excluded.
	['Ag sábháil...', 'neither'],
	['Ag cóipeáil sonraí...', 'neither'],
	['Ag tástáil...', 'neither'],
	['Ag próiseáil...', 'neither'],
	['Clúdach séalaithe á léamh …', 'neither'],
	['Ag luchtú scagairí ardleibhéil...', 'neither'],

	// ---- must read NEITHER: the `do` trap. `do` is the 2sg possessive here in
	// some of these and the preposition in others, and no rule can tell them
	// apart, so none of them scores. `le do thoil` ("please") alone would
	// otherwise flood the informal count.
	['Roghnaigh comhad le do thoil.', 'neither'],
	['Déan teagmháil le do riarthóir le do thoil.', 'neither'],
	['Bain úsáid as na scagairí thíos chun do chuardach a bheachtú.', 'neither'],
	['Fág folamh chun gach amharc a phróiseáil bunaithe ar do chumraíocht.', 'neither'],
	["D'eochair API OpenAI. Faigh ceann ag", 'neither'],
	['Bainistigh do chláir sonraí agus a gcumraíochtaí', 'neither'],

	// ---- must read NEITHER: the `díobh` / `dóibh` third-person trap, one letter
	// from the 2pl `díbh` tested above. Both are real values.
	['tá 2FA cumasaithe do gach ball díobh seo', 'neither'],
	['Is féidir le sruth gach ceann díobh a shárú dó féin', 'neither'],
	['Tabhair rochtain dóibh ar do shonraí', 'neither'],

	// ---- must read NEITHER: the `-igí` noun-plural trap. Constructed, because
	// no such word occurs in the corpus — which is precisely why a suffix rule
	// would have looked safe.
	['Oifigí na heagraíochta', 'neither'],
	['Tá trí oifigí cláraithe', 'neither'],

	// ---- must read NEITHER: the autonomous/impersonal verb, which is what this
	// bundle uses for most descriptive prose. It addresses nobody, so it carries
	// no marker in either direction — the commonest shape in the file.
	['Scriosadh an rian iniúchta go rathúil', 'neither'],
	['Níor aimsíodh aon chláir', 'neither'],
	['Gintear smutáin téacs le linn eastóscadh comhaid', 'neither'],
	['Stórálfar sreabháin oibre sa tionscadal cumraithe.', 'neither'],

	// ---- mixed sanity: one 2pl marker inside otherwise correct prose wins,
	// because that is the slip the gate has to catch.
	['Roghnaigh clár, ach caithfidh sibh na hathruithe a shábháil', 'formal'],
]

// Register information this detector CANNOT see, and why. Recorded rather than
// left as failing controls.
const UNDETECTABLE = [
	['D\'eochair API Fireworks AI', 'the 2sg possessive `do`/`d\'` ("your"). Excluded '
		+ 'wholesale because `do` is also the preposition "to/for", the past-tense '
		+ 'verbal particle, and half of `le do thoil` ("please") — 527 of the 6431 '
		+ 'corpus values carry it, overwhelmingly not as a possessive. This is the '
		+ 'largest single recall loss in the detector, and it is unavoidable: unlike '
		+ 'the 2pl possessive `bhur`, which is unambiguous and IS matched, the '
		+ 'singular has no distinct spelling'],
	['Bainistigh do chláir sonraí', 'same word, in the possessive reading, inside a '
		+ 'real value. Note the polarity: the loss is on the CORRECT-register side, '
		+ 'so it depresses the informal count without ever creating a false formal '
		+ 'hit — the gate stays sound, only the verdict evidence is thinner than the '
		+ 'language actually provides'],
	['Sábháil na hathruithe', 'the bare 2sg imperative. Excluded on both §6.5 tests '
		+ 'at once: it is this locale\'s label convention, AND for the whole -áil '
		+ 'class it is spelled identically to the verbal noun, which is live in this '
		+ 'bundle\'s prose as the progressive (`Ag sábháil...`, `Ag cóipeáil...`). '
		+ 'Several stems are ordinary nouns besides (`Scrios` = destruction, `Dún` = '
		+ 'a fort)'],
	['Sábhálfá na hathruithe dá mbeadh cead agat', 'the synthetic 2sg conditional and '
		+ 'past (`-fá`, `-is`) are enumerated only for the handful of verbs this app '
		+ 'uses; both are dialectal or literary in UI prose, and the analytic forms '
		+ 'this bundle actually uses carry `tú` and are caught'],
	['Cuirtear an réad leis an gclár', 'the autonomous/impersonal verb addresses '
		+ 'nobody at all. Most of this bundle is written this way, so the majority of '
		+ 'values legitimately score zero in both directions. A low informal count is '
		+ 'therefore NOT evidence of a register problem here — the assertion that '
		+ 'matters is that the formal count is zero'],
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
		// Formal (2pl) wins ties here, the opposite of the formal-prose locales:
		// the deviation being gated on is the plural, so a value carrying both
		// must be reported as the defect rather than excused by the singular.
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
	const r = scanCoreRegister('ga', score)
	reportCoreRegister('ga', r, { formal: 'sibh, 2pl', informal: 'tú, 2sg' })
	// The verdict string is computed from formal-vs-informal totals, so for a
	// locale whose correct register is the singular it reads INFORMAL. Spell out
	// what that means so the number is not misread as a style finding.
	console.log('\nread this as: Irish has no T-V distinction. `informal` names the ONLY')
	console.log('address form available, and the load-bearing figure is the FORMAL count —')
	console.log(`it must be 0, and is ${r.formal}. Any 2pl address in a single-user UI is a defect.`)
}
