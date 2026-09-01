#!/usr/bin/env node
 
/**
 * Gated writer for one locale's l10n/<loc>.js.
 *
 *   node scripts/l10n/apply.js <loc> <patch.json> [--apply]
 *   node scripts/l10n/apply.js <loc> <patch.json> --apply --allow-replace='key1||key2'
 *
 * Default is a DRY RUN. Every gate below refuses the whole patch rather than
 * writing a partial one, so a bad batch never lands half-applied.
 *
 * ## The gates, and why each exists
 *
 *   1. not an en key         — a typo in the patch would otherwise add a key that
 *                              no source file reads and that check:l10n then
 *                              reports as unused forever.
 *   2. plural arity          — an array shorter than the form index the runtime
 *                              asks for renders BLANK. This is the only l10n
 *                              defect invisible to a human reading the file.
 *   3. value === key         — refused unless the key carries a written
 *                              justification in scripts/l10n/locales/<loc>.json.
 *                              Absent falls back to English and stays visibly
 *                              untranslated to tooling; identical renders the same
 *                              characters while being indistinguishable from
 *                              finished work, so nobody ever revisits it.
 *   4. empty / whitespace    — edge or doubled HORIZONTAL whitespace only. `\s\s`
 *                              would also match the `\n\n` paragraph breaks that
 *                              several multi-line confirm dialogs carry in the
 *                              English source too.
 *   5. `{plural}` in a value  — English morphology assembled from a placeholder.
 *                              Banned outright; use a real n() plural key. Ordered
 *                              ahead of drift so the message names the real fix.
 *                              See MORPHOLOGY_PLACEHOLDER in lib.js.
 *   6. placeholder drift     — both directions, with no exemptions. `{plural}`
 *                              used to be the one permitted loss; gate 5 now
 *                              refuses it instead.
 *   7. clobbering a real value — refused unless the key is named explicitly in
 *                              --allow-replace, which prints every old -> new pair
 *                              so a correction can never happen as a silent side
 *                              effect of a bulk apply.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const {
	loadJsTranslations, serializeJs, APP_ROOT, isIdentical, placeholders, npluralsOf,
	MORPHOLOGY_PLACEHOLDER, loadLocaleConfig,
} = require('./lib.js')

const [loc, patchFile] = process.argv.slice(2)
if (!loc || !patchFile) {
	console.error("usage: apply.js <loc> <patch.json> [--apply] [--allow-replace='k1||k2']")
	process.exit(2)
}
const write = process.argv.includes('--apply')
const allowArg = process.argv.find(a => a.startsWith('--allow-replace='))
const allowReplace = new Set(allowArg ? allowArg.slice('--allow-replace='.length).split('||') : [])

const en = loadJsTranslations(path.join(APP_ROOT, 'l10n', 'en.js')).translations
const locFile = path.join(APP_ROOT, 'l10n', `${loc}.js`)
const cur = loadJsTranslations(locFile)
const cfg = loadLocaleConfig(loc)
const NP = npluralsOf(cur.pluralForm)
const patch = JSON.parse(fs.readFileSync(patchFile, 'utf8'))

const next = { ...cur.translations }
const problems = []
const corrections = []
let written = 0
let replaced = 0
let cognatesWritten = 0

for (const [k, v] of Object.entries(patch)) {
	if (!(k in en)) {
		problems.push(`not an en key: ${JSON.stringify(k)}`)
		continue
	}

	// gate 2: arity
	if (Array.isArray(en[k])) {
		if (!Array.isArray(v)) {
			problems.push(`plural key needs an array of ${NP}: ${JSON.stringify(k)}`)
			continue
		}
		if (v.length !== NP) {
			problems.push(`arity ${v.length} != nplurals ${NP}: ${JSON.stringify(k)}`)
			continue
		}
	} else if (Array.isArray(v)) {
		problems.push(`non-plural key given an array: ${JSON.stringify(k)}`)
		continue
	}

	// gate 3: value === key only via the justified-cognate path
	if (isIdentical(k, v)) {
		const reason = cfg.cognates[k]
		if (!reason) {
			problems.push(`value===key with no recorded reason: ${JSON.stringify(k)} `
				+ `(add it to scripts/l10n/locales/${loc}.json under "cognates" with a justification, or translate it)`)
			continue
		}
		if (String(reason).trim().length < 15) {
			problems.push(`cognate reason too thin to be a justification: ${JSON.stringify(k)}`)
			continue
		}
		cognatesWritten++
	}

	// gate 4: empties and edge/doubled horizontal whitespace
	for (const x of Array.isArray(v) ? v : [v]) {
		if (!String(x).trim()) {
			problems.push(`empty value: ${JSON.stringify(k)}`)
		}
		if (/^\s|\s$|[ \t]{2,}/.test(String(x))) {
			problems.push(`edge/doubled whitespace: ${JSON.stringify(k)} ${JSON.stringify(x)}`)
		}
	}

	// gate 5: no English morphology assembled from a placeholder. This used to be
	// an EXEMPTION in gate 6 -- `{plural}` was the one placeholder a value was
	// allowed to drop, because the five source-hack keys could not be rendered any
	// other way. The source defect is gone (those keys are real n() calls now), so
	// the exemption is inverted into a ban: a value carrying `{plural}` can only
	// come from a hand-edit or a resurrected hack, and neither should land. See
	// MORPHOLOGY_PLACEHOLDER in lib.js for the four places this is enforced.
	//
	// Ordered BEFORE the drift check on purpose. Drift would catch the same value
	// anyway -- `{plural}` is a placeholder en.js no longer has -- but it would
	// report it as an anonymous "added placeholder", which does not tell the reader
	// that the fix is an n() call rather than a corrected brace.
	let banned = false
	for (const x of Array.isArray(v) ? v : [v]) {
		if (MORPHOLOGY_PLACEHOLDER.test(String(x))) {
			problems.push('{plural} is banned — use a real n() plural key: '
				+ `${JSON.stringify(k)} ${JSON.stringify(x)}`)
			banned = true
		}
	}
	if (banned) continue

	// gate 6: placeholders, both directions
	const enPh = new Set()
	for (const f of Array.isArray(en[k]) ? en[k] : [en[k]]) {
		for (const p of placeholders(f)) {
			enPh.add(p)
		}
	}
	const vPh = new Set()
	for (const f of Array.isArray(v) ? v : [v]) {
		for (const p of placeholders(f)) {
			vPh.add(p)
		}
	}
	const dropped = [...enPh].filter(p => !vPh.has(p))
	const added = [...vPh].filter(p => !enPh.has(p))
	if (dropped.length || added.length) {
		problems.push(`placeholder drift: ${JSON.stringify(k)} en=${[...enPh]} loc=${[...vPh]}`)
		continue
	}

	// gate 7: never clobber a real translation unsupervised
	if (k in next && !isIdentical(k, next[k])) {
		if (!allowReplace.has(k)) {
			problems.push(`would clobber real value: ${JSON.stringify(k)} existing=${JSON.stringify(next[k])}`)
			continue
		}
		corrections.push([k, next[k], v])
	}

	if (k in next) {
		replaced++
	} else {
		written++
	}
	next[k] = v
}

// A recorded cognate that no patch writes is dead weight, and worse: it is a
// standing permission slip for a key nobody is looking at any more.
for (const k of Object.keys(cfg.cognates)) {
	if (!(k in patch) && !(k in cur.translations)) {
		problems.push(`cognate recorded in locales/${loc}.json but absent from both the patch and the bundle: ${JSON.stringify(k)}`)
	}
}

if (problems.length) {
	console.error(`REFUSED — ${problems.length} problem(s), nothing written:`)
	for (const p of problems.slice(0, 50)) {
		console.error('  ' + p)
	}
	if (problems.length > 50) {
		console.error(`  … +${problems.length - 50} more`)
	}
	process.exit(1)
}

if (corrections.length) {
	console.log(`CORRECTIONS (gate 6 overridden for ${corrections.length} explicitly named key(s)):`)
	for (const [k, was, now] of corrections) {
		console.log(`  ${JSON.stringify(k)}\n      was: ${JSON.stringify(was)}\n      now: ${JSON.stringify(now)}`)
		if (!cfg.corrections[k]) {
			console.log('      NOTE: no reason recorded in '
				+ `scripts/l10n/locales/${loc}.json under "corrections" — add one before committing`)
		}
	}
}

const stillAbsent = Object.keys(en).filter(k => !(k in next)).length
console.log(`${loc}: ${written} new, ${replaced} replaced, ${cognatesWritten} recorded cognate(s), 0 problems`)
console.log(`${loc}: ${Object.keys(next).length} / en ${Object.keys(en).length} keys — ${stillAbsent} still absent`)

if (write) {
	fs.writeFileSync(locFile, serializeJs({ app: cur.app, translations: next, pluralForm: cur.pluralForm }))
	console.log('APPLIED')
} else {
	console.log('DRY RUN — pass --apply to write')
}
