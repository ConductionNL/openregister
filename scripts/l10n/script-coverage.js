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
const { loadJsTranslations } = require('./lib.js')

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

const noScript = []
const runs = new Map()
for (const [key, value] of Object.entries(translations)) {
	for (const s of Array.isArray(value) ? value : [value]) {
		if (typeof s !== 'string') continue
		if (!inScript.test(s)) noScript.push([key, s])
		for (const m of s.match(LATIN_RUN) || []) {
			if (!runs.has(m)) runs.set(m, [])
			runs.get(m).push(key)
		}
	}
}

console.log(`${loc}: ${Object.keys(translations).length} keys, expected script ${script}\n`)

console.log(`=== values with NO ${script} character at all: ${noScript.length}`)
console.log('    each of these must be a recorded cognate or a normalisation — check locales/'
	+ `${loc}.json`)
for (const [key, s] of noScript) console.log(`  ${JSON.stringify(key)}  ->  ${JSON.stringify(s)}`)

console.log(`\n=== distinct Latin runs inside translated values: ${runs.size}`)
console.log('    expect placeholders, product names, acronyms and literal example values only')
for (const [m, keys] of [...runs].sort((a, b) => b[1].length - a[1].length || a[0].localeCompare(b[0]))) {
	console.log(`  ${String(keys.length).padStart(3)}  ${m}`)
}

console.log('\nBoth lists are for reading. This script never fails a build.')
