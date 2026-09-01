 
// Catalan register detector for openregister l10n.
//
// Catalan software (Softcatalà style) splits by string ROLE, not by one global
// register, so a single formal/informal verdict would be meaningless here:
//   • short commands / button labels -> 2sg imperative ("Desa", "Cancel·la")
//   • prose addressed to the user    -> vós, 2pl ("Seleccioneu", "Introduïu")
// The detector therefore measures the PROSE register only, via possessives and
// pronouns plus a closed list of instruction verbs in both forms.
//
// Why closed lists and not suffix patterns:
//   • "-eu" is not a reliable vós marker: "correu" (mail), "museu", "europeu",
//     "recreu" all end in -eu and are nouns/adjectives.
//   • the 2sg imperative is a homograph of the 3sg present indicative for most
//     verbs — "desa" is both "save!" and "he/she saves", "canvia" is both
//     "change!" and "changes", "activa" is also the feminine adjective "active".
//     So a bare 2sg imperative cannot be counted as an informal marker at all.
// What IS unambiguous is the possessive/pronoun choice, so that carries the
// verdict and the verb lists only corroborate it.

/**
 *
 * @param s
 */
function fold(s) {
	return String(s).toLowerCase()
}

// vós / formal prose
const FORMAL_RES = [
	// possessives — unambiguous
	/(?<!\p{L})(?:vostre|vostra|vostres)(?!\p{L})/gu,
	/(?<!\p{L})(?:vós)(?!\p{L})/gu,
	// closed list of vós imperatives common in UI prose. Each is a real verb form
	// and none is a noun in Catalan.
	/(?<!\p{L})(?:introduïu|seleccioneu|escriviu|trieu|cliqueu|premeu|comproveu|torneu|afegiu|suprimiu|deseu|obriu|tanqueu|utilitzeu|contacteu|espereu|assegureu|poseu|canvieu|activeu|desactiveu|reviseu|consulteu|indiqueu|ompliu|reintenteu|useu|editeu|creeu|cerqueu|configureu|instal·leu|habiliteu|inhabiliteu|executeu|reinicieu|copieu|baixeu|pugeu|envieu|verifiqueu|resoleu|definiu|establiu)(?!\p{L})/gu,
]

// tu / informal prose — the DEVIATION this gate looks for
const INFORMAL_RES = [
	// possessives — unambiguous
	/(?<!\p{L})(?:teu|teva|teus|teves)(?!\p{L})/gu,
	/(?<!\p{L})(?:tu)(?!\p{L})/gu,
	// Only verb forms with NO 3sg-indicative homograph and no nominal reading.
	// "introdueix", "suprimeix", "afegeix" etc. are 3sg indicative too, so they are
	// deliberately EXCLUDED — see the header. What remains are forms that only
	// exist as 2sg imperative or 2sg present.
	/(?<!\p{L})(?:pots|has de|hauries|vulguis|facis|tinguis|puguis|vols|saps)(?!\p{L})/gu,
]

const CONTROLS = [
	// must read formal (vós prose)
	['Introduïu la contrasenya', 'formal'],
	['Seleccioneu un registre', 'formal'],
	['Comproveu la vostra configuració', 'formal'],
	['Consulteu la documentació', 'formal'],
	['Aquesta acció afecta el vostre compte', 'formal'],
	// must read informal (tu prose) — the deviation
	['Introdueix la teva contrasenya', 'informal'],
	['Pots canviar-ho més tard', 'informal'],
	['Si vols, torna-ho a provar', 'informal'],
	['El teu compte', 'informal'],
	// must read neither: button labels in 2sg imperative are CORRECT Catalan UI
	// style, not an informal-register defect. If these fired, the gate would flag
	// every button in the app.
	['Desa', 'neither'],
	['Cancel·la', 'neither'],
	['Suprimeix', 'neither'],
	['Afegeix', 'neither'],
	['Tanca', 'neither'],
	['Crea un registre', 'neither'],
	['Canvia el nom', 'neither'],
	// must read neither: nouns that end in -eu, the suffix trap
	['Adreça de correu', 'neither'],
	['Correu electrònic', 'neither'],
	['Nom de l\'esquema', 'neither'],
	['Objecte suprimit correctament', 'neither'],
	['Qualitat de les dades', 'neither'],
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

module.exports = { score, fold, runControls, CONTROLS }

if (require.main === module) {
	const { fail, total } = runControls()
	console.log(`controls: ${total - fail}/${total} pass`)
	if (fail) process.exitCode = 1

	const { scanCoreRegister, reportCoreRegister } = require('../lib.js')
	reportCoreRegister('ca', scanCoreRegister('ca', score), { formal: 'vós', informal: 'tu' }, 15)
}
