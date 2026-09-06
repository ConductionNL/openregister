 
// Romanian register detector for openregister l10n.
//
// Measures the PROSE register: dumneavoastră / vă (formal 2pl) against tu (informal
// 2sg). Romanian is the first locale in this project where CORE ITSELF IS MIXED, so
// read the verdict together with what the bundle already does — see locales/ro.json.
//
// THE BUTTON CONVENTION HERE IS A PROJECT DECISION, NOT A MEASUREMENT, and it is the
// one case in this app where the two diverge. Measured, core ro uses the bare 2sg
// imperative or a verbal noun for short labels — Salvează, Adaugă, Șterge, Editează,
// Alege, Copiază, Mută, Continuă, Confirmă, Aplică, Trimite, Instalează, Reîncearcă,
// Redenumește, Crează, alongside Setări / Căutare / Anulare / Actualizare /
// Restaurare / Activare, with exactly one 2pl label (Dezactivați). That is the
// ca/et/hr pattern, where button style does not follow the prose register.
//
// The project chose otherwise for Romanian, on a native speaker's advice that
// Romanian users expect formal address throughout a web UI: **formal 2pl everywhere**
// — prose, dropdown placeholders and buttons alike (Salvați, Adăugați, Ștergeți).
// See locales/ro.json.
//
// CONSEQUENCE FOR THIS DETECTOR: a bare 2sg imperative in *label position* is a
// DEVIATION for this bundle and is counted as informal, even though core ro uses it.
// Two things follow, and both are deliberate:
//
//   • Scanning core with this detector inflates core's informal count, because core's
//     own button labels now register as deviations. The register evidence in
//     locales/ro.json therefore records the PROSE-ONLY measurement taken before this
//     rule existed (124 formal / 66 informal), which is the number that decided the
//     register. Do not re-derive the prose verdict from a scan that includes this rule.
//   • Label position is approximated as "string-initial, and the whole string is at
//     most 40 characters". That is what keeps the 3sg homographs out: "Se șterge..."
//     is "is being deleted", "Aceasta va șterge" is "this will delete", "Sistemul
//     salvează modificările" is "the system saves". None of those begins with the
//     verb, and long descriptive sentences that do begin with a 3sg present fall
//     outside the length bound. See UNDETECTABLE for what this misses.
//
// Why closed word lists and NOT suffix patterns, specifically for Romanian:
//
//   • "-ați" / "-eți" is the 2pl ending AND the masculine plural of a large class of
//     adjectives and nouns: "curați" (clean), "bogați" (rich), "pereți" (walls),
//     "băieți" (boys). A suffix rule scores those as formal address.
//   • "-ești" is the 2sg ending of -i verbs ("folosești") and also ends noun plurals
//     like "povești" (stories).
//   • "-i" is the 2sg ending and simultaneously the masculine plural of nearly every
//     noun ("utilizatori", "parametri", "furnizori").
//
// Traps that have to be handled explicitly, all of them live in this app's data:
//
//   1. "vă" (formal you) vs "va" (3sg future auxiliary, "will"). Differing only by a
//      diacritic, and "va" is everywhere: "Acest proces va genera embeddings" is an
//      ordinary future tense, not formal address. Only "vă" is matched.
//   2. "ai" is EXCLUDED entirely. It is the 2sg of "avea" (you have), but also the
//      masculine plural possessive article ("ai tăi"), and — since matching is
//      case-insensitive — it collides with the acronym AI, which this bundle carries
//      in "Setări chat Fireworks AI" and "Configurație embedding Fireworks AI".
//   3. "vezi" is EXCLUDED: it is a 2sg indicative but reads as the imperative in link
//      labels ("vezi mai multe"), which is the button convention.
//   4. CEDILLA vs COMMA diacritics. Romanian ș/ț exist as two codepoint pairs:
//      ș U+0219 / ț U+021B (correct) and ş U+015F / ţ U+0163 (legacy Turkish
//      cedilla). Core ro mixes them — 362 values use the comma form and 5 legacy
//      strings use the cedilla ("contactaţi", "fişiere") — so fold() normalises them.
//      Without that, closed-list entries silently miss the legacy spellings.

/**
 *
 * @param s
 */
function fold(s) {
	// Normalise the legacy cedilla codepoints to the correct comma-below ones before
	// lowercasing, so a closed list written in modern orthography still matches core's
	// older strings. See trap 4 in the header.
	return String(s)
		.replace(/ş/g, 'ș').replace(/Ş/g, 'Ș')
		.replace(/ţ/g, 'ț').replace(/Ţ/g, 'Ț')
		.toLowerCase()
}

// dumneavoastră / formal 2pl — includes the formal imperative, which is the same form
// as the 2pl present and is equally formal.
const FORMAL_RES = [
	// "vă" only, never "va": see trap 1.
	/(?<!\p{L})(?:dumneavoastră|dumneavoastra|dvs|vă|vi)(?!\p{L})/gu,
	/(?<!\p{L})(?:puteți|doriți|sunteți|aveți|faceți|veți|ați|știți|vedeți|primiți|selectați|introduceți|configurați|alegeți|apăsați|încercați|reîncercați|verificați|salvați|ștergeți|adăugați|utilizați|folosiți|tastați|instalați|contactați|resetați|gestionați|încărcați|descărcați|lăsați|includeți|editați|creați|actualizați|confirmați|continuați|anulați|închideți|deschideți|copiați|trimiteți|redenumiți|restaurați|activați|dezactivați|așteptați|mergeți|citiți|scrieți|asigurați|rafinați|reîncărcați|logați|conectați)(?!\p{L})/gu,
]

// tu / informal 2sg — the DEVIATION this gate looks for.
const INFORMAL_RES = [
	// "ai" is deliberately absent — see trap 2.
	/(?<!\p{L})(?:tu|tău|ta|tale|tăi|ție|îți|ți|te|tine)(?!\p{L})/gu,
	// 2sg present indicative forms whose 3sg is spelled differently, so there is no
	// third-person reading, and which are not the imperative used on buttons.
	// "vezi" is excluded — see trap 3.
	/(?<!\p{L})(?:poți|ești|vrei|faci|știi|selectezi|introduci|dorești|primești|alegi|contactezi|reîncarci|apeși|salvezi|ștergi|adaugi|folosești|utilizezi|configurezi|verifici|încerci|creezi|actualizezi|trebuiești)(?!\p{L})/gu,
]

// A bare 2sg imperative in label position: the deviation from this bundle's chosen
// "formal 2pl everywhere" convention. Anchored to the start of the string and bounded
// by length, because these forms are homographs of the 3sg present — see the header.
const LABEL_IMPERATIVE_RE = /^(?:salvează|adaugă|șterge|editează|alege|copiază|continuă|confirmă|aplică|trimite|instalează|reîncearcă|redenumește|crează|creează|mută|anulează|închide|deschide|descarcă|încarcă|restaurează|activează|dezactivează|selectează|introdu|caută|publică|testează|exportă|importă|reîncarcă|configurează|gestionează|verifică|generează|rulează|pornește|oprește|elimină|revocă|conectează|sincronizează|resetează|curăță|golește|atribuie|aprobă|respinge)(?!\p{L})/u
const LABEL_MAX = 40

const CONTROLS = [
	// must read formal — all real core ro or app ro.js values
	['Introduceți emailul dumneavoastră pentru a solicita o parolă provizorie', 'formal'],
	['Clientul dumneavoastră ar trebui să fie conectat acum!', 'formal'],
	['Vă rugăm contactați administratorul.', 'formal'],
	['Alegeți un registru', 'formal'],
	['Alegeți o schemă', 'formal'],
	['Configurați parametrii pentru vectorizarea obiectelor.', 'formal'],
	['Gestionați registrele dumneavoastră de date și configurațiile acestora', 'formal'],
	['Sigur doriți să ștergeți definitiv', 'formal'],
	['Parola nu s-a putut schimba. Contactați administratorul.', 'formal'],
	['Puteți schimba asta mai târziu', 'formal'],
	// the legacy cedilla spelling must still read formal — trap 4
	['Vă rugăm să contactaţi administratorul dvs.', 'formal'],
	// must read informal — all real core ro values
	['Te rugăm să alegi un fișier.', 'informal'],
	['A apărut o eroare. Te rugăm să contactezi administratorul.', 'informal'],
	['Tokenul tău de autentificare este invalid sau a expirat', 'informal'],
	['Fișierele tale sunt criptate.', 'informal'],
	['Serverul nu a reușit să proceseze cererea ta.', 'informal'],
	['Conectează-te la contul tău', 'informal'],
	['Poți schimba asta mai târziu', 'informal'],
	['Dacă vrei, încearcă din nou', 'informal'],
	// must read INFORMAL: a bare 2sg imperative in label position deviates from this
	// bundle's chosen formal-2pl convention. These are core ro's own button labels and
	// the 11 values this bundle arrived with, all of which are corrected to 2pl.
	['Salvează', 'informal'],
	['Adaugă', 'informal'],
	['Șterge', 'informal'],
	['Editează', 'informal'],
	['Alege', 'informal'],
	['Copiază', 'informal'],
	['Continuă', 'informal'],
	['Confirmă', 'informal'],
	['Aplică', 'informal'],
	['Trimite', 'informal'],
	['Instalează', 'informal'],
	['Reîncearcă', 'informal'],
	['Redenumește', 'informal'],
	['Salvează oricum', 'informal'],
	['Adaugă aplicație', 'informal'],
	['Verifică lanțul', 'informal'],
	['Salvează setările fluxului', 'informal'],
	// the formal counterparts must read FORMAL, not merely "not informal"
	['Salvați', 'formal'],
	['Adăugați', 'formal'],
	['Ștergeți', 'formal'],
	['Salvați oricum', 'formal'],
	// verbal-noun labels, the other half of the convention
	['Setări', 'neither'],
	['Căutare', 'neither'],
	['Anulare', 'neither'],
	['Actualizare', 'neither'],
	['Restaurare', 'neither'],
	// must read NEITHER: trap 1 — "va" is the future auxiliary, not formal "vă"
	['Acest proces va genera embeddings vectoriale', 'neither'],
	['Aceasta va șterge TOATE intrările jurnalului', 'neither'],
	['Obiectul va fi șters definitiv', 'neither'],
	// must read NEITHER: trap 2 — the AI acronym must not read as 2sg "ai"
	['Setări chat Fireworks AI', 'neither'],
	['Configurație embedding Fireworks AI', 'neither'],
	['Folosește agenți AI pentru procesare', 'neither'],
	// must read NEITHER: 3sg present / reflexive homographs of the imperative. None of
	// these begins with the verb, which is what the label-position rule relies on.
	['Se șterge...', 'neither'],
	['Nu se poate șterge: obiectele sunt încă atașate', 'neither'],
	['Endpoint API personalizat dacă se utilizează o regiune diferită', 'neither'],
	['Sistemul salvează modificările', 'neither'],
	['Procesul șterge obiectele vechi în fiecare noapte fără confirmare', 'neither'],
	// must read NEITHER: the -ați/-eți adjective and noun plural trap
	['Pereți curați', 'neither'],
	['Utilizatori bogați în date', 'neither'],
	// must read NEITHER: ordinary third-person and impersonal prose
	['Obiect șters cu succes', 'neither'],
	['Calitatea datelor', 'neither'],
	['Nu s-au putut încărca setările', 'neither'],
	['Niciun obiect selectat', 'neither'],
	['Registrele și schemele nu au fost găsite', 'neither'],
]

// Informal styling this detector cannot see, and why.
const UNDETECTABLE = [
	['Ai obiecte noi', '"ai" is excluded — it collides with the possessive article ("ai tăi") and with the AI acronym under case folding, which this bundle carries in "Fireworks AI"'],
	['Vezi mai multe', '"vezi" is excluded because it reads as the imperative in link labels; the label-position rule does not list it for the same reason'],
	['Șterge automat obiectele expirate la sfârșitul perioadei', 'the reverse risk of the label-position rule: a description that genuinely BEGINS with a 3sg present. Only the 40-character bound keeps it out, so a short one would be a false positive'],
	['Copiază', 'correct as a 3sg present ("it copies") in a table cell, where the label-position rule cannot tell it from a button'],
]

/**
 *
 * @param s
 */
function score(s) {
	const t = fold(s)
	let f = 0
	let i = 0
	// Fresh regex per call: a reused /g/ carries lastIndex and silently turns later
	// matches into misses.
	for (const re of FORMAL_RES) f += (t.match(new RegExp(re.source, re.flags)) || []).length
	for (const re of INFORMAL_RES) i += (t.match(new RegExp(re.source, re.flags)) || []).length
	// The chosen convention makes a 2sg imperative button a deviation; see the header
	// for why this is bounded to label position rather than matched anywhere.
	if (t.length <= LABEL_MAX && LABEL_IMPERATIVE_RE.test(t)) i += 1
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
	reportCoreRegister('ro', scanCoreRegister('ro', score), { formal: 'dumneavoastră', informal: 'tu' })
}
