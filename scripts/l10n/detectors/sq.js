/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
// Albanian (shqip) register detector for openregister l10n.
//
// CORE IS USABLE HERE. Nextcloud ships five sq catalogues in the scanned roots
// (lib, encryption, settings, twofactor_backupcodes, user_ldap) totalling 603
// values, so §5 step 2 runs as written and this detector's `main` measures
// against core the way hr/sl/sr do — no §6.4 fallback needed. The sibling
// frontends are scanned too, but as corroboration rather than as the evidence.
//
// VERDICT: FORMAL, decisively and with almost nothing on the other side. Across
// core plus the four sibling frontends (3980 values) the polite 2pl scores in the
// hundreds — bare `ju` 115, the `juaj`/`tuaj` possessive family 123, `ju lutem`
// 83, and the 2pl verb forms on top of that (`zgjidhni` 73, `fshini` 22,
// `provoni` 18, `menaxhoni` 21, `dëshironi` 18, `keni` 10, `jeni` 6 …) — against
// exactly TWO informal markers in the whole corpus:
//
//   • openconnector: "Nuk ke ende kredenciale të ndërmjetësuara." — 2sg `ke`
//   • core/encryption: "Vendos fjalëkalimin tënd" — 2sg possessive `tënd`
//
// Albanian's V-form is the 2pl `Ju` on the French/Russian model. Live and
// ordinary, not archaic (contrast is) and not merely available-but-unused
// (contrast mt). `ti` does not occur once in 3980 values.
//
// ============================================================================
// fold() LOWERCASES, unlike lb.js. Checked rather than assumed: Albanian
// capitalises the polite `Ju` by convention but the lowercase `ju` is the SAME
// 2pl pronoun and equally polite — "Nuk ju lejohet ta ndani %s", "ju lutemi,
// riprovoni" are both core, both formal. There is no `dir`/`Dir` split to
// preserve, so case-folding costs nothing and buys recall on sentence-initial
// forms.
//
// ============================================================================
// THE LEFT GUARD CARRIES A HYPHEN, AND THAT IS NEW IN THIS SET.
//
// Albanian inflects acronyms and foreign nouns by attaching the definite/case
// ending after a HYPHEN: `UUID-je`, `URL-je`, `Token-i`, `PHP-ja`, `DN-ja`,
// `email-it`, `php.ini`. So `(?<!\p{L})je` — the guard every other detector in
// this directory uses — matches the inflectional ending of `UUID-je` and scores
// two real core/launchpad values as informal 2sg "you are". Every guard below is
// therefore `(?<![\p{L}-])`. The right guard stays `(?![\p{L}])`: an ending is
// attached after the hyphen, never before it, so barring the marker on its right
// would only lose recall.
//
// ============================================================================
// WHY CLOSED WORD LISTS, SPECIFICALLY FOR ALBANIAN (§8.1):
//
//   • `-ni` IS the 2pl ending, present and imperative alike — and it is also the
//     definite singular of every masculine noun whose stem ends in `-n`. The
//     corpus carries 220 distinct `-ni` tokens, and the nouns among them are not
//     rare: `aplikacioni` 15, `pozicioni` 9, `versioni` 6, `tani` ("now") 6,
//     `dokumentacioni` 4, `informacioni` 4, `shablloni` 3, `ekrani` 2, and `ini`
//     3 from `php.ini`. A `-ni` rule would report ~45 ordinary nouns as
//     deferential address. This is the `sr` `-те` situation with a much larger
//     collision set.
//   • `-sh` is the 2sg subjunctive ending AND the ablative plural of every noun.
//     `klasa objektesh`, `prej problemesh`, `kopjeruajtjesh`, `rimarrjesh`,
//     `pultesh`, `veglash` are all real values. The closed subjunctive list below
//     is safe only because no member of it is also a noun form.
//   • THE ENTIRE `-oj` VERB CLASS IS UNDETECTABLE, and it is the largest class in
//     the language. Its 2sg and 3sg present are spelled identically: `krijon` is
//     "you create" AND "it creates", likewise `ruan`, `fshin`, `dëshiron`,
//     `zgjedh`, `filtron`. There is no `bg`-style conjugation split to rescue part
//     of it — the syncretism is the paradigm. Informal detection therefore rests
//     on the handful of irregulars (`ke`, `je`, `mundesh`), the 2sg possessives,
//     the 2sg imperfect, and a closed subjunctive list. Recall is genuinely low
//     and that is recorded in UNDETECTABLE rather than hidden.
//   • `do` IS EXCLUDED, and it is the single most important exclusion. `do` is
//     the 2sg AND 3sg present of `dua` ("want"), and it is also the FUTURE
//     PARTICLE — `do të` occurs 101 times in the corpus in ordinary
//     third-person prose ("Fjalëkalimi juaj do të skadojë nesër"). Counting bare
//     `do` would score 108 values informal and put the verdict in doubt. Same
//     shape as lb's `muss`, an order of magnitude more frequent.
//   • `tij` IS EXCLUDED. It looks like a 2sg possessive next to `tënd`/`tënde`
//     but it is the THIRD person "his/its" — 6 attested occurrences, every one of
//     them third-person ("të gjitha përgjigjet e tij", "pulti i tij"). `tyre`
//     ("their") is excluded for the same reason.
//   • THE 2SG IMPERATIVE IS EXCLUDED WHOLESALE, on §6.5 test 1. It is the label
//     convention (§7.3, measured 368:31 over values ≤24 characters), so counting
//     it would flag every button in the app — the ca/et/hr/sl/sr/ga/mt situation.
//     Test 2 comes out YES as well, though only for part of the verb system: the
//     bare imperative is a homograph of the 3sg MEDIOPASSIVE AORIST for the
//     consonant-stem verbs, and that reading is live in ordinary UI prose —
//     `{type} u fshi me sukses`, `Fjalëkalimi juaj u rivendos`, `Zgjedhësi i
//     skedarëve nuk u hap dot` are all real values spelled exactly like
//     imperatives. `Hap` is an ordinary noun besides ("step", 9 occurrences), and
//     `Ndaj` is the preposition "toward". So the exclusion is doubly forced, but
//     test 1 alone would have settled it.

// Lowercase + whitespace. Case is NOT load-bearing in Albanian address: see the
// header — `Ju` and `ju` are the same polite 2pl.
function fold(s) {
	return String(s).toLowerCase().replace(/\s+/g, ' ')
}

// Ju / juaj / -ni — the polite 2pl, and the correct register for this bundle.
const FORMAL_RES = [
	// The 2pl pronoun in every case form. `ju` has no second reading in Albanian,
	// so the bare pronoun is usable — the same useful positive as `Dir` in lb.
	// The hyphen in the left guard is what keeps `UUID-je`-style inflection out;
	// the apostrophe is deliberately NOT guarded, because `t'ju` ("to you") is a
	// real and formal contraction.
	/(?<![\p{L}-])(?:ju|juve|jush|jua)(?![\p{L}])/gu,
	// The 2pl possessive family. `tuaj` is 2pl throughout — the 2sg possessive is
	// `yt`/`jote`/`tënd` and is matched as informal below. Written out in full
	// rather than as `tuaj\p{L}*` so a new form cannot be absorbed silently.
	/(?<![\p{L}-])(?:juaj|juaji|juaja|juajat|juajin|juajve|juajit|tuaj|tuaja|tuajat|tuajin|tuajve|tuajit)(?![\p{L}])/gu,
	// Closed list of 2pl finite forms. Albanian spells the 2pl present indicative
	// and the 2pl imperative alike for almost every verb (`shkruani` is both "you
	// write" and "write!"), so one list covers both and there is nothing to gain
	// from separating them. NOT a `-ni` rule: see the header for the ~45 nouns
	// that would score. Every entry here is a verb form attested in the corpus.
	/(?<![\p{L}-])(?:keni|jeni|doni|dëshironi|mundeni|duheni|preferoni|zgjidhni|përzgjidhni|zgjedhni|fshini|provoni|riprovoni|menaxhoni|shikoni|shihni|kontrolloni|përdorni|vendosni|futni|filtroni|prisni|krijoni|shtoni|konfiguroni|kërkoni|shkruani|aktivizoni|çaktivizoni|tërhiqni|vectorizoni|vektorizoni|jepni|rijepni|lëreni|hiqeni|hiqni|kryeni|lidhuni|dilni|ndryshoni|rishikoni|verifikoni|ruani|lejoni|klikoni|ndërtoni|anashkaloni|instaloni|kopjoni|analizoni|rivendosni|gjeneroni|caktoni|sigurohuni|vini|përcaktoni|specifikoni|rregulloni|hapni|mbyllni|kontaktoni|eksportoni|importoni|përpunoni|testoni|modifikoni|ngarkoni|shkarkoni|botoni|publikoni|rendisni|pastroni|rifreskoni|zbatoni|apliko ni|riemërtoni|dërgoni|konfirmoni|vazhdoni|përditësoni|shpërndani|drejtoni|nisni|ndaloni|shënoni|zgjeroni|mbani|kërkojini|kushtojini|rishikojeni|postojeni|aktivizojeni|çaktivizojeni|fshijeni|ruajeni|zgjidhjeni)(?![\p{L}])/gu,
]

// ti / tënd / ke — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	// The 2sg pronoun in its case forms. `ti` occurs ZERO times in the 3980-value
	// corpus, which is most of why the verdict is not in doubt. Bare `ti` is
	// usable because Albanian has no demonstrative or copular reading to collide
	// with — do not port the "leave the bare pronoun unmatched" rule from cs/hr/sl.
	// `të` and `t'` are deliberately ABSENT: see UNDETECTABLE.
	/(?<![\p{L}-])(?:ti|tyj)(?![\p{L}])/gu,
	// Closed list of 2sg possessives. `tij` and `tyre` are deliberately ABSENT —
	// they are third person, and `tij` is attested 6 times as "his/its".
	/(?<![\p{L}-])(?:yt|jote|jotë|tënd|tënde|tënden|tëndit|tëndin|tëndve)(?![\p{L}])/gu,
	// Closed list of 2sg present indicative, restricted to the irregulars whose
	// 3sg is spelled differently: ke/ka, je/është, mundesh/mundet. The whole `-oj`
	// class is absent because its 2sg and 3sg are homographs — see the header.
	// `do` is absent for the same reason plus the future particle.
	/(?<![\p{L}-])(?:ke|je|mundesh|mundësh)(?![\p{L}])/gu,
	// 2sg imperfect and 2sg subjunctive. Both ARE distinct from the 3sg
	// (`ishe`/`ishte`, `kishe`/`kishte`, `të ruash`/`të ruajë`), so they recover
	// some of the recall the `-oj` present loses. A closed list, not a `-sh` rule:
	// `-sh` is also the ablative plural of every noun (`objektesh`, `veglash`).
	/(?<![\p{L}-])(?:ishe|kishe|doje|mundeshe|kesh|jesh|bësh|dish|shkruash|ruash|krijosh|fshish|shtosh|zgjedhësh|përdorësh|marrësh|zgjidhësh|filtrosh|konfigurosh|menaxhosh)(?![\p{L}])/gu,
	// The informal politeness formula. Free signal, and the mirror of `ju lutem`:
	// Albanian's "please" inflects for the ADDRESSEE, so unlike lb/is/ga it is
	// register-bearing. 0 occurrences against 83 of `ju lutem`.
	/(?<![\p{L}-])t[ëe] lutem(?![\p{L}])/gu,
]

const CONTROLS = [
	// ---- must read FORMAL. Every one is a real value from this app family.
	['Jeni i sigurt se doni të fshini gjurmët e zgjedhura të kërkimit? Ky veprim nuk mund të kthehet.', 'formal'],
	['Jeni i sigurt se doni të pastroni gjurmët e vjetra të kërkimit? Kjo do të fshijë hyrjet më të vjetra se 30 ditë.', 'formal'],
	['Jeni i sigurt se doni të fshini përgjithmonë', 'formal'],
	['Zgjidhni një regjistër', 'formal'],
	['Zgjidhni cilat pamje të përfshihen në procesin e vectorizimit. Lëreni bosh për të përpunuar të gjitha pamjet.', 'formal'],
	['Konfiguroni parametrat për vectorizimin e objekteve.', 'formal'],
	['Të dhënat e filtrit u ngarkuan automatikisht. Përdorni filtrat më poshtë për të rafinuar kërkimin tuaj.', 'formal'],
	['Ngarko filtrat e avancuar me të dhëna live nga indeksi juaj i kërkimit', 'formal'],
	['Menaxhoni dhe rivendosni artikujt e fshirë butë nga regjistrat tuaj', 'formal'],
	['Menaxhoni skemat e të dhënave tuaja dhe pronat e tyre', 'formal'],
	['Ju lutemi, hiqeni rregullimin open_basedir nga php.ini juaj ose hidhuni te PHP për 64-bit.', 'formal'],
	['Ju keni hyrë me sukses duke përdorur autentifikimin me dy faktorë ( %1$s )', 'formal'],
	['Nuk ju lejohet ta ndani %s me të tjerët', 'formal'],
	['Fjalëkalimi juaj është rivendosur nga administratori', 'formal'],
	['Kyç privat i pavlefshëm për aplikacionin e fshehtëzimeve. Ju lutemi, përditësoni fjalëkalimin tuaj.', 'formal'],
	['Nuk është konfiguruar asnjë fushë meta të dhënash. Kërkojini një administratori të shtojë një.', 'formal'],
	['Anashkaloni këtë hap për momentin — paketat demo mund të instalohen më vonë.', 'formal'],
	['Mund të specifikoni një emër procesi opsional për të treguar pse janë të kyçura.', 'formal'],
	['Tërhiqni grupet mes kolonave për të kontrolluar cilat grupe të Nextcloud përdor LaunchPad', 'formal'],
	['Kontrolloni cilat pamje objektesh duhet vectorizuar për të ulur kostot API.', 'formal'],
	['Lejoni aplikacionet të përdorin API Share', 'formal'],
	['**%1$s** tani është i juaji', 'formal'],
	// `t'ju` — the apostrophe is deliberately not guarded on the left
	["Krijoni shabllone pultesh që do t'u zbatohen përdoruesve bazuar në grupet e tyre.", 'formal'],
	['Aktivizimi i kësaj mundësie do t’ju lejojë të rifitoni hyrje te kartelat tuaja.', 'formal'],

	// ---- must read INFORMAL. The first two are the ONLY real informal values in
	// the whole 3980-value corpus; the rest are constructed but valid Albanian,
	// because there is nothing else to draw on.
	['Nuk ke ende kredenciale të ndërmjetësuara. Krijo një së pari në OpenRegister.', 'informal'],
	['Vendos fjalëkalimin tënd', 'informal'],
	['Ti nuk ke leje për këtë regjistër', 'informal'],
	['Skema jote është ruajtur', 'informal'],
	['Regjistri yt është bosh', 'informal'],
	['Objektet e tënde nuk u fshinë', 'informal'],
	['Je i identifikuar', 'informal'],
	['Nuk mundesh ta fshish këtë pamje', 'informal'],
	['Kishe një sesion të hapur', 'informal'],
	['Ishe i lidhur me këtë burim', 'informal'],
	['Nëse doje të ruaje ndryshimet, provo përsëri', 'informal'],
	['Duhet të kesh një agjent AI për të nisur një bisedë', 'informal'],
	['Mund të ruash vetëm pultet e tua', 'informal'],
	['Të lutem provo përsëri', 'informal'],
	// one informal marker inside otherwise formal prose wins
	['Jeni i sigurt, por ti nuk ke të drejta', 'informal'],

	// ---- must read NEITHER: the bare 2sg IMPERATIVE is the correct Albanian
	// label convention (§7.3), so no button may score as either register.
	['Ruaj', 'neither'],
	['Fshi', 'neither'],
	['Shto', 'neither'],
	['Anulo', 'neither'],
	['Krijo', 'neither'],
	['Eksporto', 'neither'],
	['Rifresko', 'neither'],
	['Ruaj ndryshimet', 'neither'],
	['Shto te të preferuarat', 'neither'],
	['Provo përsëri', 'neither'],
	['Kopjo URL-në', 'neither'],
	['Shiko Dokument API', 'neither'],

	// ---- must read NEITHER: `do` and `do të`. The single most important trap
	// here — 101 corpus occurrences of the future particle, every one of them
	// third-person. Counting bare `do` would invert the reading of the file.
	['Fjalëkalimi juaj do të skadojë nesër', 'formal'],
	['Hyrjet që do të fshihen:', 'neither'],
	['Ky tekst do të shfaqet në linkun publik të faqes së ngarkuar.', 'neither'],
	['Do të ridrejtoheni te faqja e përditësimeve brenda disa sekondash.', 'neither'],
	['Vektorët do të gjenerohen automatikisht kur objektet krijohen.', 'neither'],

	// ---- must read NEITHER: `tij` and `tyre` are THIRD person, not 2sg.
	['Fshirja e këtij komenti do të heqë gjithashtu të gjitha përgjigjet e tij.', 'neither'],
	['Kur një objekt botohet, boto automatikisht të gjitha bashkëngjitjet e tij', 'neither'],
	['Data e tij e botimit nuk do të ndryshojë', 'neither'],

	// ---- must read NEITHER: the 3sg MEDIOPASSIVE AORIST, spelled exactly like
	// the 2sg imperative. Real values, and the §6.5 test-2 evidence.
	['Gjurma e auditimit u fshi me sukses', 'neither'],
	['Pamja u fshi me sukses', 'neither'],
	['Zgjedhësi i skedarëve nuk u hap dot', 'neither'],
	['S’u vendos dot lidhje me Oracle', 'neither'],

	// ---- must read NEITHER: the `-ni` NOUN collision. Every one is a real value
	// and every one is an ordinary noun or adverb. A `-ni` suffix rule would read
	// all of them as deferential 2pl address.
	['Aplikacioni "%s" s’mund të instalohet, ngaqë s’lexohet dot kartela appinfo.', 'neither'],
	['Pozicioni i Etiketës', 'neither'],
	['Versioni i serverit kërkohet %s ose më lartë', 'neither'],
	['Shablloni im', 'neither'],
	['Informacioni bazë', 'neither'],
	['Atribut LDAP që përdoret për të prodhuar emër ekrani për përdoruesin.', 'neither'],
	['Përshtatja e këtij konfigurimi në php.ini', 'neither'],

	// ---- must read NEITHER: the `-sh` ABLATIVE PLURAL, which the 2sg
	// subjunctive list must not reach.
	['Klasat më të rëndomta objektesh për përdoruesit janë organizationalPerson', 'neither'],
	['Kontrolloni cilat lloje skedarësh të përfshihen', 'formal'],
	['Jepni një strehë opsionale kopjeruajtjesh.', 'formal'],
	['Ndoshta për shkak problemesh sintakse', 'neither'],

	// ---- must read NEITHER: the HYPHEN guard. Albanian attaches the definite
	// ending after a hyphen, so `UUID-je` ends in the 2sg copula `je` and
	// `Token-i` in `-i`. Both are real values.
	['Anashkalo zbullim UUID-je', 'neither'],
	['Drejtoni një ekran muri drejt një URL-je liste luajtjeje', 'formal'],
	['Token-i ka skaduar. Ju lutem ringarkoni faqen.', 'formal'],
	['Mungon ID-ja e pllakëzës', 'neither'],

	// ---- must read NEITHER: the \p{L} guards proper. `keni` contains `ke`,
	// `tani` contains `ti` under an ASCII \b, `jetë` opens with `je`.
	['Duhet të jetë një kopje identike e shërbyesit kryesor LDAP', 'neither'],
	['Kohëzgjatja e jetës së sesionit', 'neither'],
	['Duhet dhënë një fjalëkalim i vlefshëm', 'neither'],
	['Mekanizmi i shërbimit për ndarje %s duhet të sendërtojë ndërfaqen', 'neither'],
	['Ndryshim që prish përputhshmërinë', 'neither'],
	['Duke ngarkuar filtrat e avancuar...', 'neither'],
	['Yti', 'neither'],
	['Tija', 'neither'],
]

// Informal styling this detector cannot see, and why. Recorded rather than left
// as failing controls.
const UNDETECTABLE = [
	['Krijon një objekt të re', 'THE WHOLE `-oj` VERB CLASS, which is the largest '
		+ 'in Albanian. Its 2sg and 3sg present are spelled identically — `krijon` is '
		+ '"you create" and "it creates", and so are `ruan`, `fshin`, `filtron`, '
		+ '`dëshiron`, `zgjedh`. Unlike bg there is no conjugation split to rescue '
		+ 'part of it: the syncretism IS the paradigm. This is the largest single '
		+ 'blind spot of any detector in this directory, and it is why the informal '
		+ 'list rests on irregulars, possessives, the imperfect and the subjunctive'],
	['Ruaj ndryshimet e tua', 'the bare 2sg IMPERATIVE, excluded wholesale on §6.5 '
		+ 'test 1 — it is the label convention (§7.3, 368:31 over short values), so '
		+ 'counting it would flag every button. Test 2 also comes out yes for the '
		+ 'consonant stems, whose imperative is a homograph of the 3sg mediopassive '
		+ 'aorist (`u fshi`, `u vendos`, `u hap`), and `Hap`/`Ndaj` are an ordinary '
		+ 'noun and preposition besides — but test 1 alone would have settled it'],
	['A do të ruash?', 'bare `do`. It is the 2sg AND 3sg present of `dua` ("want") '
		+ 'and also the FUTURE PARTICLE, which occurs 101 times in the corpus in '
		+ 'ordinary third-person prose. Excluded, and the exclusion costs the '
		+ 'commonest informal verb in the language. Same shape as lb `muss`, an '
		+ 'order of magnitude more frequent'],
	['Të ftojmë të provosh', '`të` and the contraction `t\'`. `të` is at once the 2sg '
		+ 'accusative/dative clitic, the subjunctive particle, the linking article of '
		+ 'every adjective (`të mirë`), the plural definite article and half of `do '
		+ 'të`. It is one of the two commonest words in the corpus and carries no '
		+ 'address information on its own. Nothing recoverable here, not even by '
		+ 'bigram the way is rescues `þér hafið`'],
	['Vetëm pronari mund ta fshijë', 'a value with no pronoun and no 2nd-person '
		+ 'finite verb carries no address marker in either direction. Albanian UI '
		+ 'prose leans on the impersonal `mund të` and `duhet të`, both of which are '
		+ 'person-neutral, so this covers a large share of the bundle'],
	['të tua', 'the 2sg possessive forms `tu` and `tua`. Left out of the list '
		+ 'because two and three letters after a `të` is too little to guard: `tua` '
		+ 'also ends `pjestua`, `vazhdua` and the aorist of every `-uaj` verb. '
		+ '`yt`/`jote`/`tënd` carry the paradigm instead'],
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

	const fs = require('fs')
	const path = require('path')
	const { scanCoreRegister, reportCoreRegister, loadJsTranslations, APP_ROOT } = require('../lib.js')

	// Core is usable for sq (5 catalogues, 603 values), so this is the §5 step 2
	// measurement proper — not the §6.4 fallback.
	console.log('\n=== §5 step 2: Nextcloud core ===')
	reportCoreRegister('sq', scanCoreRegister('sq', score), { formal: 'Ju/juaj/-ni', informal: 'ti/tënd/ke' })

	// Corroboration only. The sibling frontends agree with core here, so unlike
	// lb/mt/rm this is not load-bearing evidence — it is a second opinion.
	const APPS = path.resolve(APP_ROOT, '..')
	console.log('\n=== corroboration: this app family\'s own frontend bundles ===')
	let F = 0
	let I = 0
	let V = 0
	const allHits = []
	for (const app of fs.readdirSync(APPS).sort()) {
		const file = path.join(APPS, app, 'l10n', 'sq.js')
		if (!fs.existsSync(file)) continue
		let tr
		try { tr = loadJsTranslations(file).translations } catch { continue }
		let formal = 0
		let informal = 0
		let values = 0
		for (const [k, v] of Object.entries(tr)) {
			for (const x of Array.isArray(v) ? v : [v]) {
				// Values byte-equal to their key are untranslated English and cannot
				// carry Albanian register; counting them would dilute both totals.
				if (typeof x !== 'string' || !x.trim() || (!Array.isArray(v) && x === k)) continue
				values++
				const s = score(x)
				formal += s.f
				informal += s.i
				if (s.i > 0) allHits.push([app, x.slice(0, 100)])
			}
		}
		console.log(`  ${app.padEnd(16)} ${String(values).padStart(5)} values  `
			+ `formal=${String(formal).padStart(4)}  informal=${String(informal).padStart(3)}`)
		F += formal
		I += informal
		V += values
	}
	console.log(`  ${'TOTAL'.padEnd(16)} ${String(V).padStart(5)} values  `
		+ `formal=${String(F).padStart(4)}  informal=${String(I).padStart(3)}`)
	console.log(`verdict: ${F > I * 3 ? 'FORMAL' : I > F * 3 ? 'INFORMAL' : 'MIXED — inspect'}`)
	for (const [app, h] of allHits) console.log(`  informal? [${app}] ${h}`)
}
