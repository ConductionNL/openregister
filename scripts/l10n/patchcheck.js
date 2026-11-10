#!/usr/bin/env node
 
/**
 * Run a locale's register detector over a patch's VALUES before it is applied, so
 * a register slip is caught while it is still cheap to fix rather than after 200
 * keys have landed.
 *
 *   node scripts/l10n/patchcheck.js <loc> <patch.json>
 *
 * The gate is on the DEVIATION from the locale's measured register, which is why
 * `locales/<loc>.json` records `"register"`. For a formal locale the deviation is
 * an informal marker; for an informal locale it is a formal one. Getting that
 * polarity backwards makes the check pass on exactly the strings it should catch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const { loadDetector, loadLocaleConfig } = require('./lib.js')

const [loc, patchFile] = process.argv.slice(2)
if (!loc || !patchFile) {
	console.error('usage: patchcheck.js <loc> <patch.json>')
	process.exit(2)
}

const detector = loadDetector(loc)
if (!detector) {
	console.error(`no detector at scripts/l10n/detectors/${loc}.js — build one before translating.`)
	console.error('See docs/l10n-ui-translation.md: closed word lists, never suffix patterns,')
	console.error('validated on must-fire / must-not-fire controls including the homograph traps.')
	process.exit(2)
}
const cfg = loadLocaleConfig(loc)
if (!cfg.register) {
	console.error(`locales/${loc}.json does not record a measured register — measure it against core first.`)
	process.exit(2)
}

const patch = JSON.parse(fs.readFileSync(path.resolve(patchFile), 'utf8'))
const deviation = cfg.register === 'formal' ? 'informal' : 'formal'

let formal = 0
let informal = 0
const hits = []
for (const [k, v] of Object.entries(patch)) {
	for (const x of [].concat(v)) {
		const s = detector.score(x)
		formal += s.f
		informal += s.i
		const bad = deviation === 'informal' ? s.i > 0 : s.f > 0
		if (bad) {
			hits.push([k, x])
		}
	}
}

// The controls guard the detector itself. A detector whose own must-fire /
// must-not-fire cases fail cannot be trusted to judge a patch.
const ctl = detector.runControls()
console.log(`detector controls: ${ctl.total - ctl.fail}/${ctl.total}`)
if (ctl.fail) {
	console.error('detector controls FAIL — fix the detector before trusting this patch')
	process.exit(1)
}

console.log(`patch values: ${Object.keys(patch).length} keys`)
console.log(`  formal markers:   ${formal}`)
console.log(`  informal markers: ${informal}`)
console.log(`  measured register: ${cfg.register}  ->  gating on ${deviation} markers`)

if (hits.length) {
	console.error(`  ${deviation.toUpperCase()} HITS — must be zero for ${loc}:`)
	for (const [k, x] of hits) {
		console.error(`    ${JSON.stringify(k)}\n      -> ${JSON.stringify(x)}`)
	}
	process.exit(1)
}
console.log(`  OK — no ${deviation} marker in any value`)
