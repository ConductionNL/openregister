#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Terminology drift within one bundle: English words rendered two different ways.
 *
 *   node scripts/l10n/termdrift.js <loc> [--min-keys=4] [--max-minority=0.34] [--top=40]
 *
 * WHY THIS IS THE FIRST TOOL TO RUN. §6.9 says to count competing renderings per
 * English term before anything else, because it is the highest-yield check and
 * needs no knowledge of the language. It produced ~70 of `cs`'s 113 corrections
 * and 41 of `is`'s. On `sk` it was decisive: 40 of the 57 corrections were
 * terminology drift, and 37 of those were a SINGLE term (`audit trail`).
 *
 * But on all three passes the counting was done BY HAND, against a list of terms
 * someone guessed — `register`, `schema`, `file`, `audit`, `log`… That is the
 * weak link: the term that had actually drifted on `sk` was only found because
 * `audit` happened to be on the guessed list. This counts EVERY English content
 * word instead, so the guess is removed from the method.
 *
 * HOW. Index English content word -> the keys containing it. For each word in
 * enough keys, fold every target value to stems and cluster. Where one stem
 * dominates and a minority of keys carry none of the dominant cluster, that
 * minority is reported: the bundle is saying the same English thing two ways.
 *
 * WHAT IT DOES *NOT* CATCH, measured on `sk` rather than guessed:
 *   · Inflection-level splits. `Poradie fasiet` vs `Poradie fasety` share the
 *     stem `faset`, so a stem clusterer cannot see them.
 *   · Word-ORDER splits. `špecifikáciu API` vs `API špecifikáciu` have identical
 *     stems. Both of those `sk` corrections needed reading.
 * Measured recall on `sk` is 37 of 57 (65%); the remaining 20 need the regex
 * sweeps, the spell pass and reading. This is a worklist, not a gate.
 *
 * WHICH SIDE OF A SPLIT IS RIGHT IS NOT THIS TOOL'S CALL, and the majority is
 * not automatically correct. On `sk` the single minority rendering of
 * `audit trail` was the one the owner chose, and it became the convention for
 * the other 36 keys. Check core and the call site (§6.9) before picking a side.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const path = require('path')
const { loadJsTranslations, APP_ROOT } = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: termdrift.js <loc> [--min-keys=4] [--max-minority=0.34] [--top=40]')
	process.exit(2)
}
const num = (flag, dflt) => {
	const a = process.argv.find((x) => x.startsWith(`--${flag}=`))
	return a ? Number(a.slice(flag.length + 3)) : dflt
}
const MIN_KEYS = num('min-keys', 4)
const MAX_MINORITY = num('max-minority', 0.34)
const TOP = num('top', 40)
const STEM = 5

// English function words carry no terminology and would swamp the index.
const STOP = new Set(('a an the this that these those and or but if then else not no of to in on '
	+ 'for with by from at as is are was were be been being do does did have has had will would '
	+ 'can could may might must shall should it its their there here you your we our they them '
	+ 'all any each every some more most other another new old first last next previous up down '
	+ 'out off over under again once only just also very too so than when while where which who '
	+ 'what how why whether into onto upon per via yet still now already about after before'
).split(' '))

const fold = (s) => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()

/** @param {string} s Target value. @return {Set<string>} Folded word stems. */
function stems(s) {
	const cleaned = String(s)
		.replace(/\{[^}]*\}/g, ' ')
		.replace(/`[^`]*`/g, ' ')
		.replace(/https?:\/\/\S+/g, ' ')
	const out = new Set()
	for (const w of cleaned.match(/\p{L}[\p{L}-]*/gu) || []) {
		const f = fold(w)
		if (f.length < 4) continue
		out.add(f.slice(0, STEM))
	}
	return out
}

const { translations } = loadJsTranslations(path.join(APP_ROOT, 'l10n', `${loc}.js`))

// --------------------------------------------------- English word -> keys index

const byWord = new Map()
for (const [key, value] of Object.entries(translations)) {
	if (Array.isArray(value) || !String(value).trim()) continue
	// Short keys only: in a 30-word sentence the head word cannot be isolated,
	// and every content word would claim the whole sentence's stems.
	const words = key.match(/\S+/g) || []
	if (words.length > 6) continue
	// A word that occurs ONLY inside a {placeholder} is substituted at runtime, so
	// the value is not expected to carry any rendering of it. Counting those made
	// "Converting {schema} to blob storage..." look like schema-drift, and that
	// class alone dominated the first run's output.
	const bare = key.replace(/\{[^}]*\}/g, ' ')
	const seen = new Set()
	for (const w of bare.match(/[A-Za-z][A-Za-z-]*/g) || []) {
		const lw = w.toLowerCase()
		if (lw.length < 3 || STOP.has(lw)) continue
		if (seen.has(lw)) continue
		seen.add(lw)
		if (!byWord.has(lw)) byWord.set(lw, [])
		byWord.get(lw).push(key)
	}
}

// ------------------------------------------------------------------- clustering

const findings = []
for (const [word, keys] of byWord) {
	if (keys.length < MIN_KEYS) continue

	const counts = new Map()
	const keyStems = new Map()
	for (const k of keys) {
		const s = stems(translations[k])
		keyStems.set(k, s)
		for (const g of s) counts.set(g, (counts.get(g) || 0) + 1)
	}

	// The dominant rendering must actually dominate, or there is no convention to
	// deviate from — a word whose top stem covers half the keys is just a common
	// word, not an established term.
	const ranked = [...counts].sort((a, b) => b[1] - a[1])
	if (!ranked.length) continue
	const [domStem, domCount] = ranked[0]
	if (domCount / keys.length < 0.6) continue
	if (domCount < 3) continue

	const missing = keys.filter((k) => !keyStems.get(k).has(domStem))
	if (!missing.length) continue
	if (missing.length / keys.length > MAX_MINORITY) continue

	findings.push({
		word,
		keys: keys.length,
		domStem,
		domCount,
		missing,
		// A big established majority broken by one key is the strongest claim.
		score: domCount * (1 - missing.length / keys.length),
	})
}

findings.sort((a, b) => b.score - a.score)

console.log(`termdrift ${loc}: ${findings.length} English word(s) rendered inconsistently`)
console.log(`(words in >=${MIN_KEYS} short keys; dominant stem must cover >=60%; `
	+ `minority <=${MAX_MINORITY * 100}%)`)
console.log()

for (const f of findings.slice(0, TOP)) {
	console.log(`  "${f.word}" — ${f.domCount}/${f.keys} keys use "${f.domStem}-", `
		+ `${f.missing.length} do not:`)
	for (const k of f.missing.slice(0, 6)) {
		console.log(`      ${JSON.stringify(k).padEnd(46)} ${JSON.stringify(translations[k])}`)
	}
	if (f.missing.length > 6) console.log(`      … +${f.missing.length - 6} more`)
	console.log()
}

if (findings.length > TOP) console.log(`… +${findings.length - TOP} more below the top ${TOP}`)
console.log(`termdrift ${loc}: the majority is NOT automatically right (§6.9). `
	+ 'A reading aid; never fails.')
