#!/usr/bin/env node
 
/**
 * Spell-sweep one bundle against a hunspell dictionary.
 *
 *   node scripts/l10n/spell.js <loc> [--suggest] [--all]
 *
 * Needs scripts/l10n/dicts/<loc>.{aff,dic} — run fetch-dicts.js first.
 *
 * WHY. "Not a word in the language at all" was 14 of `is`'s 235 defects and typos
 * another 8 — 22 between them, the single largest mechanically-findable class in
 * that pass, and every one of them found by reading. `is` carried `Stav` (Slavic),
 * `skrivaðgang` (a Danish/Norwegian stem where Icelandic needs `skrifaðgang`),
 * `levranir` and `Misheppnaðst`. On `sk` this class was one key — `strategie`, the
 * Czech spelling of `stratégie` — which this tool finds and offers the right
 * correction for. Wrong-language contamination inside a committed bundle is the
 * thing it is really for (§6.9), and neighbouring-language stems are exactly what
 * a dictionary catches and a reader's eye slides over.
 *
 * WHAT IT WILL NOT DO. It will not adjudicate derived or technical vocabulary.
 * Measured on `sk`: the dictionary rejects BOTH `auditný` and `audítny`, so the
 * 35-key adjective misspelling that dominated that pass is invisible here — that
 * argument had to be made from Slovak morphology and the bundle's own forms.
 *
 * NOISE, and why the allowlist is per locale. A raw sweep of `sk` flags 199 of
 * 2593 distinct words. Almost all are (a) English identifiers and code samples,
 * (b) product names, or (c) real domain vocabulary no general dictionary carries
 * (`webhook`, `token`, `vektorizácia`, `faseta`, `úsek`, `nástenka`). (a) is
 * stripped mechanically below; (b) and (c) go in `spellAllow` in
 * scripts/l10n/locales/<loc>.json, ONCE, and then the report stays short for
 * every later pass. Build that list on the first run and the tool becomes cheap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')
const { loadJsTranslations, APP_ROOT, loadLocaleConfig } = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: spell.js <loc> [--suggest] [--all]')
	process.exit(2)
}
const suggest = process.argv.includes('--suggest')
const showAll = process.argv.includes('--all')

const dict = path.join(APP_ROOT, 'scripts', 'l10n', 'dicts', loc)
if (!fs.existsSync(`${dict}.dic`) || !fs.existsSync(`${dict}.aff`)) {
	console.error(`spell ${loc}: no dictionary at scripts/l10n/dicts/${loc}.{aff,dic}`)
	console.error(`run: node scripts/l10n/fetch-dicts.js ${loc}`)
	console.error('(fi ga lb mk mt rm have no hunspell dictionary published — see fetch-dicts.js)')
	process.exit(3)
}

const { translations } = loadJsTranslations(path.join(APP_ROOT, 'l10n', `${loc}.js`))
const cfg = loadLocaleConfig(loc)
const allow = new Set((cfg.spellAllow || []).map((w) => w.toLowerCase()))

// ------------------------------------------------------------------ tokenising

/**
 * Strip everything that is not prose in the target language: placeholders, code
 * spans, URLs, file paths, and bare ALLCAPS initialisms.
 *
 * @param {string} s Value.
 * @return {string} Prose only.
 */
function prose(s) {
	return String(s)
		.replace(/\{[^}]*\}/g, ' ')
		.replace(/`[^`]*`/g, ' ')
		.replace(/https?:\/\/\S+/g, ' ')
		.replace(/\b[\w.-]+\.(json|js|vue|ods|xlsx|php|md|ya?ml)\b/gi, ' ')
		// snake_case identifiers. The classes either side of the underscore are
		// DISJOINT on purpose: the previous `[a-z_]+(?:_[a-z_]+)+` let both
		// halves consume underscores, so a run of them ("a__________b") gave the
		// engine an exponential number of ways to split the same text — CodeQL
		// js/redos. `_+` here still absorbs repeated underscores, so `foo__bar`
		// matches exactly as before, but there is only one way to match it.
		.replace(/\b[a-z0-9]+(?:_+[a-z0-9]+)+\b/gi, ' ')
		.replace(/\b[a-z]+[A-Z]\w*/g, ' ') // camelCase identifiers
		.replace(/(?<!\p{L})\p{Lu}{2,}(?!\p{Ll})/gu, ' ') // ALLCAPS initialisms
}

// U+00B7 MIDDLE DOT is word-INTERNAL in Catalan (`col·lecció`, `paral·lel`,
// `Cancel·la`), so it belongs in the token class rather than acting as a
// separator. Leaving it out split every geminate-l word in the `ca` bundle into
// two junk halves — `col`+`lecció`, `paral`+`lel`, `col`+`laboratives` — and put
// seven of them in the report as if they were misspellings. Catalan is the only
// locale here that uses it, which is exactly why it went unnoticed until `ca`.
const words = new Map() // word -> first key it appears in
for (const [key, value] of Object.entries(translations)) {
	for (const v of Array.isArray(value) ? value : [value]) {
		for (const w of prose(v).match(/\p{L}[\p{L}'’·-]*/gu) || []) {
			if (w.length < 3) continue
			if (allow.has(w.toLowerCase())) continue
			if (!words.has(w)) words.set(w, key)
		}
	}
}

// ------------------------------------------------------------------- hunspell

const list = [...words.keys()]
if (!list.length) {
	console.log(`spell ${loc}: nothing to check`)
	process.exit(0)
}

let unknown = []
try {
	const out = execFileSync('hunspell', ['-d', dict, '-l'], {
		input: list.join('\n'),
		encoding: 'utf8',
		maxBuffer: 32 * 1024 * 1024,
	})
	unknown = [...new Set(out.split('\n').filter(Boolean))]
} catch (e) {
	console.error(`spell ${loc}: hunspell failed — ${e.message}`)
	process.exit(3)
}

let sugg = new Map()
if (suggest && unknown.length) {
	try {
		const out = execFileSync('hunspell', ['-d', dict, '-a'], {
			input: unknown.join('\n'),
			encoding: 'utf8',
			maxBuffer: 32 * 1024 * 1024,
		})
		let i = 0
		for (const line of out.split('\n').slice(1)) {
			if (!line.trim()) continue
			const m = line.match(/^&\s+(\S+)\s+\d+\s+\d+:\s*(.*)$/)
			if (m) sugg.set(m[1], m[2].split(', ').slice(0, 4))
			else if (line.startsWith('#')) sugg.set(line.split(/\s+/)[1], [])
			i++
		}
		void i
	} catch { sugg = new Map() }
}

console.log(`spell ${loc}: ${list.length} distinct prose words, ${unknown.length} not in the `
	+ `dictionary (${allow.size} allowlisted)`)
console.log()
console.log('Read this as a worklist. A general dictionary does not carry this app\'s domain')
console.log('vocabulary; put the legitimate ones in "spellAllow" in locales/' + loc + '.json so')
console.log('the next pass sees a short report. What you are hunting is a NEIGHBOURING LANGUAGE\'S')
console.log('stem and an outright typo — that is what no reader reliably catches.')
console.log()

// EVERY unknown word is printed. An earlier version suppressed the ones hunspell
// had no suggestion for, which is exactly backwards: a word so mangled that the
// dictionary cannot even propose a neighbour is the MOST likely real defect —
// `is`'s `Misheppnaðst` and `levranir` are that shape. Suggestions are a bonus
// column, never a filter.
for (const w of unknown.sort((a, b) => a.localeCompare(b))) {
	const s = sugg.get(w)
	const tail = s && s.length ? `   -> ${s.join(', ')}` : ''
	// hunspell splits on hyphens and apostrophes, so `-l` can name a SUB-token of
	// a word this script sent, which is then absent from `words`. An earlier
	// version indexed that blindly and threw, killing the report after its header
	// — the crash looked exactly like "nothing found", which is the worst possible
	// failure for a reading aid. Never let a missing origin key be fatal.
	const where = words.has(w) ? JSON.stringify(words.get(w)).slice(0, 46) : '(sub-token)'
	console.log(`  ${w.padEnd(26)} ${where}${tail}`)
}
void showAll

console.log()
console.log(`spell ${loc}: a reading aid; never fails. Suggestions come from hunspell, not from`)
console.log('this tool — verify each against core and the call site before changing a value.')
