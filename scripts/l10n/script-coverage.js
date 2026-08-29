/* eslint-disable no-console */
/* eslint-disable n/no-process-exit */
/**
 * Script-coverage sweep for a non-Latin locale — bg, sr, mk, be.
 *
 * This is the replacement for the English-leftover word scan in
 * docs/l10n-workflow.md §5 step 8. That scan looks for English function words
 * ("the", "and", "with"), which works for a Latin-script target and is useless
 * for a Cyrillic one: an untranslated English value and a correct Bulgarian one
 * are both just words, and the interesting signal is the SCRIPT rather than the
 * vocabulary.
 *
 * Two questions, and neither can be answered mechanically:
 *
 *   1. Which values carry no target-script character at all? Those are
 *      candidates for untranslated English — but a legitimate cognate ("CSV",
 *      "PDF", "Deck") looks exactly the same, which is why this prints them
 *      rather than failing on them. Cross-check against the `cognates` block in
 *      locales/<loc>.json: every no-script value should be either recorded there
 *      or a normalisation of one ("Url" -> "URL").
 *   2. Which Latin-letter runs appear INSIDE otherwise-translated values? Almost
 *      all are placeholders ({count}, {error}), product names (Nextcloud,
 *      OpenAnonymiser), acronyms (HTTP, RBAC) or literal example values
 *      (localhost, config.json) — and each has to be eyeballed once to confirm
 *      it is one of those and not a fragment nobody translated.
 *
 * So the exit code is 0 whatever it finds. It is a reading aid, not a gate; the
 * gates are selfcheck.js and check-l10n-parity.js.
 *
 * Usage: node scripts/l10n/script-coverage.js <loc>
 */

const fs = require('fs')
const path = require('path')
const { loadJsTranslations, loadLocaleConfig } = require('./lib.js')

const APP_ROOT = path.resolve(__dirname, '..', '..')

// Per-locale expected script. Keyed rather than inferred so an unlisted locale
// errors instead of being silently checked against the wrong alphabet — sr also
// ships in Latin in the wild, so "Cyrillic" is a claim about THIS bundle that
// has to be made deliberately. Verify with the locale's own pre-existing values
// before adding a row.
const SCRIPTS = {
	bg: 'Cyrillic',
	mk: 'Cyrillic',
	be: 'Cyrillic',
	// sr: measured, not assumed — 945 of this bundle's 1055 pre-existing values are
	// Cyrillic-only and the Latin-only ones were two defects. See locales/sr.json.
	sr: 'Cyrillic',
	el: 'Greek',
	uk: 'Cyrillic',
	ru: 'Cyrillic',
	// A LATIN row buys only the foreign-script check below, not the two lists,
	// which are meaningless for a Latin target. Add one per locale as it is
	// audited rather than defaulting unlisted locales to Latin — an unlisted
	// locale must keep erroring, because a silent wrong-alphabet check is the
	// failure this map exists to prevent.
	bs: 'Latin',
}

const loc = process.argv[2]
if (!loc) {
	console.error('usage: node scripts/l10n/script-coverage.js <loc>')
	process.exit(2)
}
const script = SCRIPTS[loc]
if (!script) {
	console.error(`no expected script recorded for "${loc}" — add it to SCRIPTS in this file, `
		+ 'after checking which alphabet that bundle actually uses')
	process.exit(2)
}

const file = path.join(APP_ROOT, 'l10n', `${loc}.js`)
if (!fs.existsSync(file)) {
	console.error(`no such bundle: ${file}`)
	process.exit(2)
}

const { translations } = loadJsTranslations(file)
const inScript = new RegExp(`\\p{Script=${script}}`, 'u')
// Three or more Latin letters: two-letter runs are almost all placeholder or
// unit fragments and drown the useful hits.
const LATIN_RUN = /[A-Za-z][A-Za-z.'-]{2,}/g

// HOMOGLYPHS: a single WORD mixing two scripts.
//
// WHY THIS EXISTS, and why it runs for EVERY locale including Latin ones. A
// Cyrillic small o (U+043E) is visually identical to a Latin o, so one inside an
// otherwise-correct Latin word is invisible to every gate and to the eye: the
// value is not empty, not identical to English, not wrong-arity, and reads as
// finished work. It was found in `bs`, in "proširenо dohvatom". The two lists
// below cannot see it — they ask whether the EXPECTED script is PRESENT, and it
// is — so the homoglyph class needs a different question, and all 30
// Latin-script locales had no coverage of it whatever.
//
// The test is per unbroken LETTER RUN rather than per value or per word, and the
// difference is what keeps it usable. Tolerating Latin anywhere in a value would
// be wrong for a Cyrillic bundle, because the inverse defect — a stray Latin o
// inside a Cyrillic word — is just as invisible; flagging any Latin in a Cyrillic
// value would flag every placeholder and product name, which is the second
// list's business.
//
// A HYPHEN IS A MORPHEME BOUNDARY AND MUST BREAK THE RUN. Macedonian, Serbian,
// Bosnian and Albanian all attach case endings to a Latin-script acronym across a
// hyphen — `API-клуч`, `MIME-типови`, `VAPID-пар`, `webhook-a`, `UUID-je` — which
// is correct morphology, not a homoglyph. Testing whole words instead reported
// 105 hits on `mk` and 19 on `uk`, essentially all of them that construction. A
// script change WITHIN one unbroken run is the real signal, because no language
// changes alphabet in the middle of a morpheme.
const WORD = /\p{L}[\p{L}\p{M}]*/gu
const isCyr = /\p{Script=Cyrillic}/u
const isLat = /\p{Script=Latin}/u
const isGrk = /\p{Script=Greek}/u

/**
 * @param {string} w A single word.
 * @return {string[]|null} The scripts it mixes, or null if it is single-script.
 */
function mixedScripts(w) {
	const found = []
	if (isLat.test(w)) found.push('Latin')
	if (isCyr.test(w)) found.push('Cyrillic')
	if (isGrk.test(w)) found.push('Greek')
	return found.length > 1 ? found : null
}

// Runs this locale records as legitimately mixed-script (see homoglyphAllow in
// lib.js). Case-folded, because the same run recurs sentence-initially.
const allow = new Set(
	(loadLocaleConfig(loc).homoglyphAllow || []).flatMap((w) => [w, String(w).toLowerCase()]),
)
let allowed = 0

const noScript = []
const runs = new Map()
const foreign = []
for (const [key, value] of Object.entries(translations)) {
	for (const s of Array.isArray(value) ? value : [value]) {
		if (typeof s !== 'string') continue
		if (!inScript.test(s)) noScript.push([key, s])
		for (const w of s.match(WORD) || []) {
			const mixes = mixedScripts(w)
			if (!mixes) continue
			if (allow.has(w) || allow.has(w.toLowerCase())) { allowed++; continue }
			// Name the minority characters: those are the homoglyphs, and printing
			// their code points is the only way to see which "o" is which.
			const majority = mixes.includes(script) ? script : mixes[0]
			const odd = [...new Set([...w].filter((c) => /\p{L}/u.test(c)
				&& !new RegExp(`\\p{Script=${majority}}`, 'u').test(c)))]
				.map((c) => `${c} U+${c.codePointAt(0).toString(16).toUpperCase()}`)
			foreign.push([key, w, `${mixes.join(' + ')} — odd char(s): ${odd.join(', ')}`])
		}
		for (const m of s.match(LATIN_RUN) || []) {
			if (!runs.has(m)) runs.set(m, [])
			runs.get(m).push(key)
		}
	}
}

console.log(`${loc}: ${Object.keys(translations).length} keys, expected script ${script}\n`)

// First, because it is the only section here that reports something that is
// always a defect rather than something needing a human read.
console.log(`=== HOMOGLYPHS — single words mixing two scripts: ${foreign.length}`)
console.log('    a Cyrillic o inside a Latin word, or a Latin o inside a Cyrillic one, is')
console.log('    invisible to every gate and to the eye. Real text does not change alphabet')
console.log('    mid-word, so any hit here is a defect.')
for (const [key, w, why] of foreign) {
	console.log(`  ${JSON.stringify(w)}   ${why}`)
	console.log(`      in ${JSON.stringify(key)}`)
}
if (!foreign.length) console.log('  (none)')
if (allowed) {
	console.log(`    (${allowed} occurrence(s) suppressed by homoglyphAllow in locales/${loc}.json`)
	console.log('     — a run belongs there only if this locale\'s own core catalogues use it too)')
}

if (script === 'Latin') {
	console.log('\nExpected script is Latin, so the two lists below are skipped: "values with no')
	console.log('Latin character" and "Latin runs inside values" carry no signal for a Latin')
	console.log('target. Use the English-leftover word scan of §5 step 8 instead.')
	console.log('\nThis script never fails a build.')
	process.exit(0)
}

console.log(`\n=== values with NO ${script} character at all: ${noScript.length}`)
console.log('    each of these must be a recorded cognate or a normalisation — check locales/'
	+ `${loc}.json`)
for (const [key, s] of noScript) console.log(`  ${JSON.stringify(key)}  ->  ${JSON.stringify(s)}`)

console.log(`\n=== distinct Latin runs inside translated values: ${runs.size}`)
console.log('    expect placeholders, product names, acronyms and literal example values only')
for (const [m, keys] of [...runs].sort((a, b) => b[1].length - a[1].length || a[0].localeCompare(b[0]))) {
	console.log(`  ${String(keys.length).padStart(3)}  ${m}`)
}

console.log('\nBoth lists are for reading. This script never fails a build.')
