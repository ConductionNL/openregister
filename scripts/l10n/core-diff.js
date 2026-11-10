#!/usr/bin/env node
 
/**
 * Bundle vs Nextcloud core, key by key, for one locale.
 *
 *   node scripts/l10n/core-diff.js <loc> [--all] [--min-core=2]
 *
 * WHY THIS EXISTS, and why it is the FIRST thing to run in an audit (§6.9).
 *
 * The `sk` pass checked core only AFTER forming candidate corrections, and core
 * then overturned five of them — `Refresh`/`Restore` (core collapses them the
 * same way the bundle does), `First`/`Last`/`Previous` and bare `Search` (core
 * ships the bundle's exact wording). Each had looked like a textbook defect on
 * grep evidence alone. The cs pass lost four the same way.
 *
 * Forming a candidate and then killing it is the expensive order. This report
 * inverts it: read it before reading the bundle, and a value that AGREES with
 * core is one you never question, while the DISAGREE list is a ranked worklist
 * where the real terminology decisions live. On `sk` it would also have raised
 * the Delete/Zmazať question in the first minute rather than an hour in.
 *
 * A disagreement is NOT a defect. Core is evidence, not authority — §3.5 says
 * where the bundle and core differ on lexicon the bundle usually wins, and `hr`
 * deliberately keeps `lozinka` over core's `zaporka`. Read this as "these are
 * the places worth a decision", not as a fix list.
 *
 * Core often carries several renderings of one English key across its ~31
 * catalogues. All of them are printed with counts, because the split itself is
 * evidence: `Delete` → Zmazať(6)/Vymazať(3) in core sk says both are available
 * and neither is a slip, whereas a 1-vs-48 split usually means core has a typo.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const { loadJsTranslations, APP_ROOT, coreCatalogues } = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: core-diff.js <loc> [--all] [--min-core=2]')
	process.exit(2)
}
const showAll = process.argv.includes('--all')
const minArg = process.argv.find((a) => a.startsWith('--min-core='))
const minCore = minArg ? Number(minArg.slice('--min-core='.length)) : 1

// ---------------------------------------------------------------- core values

/**
 * Every rendering core gives each English key, with how many catalogues use it.
 *
 * @param {string} l Locale code.
 * @return {Map<string, Map<string, number>>} key -> (value -> catalogue count)
 */
function coreValues(l) {
	const out = new Map()
	for (const f of coreCatalogues(l)) {
		let json
		try {
			json = JSON.parse(fs.readFileSync(f, 'utf8'))
		} catch {
			continue
		}
		const t = json.translations
		if (!t || typeof t !== 'object') continue
		for (const [k, v] of Object.entries(t)) {
			// Core stores plurals as arrays too; only bare strings compare cleanly.
			if (typeof v !== 'string' || !v.trim()) continue
			if (!out.has(k)) out.set(k, new Map())
			const m = out.get(k)
			m.set(v, (m.get(v) || 0) + 1)
		}
	}
	return out
}

const core = coreValues(loc)
const { translations: bundle } = loadJsTranslations(path.join(APP_ROOT, 'l10n', `${loc}.js`))

// ------------------------------------------------------------------- classify

const agree = []
const disagree = []

for (const [key, value] of Object.entries(bundle)) {
	if (Array.isArray(value)) continue
	const cv = core.get(key)
	if (!cv) continue
	const total = [...cv.values()].reduce((a, b) => a + b, 0)
	if (total < minCore) continue
	const renderings = [...cv].sort((a, b) => b[1] - a[1])
	const row = { key, value, renderings, total }
	if (cv.has(value)) agree.push(row)
	else disagree.push(row)
}

const fmt = (r) => r.renderings.map(([v, n]) => `${JSON.stringify(v)}×${n}`).join('  ')

console.log(`core-diff ${loc}: ${core.size} English keys appear in core; `
	+ `${agree.length + disagree.length} of them are in this bundle`)
console.log(`  AGREE with core:    ${agree.length}   <- never question these`)
console.log(`  DISAGREE with core: ${disagree.length}   <- the worklist`)
console.log()
console.log('DISAGREE — bundle value first, then every core rendering with its catalogue count.')
console.log('A disagreement is evidence, not a verdict (§3.5): the bundle usually wins on lexicon.')
console.log()

for (const r of disagree.sort((a, b) => b.total - a.total || a.key.localeCompare(b.key))) {
	console.log(`  ${JSON.stringify(r.key)}`)
	console.log(`      bundle: ${JSON.stringify(r.value)}`)
	console.log(`      core:   ${fmt(r)}`)
}

if (showAll) {
	console.log()
	console.log('AGREE — the bundle already matches a core rendering. Do NOT "fix" these.')
	console.log()
	for (const r of agree.sort((a, b) => a.key.localeCompare(b.key))) {
		console.log(`  ${JSON.stringify(r.key).padEnd(42)} ${JSON.stringify(r.value)}`)
	}
}

console.log()
console.log(`core-diff ${loc}: read the DISAGREE list before reading the bundle. `
	+ 'This is a reading aid and never fails.')
